<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Inventory Module — advanced multi-warehouse stock management
 * with optional ATUM integration.
 *
 * Syncs Odoo warehouses and stock locations as reference data (pull-only),
 * and stock movements (stock.move) bidirectionally. Complements the
 * WooCommerce module's global stock.quant sync with individual move tracking.
 *
 * Supports optional ATUM Multi-Inventory for multi-location WC stock.
 *
 * Requires WooCommerce to be active.
 * Independent module — coexists with the WooCommerce module.
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
class WC_Inventory_Module extends Module_Base {

	use WC_Inventory_Hooks;

	protected const PLUGIN_MIN_VERSION  = '7.0';
	protected const PLUGIN_TESTED_UP_TO = '11.3';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'warehouse' => 'stock.warehouse',
		'location'  => 'stock.location',
		'movement'  => 'stock.move',
	];

	/**
	 * Default field mappings.
	 *
	 * Warehouse and location use direct field maps.
	 * Movement data is pre-formatted by the handler (identity pass-through).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'warehouse' => [
			'name' => 'name',
			'code' => 'code',
		],
		'location'  => [
			'name'          => 'complete_name',
			'location_type' => 'usage',
		],
		'movement'  => [
			'product_id'       => 'product_id',
			'product_uom_qty'  => 'product_uom_qty',
			'state'            => 'state',
			'location_id'      => 'location_id',
			'location_dest_id' => 'location_dest_id',
			'reference'        => 'reference',
			'date'             => 'date',
			'name'             => 'name',
		],
	];

	/**
	 * Inventory data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WC_Inventory_Handler
	 */
	private WC_Inventory_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_inventory', 'WC Inventory', $client_provider, $entity_map, $settings );
		$this->handler = new WC_Inventory_Handler( $this->logger, $this->entity_map() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_modules(): array {
		return [ 'woocommerce' ];
	}

	/**
	 * Sync direction: bidirectional for movements, pull-only for warehouses/locations.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Boot the module: register inventory hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->logger->warning( __( 'WC Inventory module enabled but WooCommerce is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		// Push: WC stock adjustment → Odoo stock.move (priority 20, after WC module at 10).
		if ( ! empty( $settings['push_adjustments'] ) ) {
			add_action( 'woocommerce_product_set_stock', $this->safe_callback( [ $this, 'on_inventory_adjustment' ] ), 20, 1 );
		}

		// ATUM Multi-Inventory.
		if ( ! empty( $settings['push_adjustments'] ) && $this->handler->has_atum() ) {
			add_action( 'atum/stock_central/after_save_data', $this->safe_callback( [ $this, 'on_atum_stock_change' ] ), 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_movements'       => true,
			'sync_warehouses'      => false,
			'sync_locations'       => false,
			'push_adjustments'     => true,
			'default_warehouse_id' => 0,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_movements'       => [
				'label'       => __( 'Sync stock movements', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Synchronize stock movements between WooCommerce and Odoo.', 'wp4odoo' ),
			],
			'sync_warehouses'      => [
				'label'       => __( 'Sync warehouses', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo warehouses for reference.', 'wp4odoo' ),
			],
			'sync_locations'       => [
				'label'       => __( 'Sync stock locations', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo stock locations for reference.', 'wp4odoo' ),
			],
			'push_adjustments'     => [
				'label'       => __( 'Push stock adjustments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WooCommerce stock changes to Odoo as stock movements.', 'wp4odoo' ),
			],
			'default_warehouse_id' => [
				'label'       => __( 'Default warehouse ID', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'Odoo warehouse ID for stock movements (0 for auto-detect).', 'wp4odoo' ),
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
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'WooCommerce' );
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
	 * Warehouses and locations are pull-only reference data.
	 * Movements are bidirectional.
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

		if ( 'warehouse' === $entity_type && empty( $settings['sync_warehouses'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'location' === $entity_type && empty( $settings['sync_locations'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'movement' === $entity_type && empty( $settings['sync_movements'] ) ) {
			return \WP4Odoo\Sync_Result::success();
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
			'movement' => $this->handler->load_movement( $wp_id ),
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
		return match ( $entity_type ) {
			'warehouse' => $this->handler->parse_warehouse_from_odoo( $odoo_data ),
			'location'  => $this->handler->parse_location_from_odoo( $odoo_data ),
			'movement'  => $this->handler->parse_movement_from_odoo( $odoo_data ),
			default     => parent::map_from_odoo( $entity_type, $odoo_data ),
		};
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
		return match ( $entity_type ) {
			'warehouse' => $this->handler->save_warehouse( $data, $wp_id ),
			'location'  => $this->handler->save_location( $data, $wp_id ),
			'movement'  => $this->handler->save_movement( $data, $wp_id ),
			default     => 0,
		};
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Inventory entities cannot be deleted from Odoo side.
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
	 * Warehouses dedup by code, locations by complete_name,
	 * movements by reference.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'warehouse' === $entity_type && ! empty( $odoo_values['code'] ) ) {
			return [ [ 'code', '=', $odoo_values['code'] ] ];
		}

		if ( 'location' === $entity_type && ! empty( $odoo_values['complete_name'] ) ) {
			return [ [ 'complete_name', '=', $odoo_values['complete_name'] ] ];
		}

		if ( 'movement' === $entity_type && ! empty( $odoo_values['reference'] ) ) {
			return [ [ 'reference', '=', $odoo_values['reference'] ] ];
		}

		return [];
	}
}
