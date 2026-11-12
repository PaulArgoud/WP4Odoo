<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Shipping Module — bidirectional shipment tracking sync,
 * optional carrier push.
 *
 * Pushes WC tracking data to Odoo stock.picking (carrier_tracking_ref),
 * pulls Odoo shipment tracking back to WC order meta, and optionally
 * syncs WC shipping methods as delivery.carrier records.
 *
 * Supports optional integration with ShipStation, Sendcloud, Packlink,
 * and Advanced Shipment Tracking (AST) for real-time tracking push.
 *
 * Requires WooCommerce to be active.
 * Independent module — coexists with the WooCommerce module.
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
class WC_Shipping_Module extends Module_Base {

	use WC_Shipping_Hooks;

	protected const PLUGIN_MIN_VERSION  = '7.0';
	protected const PLUGIN_TESTED_UP_TO = '11.3';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'carrier'  => 'delivery.carrier',
		'shipment' => 'stock.picking',
	];

	/**
	 * Default field mappings.
	 *
	 * Carrier data is mapped via standard fields.
	 * Shipment data is pre-formatted by the handler (identity pass-through).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'carrier'  => [
			'name'          => 'name',
			'tracking_url'  => 'tracking_url',
			'delivery_type' => 'delivery_type',
		],
		'shipment' => [
			'carrier_tracking_ref' => 'carrier_tracking_ref',
			'carrier_id'           => 'carrier_id',
			'state'                => 'state',
			'date_done'            => 'date_done',
			'origin'               => 'origin',
		],
	];

	/**
	 * Shipping data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WC_Shipping_Handler
	 */
	private WC_Shipping_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_shipping', 'WC Shipping', $client_provider, $entity_map, $settings );
		$this->handler = new WC_Shipping_Handler( $this->logger, $this->entity_map() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_modules(): array {
		return [ 'woocommerce' ];
	}

	/**
	 * Sync direction: bidirectional.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Boot the module: register shipping hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->logger->warning( __( 'WC Shipping module enabled but WooCommerce is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		// AST plugin: tracking added.
		if ( ! empty( $settings['sync_tracking_push'] ) ) {
			add_action( 'wc_shipment_tracking_added', $this->safe_callback( [ $this, 'on_ast_tracking_added' ] ), 10, 2 );
		}

		// ShipStation.
		if ( ! empty( $settings['shipstation_hooks'] ) && defined( 'SHIPSTATION_WC_VERSION' ) ) {
			add_action( 'woocommerce_shipstation_shipnotify', $this->safe_callback( [ $this, 'on_shipstation_shipped' ] ), 10, 2 );
		}

		// Sendcloud.
		if ( ! empty( $settings['sendcloud_hooks'] ) && $this->handler->has_sendcloud() ) {
			add_action( 'sendcloud_parcel_status_changed', $this->safe_callback( [ $this, 'on_sendcloud_status' ] ), 10, 3 );
		}

		// Packlink.
		if ( ! empty( $settings['packlink_hooks'] ) && $this->handler->has_packlink() ) {
			add_action( 'packlink_tracking_updated', $this->safe_callback( [ $this, 'on_packlink_tracking' ] ), 10, 2 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_carriers'         => false,
			'sync_tracking_push'    => true,
			'sync_tracking_pull'    => true,
			'auto_validate_picking' => false,
			'shipstation_hooks'     => true,
			'sendcloud_hooks'       => true,
			'packlink_hooks'        => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_carriers'         => [
				'label'       => __( 'Sync carriers', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WooCommerce shipping methods to Odoo as delivery carriers.', 'wp4odoo' ),
			],
			'sync_tracking_push'    => [
				'label'       => __( 'Push tracking to Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push tracking numbers from WooCommerce to Odoo stock pickings.', 'wp4odoo' ),
			],
			'sync_tracking_pull'    => [
				'label'       => __( 'Pull tracking from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull tracking numbers from Odoo to WooCommerce order meta.', 'wp4odoo' ),
			],
			'auto_validate_picking' => [
				'label'       => __( 'Auto-validate pickings', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically validate Odoo stock pickings when tracking is pushed.', 'wp4odoo' ),
			],
			'shipstation_hooks'     => [
				'label'       => __( 'ShipStation hooks', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Hook into ShipStation for automatic tracking push.', 'wp4odoo' ),
			],
			'sendcloud_hooks'       => [
				'label'       => __( 'Sendcloud hooks', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Hook into Sendcloud for parcel status updates.', 'wp4odoo' ),
			],
			'packlink_hooks'        => [
				'label'       => __( 'Packlink hooks', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Hook into Packlink for tracking updates.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'WooCommerce' ), 'WooCommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WC_VERSION' ) ? WC_VERSION : '';
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Only shipments (tracking) can be pulled. Carriers are push-only.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'carrier' === $entity_type ) {
			$this->logger->info(
				'Carrier pull not supported — push-only.',
				[ 'odoo_id' => $odoo_id ]
			);
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'shipment' === $entity_type ) {
			$settings = $this->get_settings();
			if ( empty( $settings['sync_tracking_pull'] ) ) {
				return \WP4Odoo\Sync_Result::success();
			}
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	// ─── Data access ───────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'carrier'  => $this->handler->load_carrier( $wp_id ),
			'shipment' => $this->handler->load_shipment_from_order( $wp_id ),
			default    => [],
		};
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'shipment' === $entity_type ) {
			return $this->handler->parse_shipment_from_odoo( $odoo_data );
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
		if ( 'shipment' === $entity_type ) {
			return $this->handler->save_shipment( $data, $wp_id );
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Shipments and carriers cannot be deleted from Odoo side.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return false;
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Carriers dedup by name. Shipments are updated, not deduped.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'carrier' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}
}
