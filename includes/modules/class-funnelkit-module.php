<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FunnelKit Module — bidirectional sync between FunnelKit and Odoo.
 *
 * Syncs FunnelKit contacts to Odoo crm.lead (bidirectional) and
 * funnel steps to crm.stage (push-only), enabling CRM pipeline
 * progression tracking.
 *
 * FunnelKit stores contact data in custom DB tables:
 * - {prefix}bwf_contact — contact records
 * - {prefix}bwf_contact_meta — contact metadata
 *
 * Funnel steps are stored as the wffn_step custom post type.
 *
 * Requires the FunnelKit (FunnelKit Automations) plugin to be active.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
class FunnelKit_Module extends Module_Base {

	use FunnelKit_Hooks;

	protected const PLUGIN_MIN_VERSION  = '3.0';
	protected const PLUGIN_TESTED_UP_TO = '3.5';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'contact' => 'crm.lead',
		'step'    => 'crm.stage',
	];

	/**
	 * Default field mappings.
	 *
	 * Contact mapping uses handler->format_lead() for rich mapping,
	 * but these defaults enable base-class map_to_odoo() for simple fields.
	 * Step mapping is handled by handler->format_stage().
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'contact' => [
			'email'      => 'email_from',
			'first_name' => 'contact_name',
			'phone'      => 'phone',
		],
		'step'    => [
			'title'    => 'name',
			'sequence' => 'sequence',
		],
	];

	/**
	 * FunnelKit data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var FunnelKit_Handler
	 */
	private FunnelKit_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'funnelkit', 'FunnelKit', $client_provider, $entity_map, $settings );
		$this->handler = new FunnelKit_Handler( $this->logger );
	}

	/**
	 * Sync direction: bidirectional for contacts, push-only for steps.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Boot the module: register FunnelKit hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'WFFN_VERSION' ) ) {
			$this->logger->warning( __( 'FunnelKit module enabled but FunnelKit is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_contacts'] ) ) {
			add_action( 'bwfan_contact_created', $this->safe_callback( [ $this, 'on_contact_created' ] ), 10, 1 );
			add_action( 'bwfan_contact_updated', $this->safe_callback( [ $this, 'on_contact_updated' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_steps'] ) ) {
			add_action( 'save_post_wffn_step', $this->safe_callback( [ $this, 'on_step_saved' ] ), 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_contacts'    => true,
			'sync_steps'       => true,
			'pull_contacts'    => true,
			'odoo_pipeline_id' => 0,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_contacts'    => [
				'label'       => __( 'Sync contacts', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push FunnelKit contacts to Odoo as CRM leads.', 'wp4odoo' ),
			],
			'sync_steps'       => [
				'label'       => __( 'Sync steps', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push FunnelKit funnel steps to Odoo as CRM stages.', 'wp4odoo' ),
			],
			'pull_contacts'    => [
				'label'       => __( 'Pull contacts from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull CRM lead changes from Odoo back to FunnelKit contacts.', 'wp4odoo' ),
			],
			'odoo_pipeline_id' => [
				'label'       => __( 'Odoo pipeline ID', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'The Odoo CRM team (pipeline) ID to assign stages to.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for FunnelKit.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'WFFN_VERSION' ), 'FunnelKit' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WFFN_VERSION' ) ? WFFN_VERSION : '';
	}

	// ─── Data Loading ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'contact' => $this->handler->load_contact( $wp_id ),
			'step'    => $this->handler->load_step( $wp_id ),
			default   => [],
		};
	}

	// ─── Odoo Mapping ────────────────────────────────────────

	/**
	 * Transform WordPress data to Odoo field values.
	 *
	 * For contacts, uses handler->format_lead() for rich mapping including
	 * x_wp_source and stage_id resolution from the current step.
	 * For steps, uses handler->format_stage() with team_id from settings.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array Odoo-compatible field values.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'contact' === $entity_type ) {
			$odoo_values = $this->handler->format_lead( $wp_data );

			// Resolve current step → Odoo crm.stage ID.
			$step_id = $wp_data['current_step_id'] ?? 0;
			if ( $step_id > 0 ) {
				$stage_id = $this->handler->resolve_stage_from_step( $step_id, $this->entity_map );
				if ( $stage_id ) {
					$odoo_values['stage_id'] = $stage_id;
				}
			}

			// Combine first_name + last_name into contact_name.
			$first = $wp_data['first_name'] ?? '';
			$last  = $wp_data['last_name'] ?? '';
			$name  = trim( $first . ' ' . $last );
			if ( '' !== $name ) {
				$odoo_values['contact_name'] = $name;
			}

			/**
			 * Filter the mapped Odoo values before push.
			 *
			 * @since 3.2.0
			 *
			 * @param array  $odoo_values Mapped values.
			 * @param array  $wp_data     Original WP data.
			 * @param string $entity_type Entity type.
			 */
			return apply_filters( "wp4odoo_map_to_odoo_{$this->id}_{$entity_type}", $odoo_values, $wp_data, $entity_type );
		}

		if ( 'step' === $entity_type ) {
			$settings    = $this->get_settings();
			$team_id     = (int) ( $settings['odoo_pipeline_id'] ?? 0 );
			$odoo_values = $this->handler->format_stage( $wp_data, $team_id );

			/** This filter is documented above. */
			return apply_filters( "wp4odoo_map_to_odoo_{$this->id}_{$entity_type}", $odoo_values, $wp_data, $entity_type );
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	/**
	 * Transform Odoo data to WordPress-compatible format.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Odoo record data.
	 * @return array WordPress-compatible data.
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'contact' === $entity_type ) {
			return $this->handler->parse_contact_from_odoo( $odoo_data );
		}

		return parent::map_from_odoo( $entity_type, $odoo_data );
	}

	// ─── Data Saving ─────────────────────────────────────────

	/**
	 * Save data to WordPress.
	 *
	 * Steps are push-only and cannot be saved from Odoo.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'contact' === $entity_type ) {
			return $this->handler->save_contact( $data );
		}

		return 0;
	}

	// ─── Pull override ───────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Steps are push-only and cannot be pulled.
	 * Contacts respect the pull_contacts setting.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		// Steps are push-only: skip pull.
		if ( 'step' === $entity_type ) {
			return \WP4Odoo\Sync_Result::success( null );
		}

		// Check pull settings.
		$settings = $this->get_settings();

		if ( 'contact' === $entity_type && empty( $settings['pull_contacts'] ) ) {
			return \WP4Odoo\Sync_Result::success( null );
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	// ─── Deduplication ───────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Contacts dedup by email_from, steps dedup by name.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'contact' === $entity_type && ! empty( $odoo_values['email_from'] ) ) {
			return [ [ 'email_from', '=', $odoo_values['email_from'] ] ];
		}

		if ( 'step' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}
}
