<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MailPoet Module — bidirectional sync between MailPoet and Odoo.
 *
 * Syncs MailPoet subscribers to Odoo mailing.contact (bidirectional)
 * and mailing lists to mailing.list (bidirectional).
 *
 * MailPoet stores data in custom DB tables and provides access via
 * the \MailPoet\API\API class.
 *
 * Requires the MailPoet plugin to be active.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class MailPoet_Module extends Module_Base {

	use MailPoet_Hooks;

	protected const PLUGIN_MIN_VERSION  = '4.0';
	protected const PLUGIN_TESTED_UP_TO = '5.5';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'subscriber' => 'mailing.contact',
		'list'       => 'mailing.list',
	];

	/**
	 * Default field mappings.
	 *
	 * Subscriber list_ids are pre-formatted as Odoo M2M tuples by map_to_odoo().
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
	];

	/**
	 * MailPoet data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var MailPoet_Handler
	 */
	private MailPoet_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'mailpoet', 'MailPoet', $client_provider, $entity_map, $settings );
		$this->handler = new MailPoet_Handler( $this->logger );
	}

	/**
	 * Sync direction: bidirectional for subscribers and lists.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Boot the module: register MailPoet hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'MAILPOET_VERSION' ) ) {
			$this->logger->warning( __( 'MailPoet module enabled but MailPoet is not active.', 'wp4odoo' ) );
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
			'sync_subscribers' => true,
			'sync_lists'       => true,
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
				'description' => __( 'Push MailPoet subscribers to Odoo as mailing contacts.', 'wp4odoo' ),
			],
			'sync_lists'       => [
				'label'       => __( 'Sync lists', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push MailPoet mailing lists to Odoo.', 'wp4odoo' ),
			],
			'pull_subscribers' => [
				'label'       => __( 'Pull subscribers from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull mailing contact changes from Odoo back to MailPoet.', 'wp4odoo' ),
			],
			'pull_lists'       => [
				'label'       => __( 'Pull lists from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull mailing list changes from Odoo back to MailPoet.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for MailPoet.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'MAILPOET_VERSION' ), 'MailPoet' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'MAILPOET_VERSION' ) ? MAILPOET_VERSION : '';
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
			default      => [],
		};
	}

	// ─── Odoo Mapping ────────────────────────────────────────

	/**
	 * Transform WordPress data to Odoo field values.
	 *
	 * For subscribers, resolves list_ids to Odoo M2M format:
	 * maps each MailPoet list ID to Odoo mailing.list ID via entity_map,
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

			foreach ( $wp_data['list_ids'] as $mp_list_id ) {
				$odoo_id = $this->get_mapping( 'list', (int) $mp_list_id );
				if ( $odoo_id ) {
					$odoo_list_ids[] = $odoo_id;
				}
			}

			$odoo_values['list_ids'] = [ [ 6, 0, $odoo_list_ids ] ];
		}

		// Combine first_name + last_name for subscriber name field.
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
			'subscriber' => $this->handler->save_subscriber( $data, $wp_id ),
			'list'       => $this->handler->save_list( $data, $wp_id ),
			default      => 0,
		};
	}

	// ─── Pull override ───────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
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
	 * Subscribers dedup by email, lists dedup by name.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'subscriber' === $entity_type && ! empty( $odoo_values['email'] ) ) {
			return [ [ 'email', '=', $odoo_values['email'] ] ];
		}

		if ( 'list' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}
}
