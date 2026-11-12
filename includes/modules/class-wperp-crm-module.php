<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP ERP CRM Module — bidirectional sync for CRM contacts and activities.
 *
 * Syncs WP ERP CRM contacts (erp_peoples) as Odoo leads (crm.lead) and
 * CRM activities as Odoo activities (mail.activity).
 *
 * Leads are bidirectional (push + pull). Activities are push-only.
 *
 * Independent from the existing `wperp` module (HR). No exclusive group
 * conflict with the existing `crm` module either — `crm` maps WordPress
 * users, while `wperp_crm` maps WP ERP contacts (different source entities,
 * different entity map namespaces).
 *
 * WP ERP stores CRM data in custom database tables — the handler queries
 * them directly via $wpdb.
 *
 * Requires the WP ERP plugin with CRM component to be active.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class WPERP_CRM_Module extends Module_Base {

	use WPERP_CRM_Hooks;

	protected const PLUGIN_MIN_VERSION  = '1.6';
	protected const PLUGIN_TESTED_UP_TO = '1.14';

	/**
	 * Sync direction: bidirectional.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'lead'     => 'crm.lead',
		'activity' => 'mail.activity',
	];

	/**
	 * Default field mappings.
	 *
	 * Lead and activity data are pre-formatted by the handler (identity
	 * pass-through in map_to_odoo).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'lead'     => [
			'name'         => 'name',
			'contact_name' => 'contact_name',
			'email_from'   => 'email_from',
			'phone'        => 'phone',
			'mobile'       => 'mobile',
			'website'      => 'website',
			'street'       => 'street',
			'street2'      => 'street2',
			'city'         => 'city',
			'zip'          => 'zip',
			'description'  => 'description',
			'type'         => 'type',
		],
		'activity' => [
			'summary'       => 'summary',
			'note'          => 'note',
			'date_deadline' => 'date_deadline',
		],
	];

	/**
	 * WP ERP CRM data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WPERP_CRM_Handler
	 */
	private WPERP_CRM_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wperp_crm', 'WP ERP CRM', $client_provider, $entity_map, $settings );
		$this->handler = new WPERP_CRM_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WP ERP CRM hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'WPERP_VERSION' ) ) {
			$this->logger->warning( __( 'WP ERP CRM module enabled but WP ERP is not active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_leads'      => true,
			'pull_leads'      => true,
			'sync_activities' => true,
		];
	}

	/**
	 * Third-party tables accessed directly via $wpdb.
	 *
	 * @return array<int, string>
	 */
	protected function get_required_tables(): array {
		return [
			'erp_peoples',
			'erp_crm_customer_activities',
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_leads'      => [
				'label'       => __( 'Sync contacts as leads', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP ERP CRM contacts to Odoo as leads (crm.lead).', 'wp4odoo' ),
			],
			'pull_leads'      => [
				'label'       => __( 'Pull leads from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull lead changes from Odoo back to WP ERP CRM contacts.', 'wp4odoo' ),
			],
			'sync_activities' => [
				'label'       => __( 'Sync activities', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP ERP CRM activities to Odoo (mail.activity).', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Whether the third-party plugin(s) this module integrates with are active.
	 *
	 * Single source of truth for module activation, shared by the registry
	 * and the dependency check.
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		return defined( 'WPERP_VERSION' );
	}

	/**
	 * Get external dependency status for WP ERP.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'WP ERP' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WPERP_VERSION' ) ? WPERP_VERSION : '';
	}

	// ─── Deduplication ────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Leads dedup by email. Activities have no dedup.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'lead' === $entity_type && ! empty( $odoo_values['email_from'] ) ) {
			return [ [ 'email_from', '=', $odoo_values['email_from'] ] ];
		}

		return [];
	}

	// ─── Pull override ────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Checks pull settings per entity type before delegating to parent.
	 * Activities are push-only — pull is a no-op.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'activity' === $entity_type ) {
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'lead' === $entity_type ) {
			$settings = $this->get_settings();
			if ( empty( $settings['pull_leads'] ) ) {
				return \WP4Odoo\Sync_Result::success();
			}
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'lead' === $entity_type ) {
			return $this->handler->parse_lead_from_odoo( $odoo_data );
		}

		return parent::map_from_odoo( $entity_type, $odoo_data );
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'lead' === $entity_type ) {
			return $this->handler->save_lead( $data, $wp_id );
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'lead' === $entity_type ) {
			return $this->handler->delete_lead( $wp_id );
		}

		return false;
	}

	// ─── Push override ────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Ensures the linked lead is synced before pushing an activity.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'activity' === $entity_type && 'delete' !== $action ) {
			$contact_id = $this->handler->get_contact_id_for_activity( $wp_id );
			$this->ensure_entity_synced( 'lead', $contact_id );
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Lead and activity data are pre-formatted by the handler (identity
	 * pass-through). Activity gets additional enrichment for Odoo-specific
	 * resolution fields.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'lead' === $entity_type ) {
			return $wp_data;
		}

		if ( 'activity' === $entity_type ) {
			// Resolve activity_type_id from the human-readable type name.
			$type_name = $wp_data['activity_type_name'] ?? '';
			unset( $wp_data['activity_type_name'] );

			$type_id = $this->resolve_activity_type_id( $type_name );
			if ( $type_id > 0 ) {
				$wp_data['activity_type_id'] = $type_id;
			}

			return $wp_data;
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	// ─── Data access ──────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		if ( 'lead' === $entity_type ) {
			return $this->load_lead_data( $wp_id );
		}

		if ( 'activity' === $entity_type ) {
			return $this->load_activity_data( $wp_id );
		}

		return [];
	}

	/**
	 * Load and enrich lead data.
	 *
	 * Attempts cross-module partner linking: if the WP ERP contact shares
	 * an email with a WordPress user synced by the `crm` module, link the
	 * Odoo partner.
	 *
	 * @param int $contact_id WP ERP contact ID.
	 * @return array<string, mixed>
	 */
	private function load_lead_data( int $contact_id ): array {
		$data = $this->handler->load_lead( $contact_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Cross-module partner linking via email.
		$email = $data['email_from'] ?? '';
		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$partner_id = $this->entity_map->get_odoo_id( 'crm', 'contact', $user->ID );
				if ( $partner_id ) {
					$data['partner_id'] = $partner_id;
				}
			}
		}

		return $data;
	}

	/**
	 * Load and enrich activity data.
	 *
	 * Resolves the linked lead's Odoo ID as `res_id` and sets `res_model`.
	 *
	 * @param int $activity_id WP ERP CRM activity ID.
	 * @return array<string, mixed>
	 */
	private function load_activity_data( int $activity_id ): array {
		$data = $this->handler->load_activity( $activity_id );
		if ( empty( $data ) ) {
			return [];
		}

		$contact_id = (int) ( $data['contact_id'] ?? 0 );
		unset( $data['contact_id'] );

		// Resolve the Odoo ID of the linked crm.lead.
		$lead_odoo_id = $this->get_mapping( 'lead', $contact_id );
		if ( ! $lead_odoo_id ) {
			$this->logger->warning(
				'Cannot push CRM activity: linked lead not synced.',
				[
					'activity_id' => $activity_id,
					'contact_id'  => $contact_id,
				]
			);
			return [];
		}

		$data['res_id']    = $lead_odoo_id;
		$data['res_model'] = 'crm.lead';

		return $data;
	}

	// ─── Odoo resolution helpers ──────────────────────────

	/**
	 * Resolve a mail.activity.type ID by name.
	 *
	 * Uses transient caching to avoid repeated API calls.
	 *
	 * @param string $type_name Activity type name (e.g. 'Email', 'Phone Call').
	 * @return int Activity type ID, or 0 if not resolved.
	 */
	private function resolve_activity_type_id( string $type_name ): int {
		if ( '' === $type_name ) {
			return 0;
		}

		$cache_key = 'wp4odoo_activity_type_' . md5( $type_name );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		try {
			$client = $this->client();
			$ids    = $client->search( 'mail.activity.type', [ [ 'name', 'ilike', $type_name ] ], 0, 1 );
			$id     = ! empty( $ids ) ? (int) $ids[0] : 0;

			if ( $id > 0 ) {
				set_transient( $cache_key, $id, DAY_IN_SECONDS );
			}

			return $id;
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'Failed to resolve activity type ID.',
				[
					'type_name' => $type_name,
					'error'     => $e->getMessage(),
				]
			);
			return 0;
		}
	}
}
