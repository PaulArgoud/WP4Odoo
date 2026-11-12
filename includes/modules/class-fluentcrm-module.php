<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentCRM Module — bidirectional sync between FluentCRM and Odoo.
 *
 * Syncs FluentCRM subscribers to Odoo mailing.contact (bidirectional),
 * mailing lists to mailing.list (bidirectional), and tags to
 * res.partner.category (push-only).
 *
 * FluentCRM stores data in custom DB tables (not CPTs):
 * - {prefix}fc_subscribers — subscriber records
 * - {prefix}fc_lists — mailing lists
 * - {prefix}fc_tags — tags
 * - {prefix}fc_subscriber_pivot — M2M subscriber↔list/tag
 *
 * Requires the FluentCRM plugin to be active.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
class FluentCRM_Module extends Module_Base {

	use FluentCRM_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.8';
	protected const PLUGIN_TESTED_UP_TO = '2.9';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'subscriber' => 'mailing.contact',
		'list'       => 'mailing.list',
		'tag'        => 'res.partner.category',
	];

	/**
	 * Default field mappings.
	 *
	 * Subscriber list_ids are pre-formatted as Odoo M2M tuples by map_to_odoo().
	 * Tag mapping is name-only (push-only, no pull).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'subscriber' => [
			'email'      => 'email',
			'first_name' => 'name',
			'status'     => 'x_status',
			'list_ids'   => 'list_ids',
		],
		'list'       => [
			'title'       => 'name',
			'description' => 'x_description',
		],
		'tag'        => [
			'title' => 'name',
		],
	];

	/**
	 * FluentCRM data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var FluentCRM_Handler
	 */
	private FluentCRM_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'fluentcrm', 'FluentCRM', $client_provider, $entity_map, $settings );
		$this->handler = new FluentCRM_Handler( $this->logger );
	}

	/**
	 * Sync direction: bidirectional for subscribers/lists, push-only for tags.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Boot the module: register FluentCRM hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'FLUENTCRM' ) ) {
			$this->logger->warning( __( 'FluentCRM module enabled but FluentCRM is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_subscribers'] ) ) {
			add_action( 'fluentcrm_subscriber_created', $this->safe_callback( [ $this, 'on_subscriber_created' ] ), 10, 1 );
			add_action( 'fluentcrm_subscriber_status_changed', $this->safe_callback( [ $this, 'on_subscriber_status_changed' ] ), 10, 2 );
		}

		if ( ! empty( $settings['sync_lists'] ) ) {
			add_action( 'fluent_crm/list_created', $this->safe_callback( [ $this, 'on_list_created' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_tags'] ) ) {
			add_action( 'fluent_crm/tag_created', $this->safe_callback( [ $this, 'on_tag_created' ] ), 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_subscribers' => true,
			'sync_lists'       => true,
			'sync_tags'        => true,
			'pull_subscribers' => true,
			'pull_lists'       => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_subscribers' => [
				'label'       => __( 'Sync subscribers', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push FluentCRM subscribers to Odoo as mailing contacts.', 'wp4odoo' ),
			],
			'sync_lists'       => [
				'label'       => __( 'Sync lists', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push FluentCRM mailing lists to Odoo.', 'wp4odoo' ),
			],
			'sync_tags'        => [
				'label'       => __( 'Sync tags', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push FluentCRM tags to Odoo as partner categories.', 'wp4odoo' ),
			],
			'pull_subscribers' => [
				'label'       => __( 'Pull subscribers from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull mailing contact changes from Odoo back to FluentCRM.', 'wp4odoo' ),
			],
			'pull_lists'       => [
				'label'       => __( 'Pull lists from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull mailing list changes from Odoo back to FluentCRM.', 'wp4odoo' ),
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
		return defined( 'FLUENTCRM' );
	}

	/**
	 * Get external dependency status for FluentCRM.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'FluentCRM' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'FLUENTCRM_PLUGIN_VERSION' ) ? FLUENTCRM_PLUGIN_VERSION : '';
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
			'subscriber' => $this->handler->load_subscriber( $wp_id ),
			'list'       => $this->handler->load_list( $wp_id ),
			'tag'        => $this->handler->load_tag( $wp_id ),
			default      => [],
		};
	}

	// ─── Odoo Mapping ────────────────────────────────────────

	/**
	 * Transform WordPress data to Odoo field values.
	 *
	 * For subscribers, resolves list_ids to Odoo M2M format:
	 * maps each FluentCRM list ID → Odoo mailing.list ID via entity_map,
	 * then formats as [(6, 0, [ids])].
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array Odoo-compatible field values.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		$odoo_values = parent::map_to_odoo( $entity_type, $wp_data );

		if ( 'subscriber' === $entity_type && isset( $wp_data['list_ids'] ) && is_array( $wp_data['list_ids'] ) ) {
			$odoo_list_ids = [];

			foreach ( $wp_data['list_ids'] as $fc_list_id ) {
				$odoo_id = $this->get_mapping( 'list', (int) $fc_list_id );
				if ( $odoo_id ) {
					$odoo_list_ids[] = $odoo_id;
				}
			}

			$odoo_values['list_ids'] = [ [ 6, 0, $odoo_list_ids ] ];
		}

		// Combine first_name for subscriber name field.
		if ( 'subscriber' === $entity_type ) {
			$first = $wp_data['first_name'] ?? '';
			$last  = $wp_data['last_name'] ?? '';
			$name  = trim( $first . ' ' . $last );
			if ( '' !== $name && isset( $odoo_values['name'] ) ) {
				$odoo_values['name'] = $name;
			}
		}

		return $odoo_values;
	}

	/**
	 * Transform Odoo data to WordPress-compatible format.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Odoo record data.
	 * @return array WordPress-compatible data.
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		return match ( $entity_type ) {
			'subscriber' => $this->handler->parse_subscriber_from_odoo( $odoo_data ),
			'list'       => $this->handler->parse_list_from_odoo( $odoo_data ),
			default      => parent::map_from_odoo( $entity_type, $odoo_data ),
		};
	}

	// ─── Data Saving ─────────────────────────────────────────

	/**
	 * Save data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		return match ( $entity_type ) {
			'subscriber' => $this->handler->save_subscriber( $data ),
			'list'       => $this->handler->save_list( $data ),
			default      => 0,
		};
	}

	// ─── Pull override ───────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Tags are push-only and cannot be pulled.
	 * Subscribers and lists respect pull settings.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		// Tags are push-only: skip pull.
		if ( 'tag' === $entity_type ) {
			return \WP4Odoo\Sync_Result::success( null );
		}

		// Check pull settings.
		$settings = $this->get_settings();

		if ( 'subscriber' === $entity_type && empty( $settings['pull_subscribers'] ) ) {
			return \WP4Odoo\Sync_Result::success( null );
		}

		if ( 'list' === $entity_type && empty( $settings['pull_lists'] ) ) {
			return \WP4Odoo\Sync_Result::success( null );
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	// ─── Deduplication ───────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Subscribers dedup by email, lists and tags dedup by name.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'subscriber' === $entity_type && ! empty( $odoo_values['email'] ) ) {
			return [ [ 'email', '=', $odoo_values['email'] ] ];
		}

		if ( in_array( $entity_type, [ 'list', 'tag' ], true ) && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}
}
