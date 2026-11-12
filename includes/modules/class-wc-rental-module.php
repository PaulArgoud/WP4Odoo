<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Rental Module — push WooCommerce rental orders to Odoo Rental.
 *
 * Detects WooCommerce orders containing rental products (identified by
 * a configurable meta key) and pushes them to Odoo as sale.order records
 * with Odoo Rental fields (is_rental, pickup_date, return_date).
 *
 * Generic approach: not tied to a specific WC rental plugin. Any plugin
 * that stores rental metadata on WC products (start/return dates) is
 * supported by configuring the meta keys in settings.
 *
 * Requires the WooCommerce module to be active.
 *
 * @package WP4Odoo
 * @since   3.7.0
 */
class WC_Rental_Module extends Module_Base {

	use WC_Rental_Hooks;

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'rental' => 'sale.order',
	];

	/**
	 * Default field mappings.
	 *
	 * Rental orders are pre-formatted by the handler (identity pass-through).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'rental' => [
			'partner_id'  => 'partner_id',
			'date_order'  => 'date_order',
			'state'       => 'state',
			'order_line'  => 'order_line',
			'is_rental'   => 'is_rental',
			'pickup_date' => 'pickup_date',
			'return_date' => 'return_date',
		],
	];

	/**
	 * WC Rental data handler.
	 *
	 * @var WC_Rental_Handler
	 */
	private WC_Rental_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_rental', 'WC Rental', $client_provider, $entity_map, $settings );
		$this->handler = new WC_Rental_Handler( $this->logger );
	}

	/**
	 * Sync direction: push-only (WP → Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	/**
	 * Required modules: WooCommerce must be active and booted.
	 *
	 * @return string[]
	 */
	public function get_required_modules(): array {
		return [ 'woocommerce' ];
	}

	/**
	 * Boot the module: register WooCommerce order hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->logger->warning( __( 'WC Rental module enabled but WooCommerce is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_rentals'] ) ) {
			add_action( 'woocommerce_order_status_changed', $this->safe_callback( [ $this, 'on_order_status_changed' ] ), 20, 3 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_rentals'    => true,
			'rental_meta_key' => '_rental',
			'rental_start'    => '_rental_start_date',
			'rental_return'   => '_rental_return_date',
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_rentals'    => [
				'label'       => __( 'Sync rental orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WooCommerce rental orders to Odoo as sale orders.', 'wp4odoo' ),
			],
			'rental_meta_key' => [
				'label'       => __( 'Rental product meta key', 'wp4odoo' ),
				'type'        => 'text',
				'description' => __( 'Product meta key identifying a rental product (e.g. _rental).', 'wp4odoo' ),
			],
			'rental_start'    => [
				'label'       => __( 'Start date meta key', 'wp4odoo' ),
				'type'        => 'text',
				'description' => __( 'Order item meta key for rental start date.', 'wp4odoo' ),
			],
			'rental_return'   => [
				'label'       => __( 'Return date meta key', 'wp4odoo' ),
				'type'        => 'text',
				'description' => __( 'Order item meta key for rental return date.', 'wp4odoo' ),
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

	// ─── Data Access ─────────────────────────────────────────

	/**
	 * Load WordPress data for a rental order.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WooCommerce order ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		if ( 'rental' !== $entity_type ) {
			return [];
		}

		$settings = $this->get_settings();

		return $this->handler->load_rental_order(
			$wp_id,
			$settings['rental_meta_key'] ?? '_rental',
			$settings['rental_start'] ?? '_rental_start_date',
			$settings['rental_return'] ?? '_rental_return_date',
			fn( int $product_id ) => $this->entity_map->get_odoo_id( 'woocommerce', 'product', $product_id ),
			fn( int $user_id ) => $this->resolve_partner_from_user( $user_id )
		);
	}

	/**
	 * Map WP data to Odoo values (identity — pre-formatted).
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array<string, mixed>
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		return $wp_data;
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'rental' === $entity_type && ! empty( $odoo_values['client_order_ref'] ) ) {
			return [ [ 'client_order_ref', '=', $odoo_values['client_order_ref'] ] ];
		}

		return [];
	}
}
