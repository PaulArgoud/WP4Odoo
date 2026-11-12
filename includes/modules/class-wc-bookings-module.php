<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bookings Module — sync booking products and bookings with Odoo.
 *
 * Syncs WC Booking products as Odoo service products (product.product)
 * and individual bookings as Odoo calendar events (calendar.event), with
 * automatic partner resolution via Partner_Service.
 *
 * WC Bookings uses WC products (type 'booking') for bookable resources
 * and the `wc_booking` CPT for individual reservations. The handler
 * accesses both via WC CRUD classes (WC_Product, WC_Booking).
 *
 * Bidirectional: products ↔ Odoo, bookings → Odoo only.
 * No mutual exclusivity — coexists with WooCommerce module (same
 * pattern as WC Subscriptions).
 *
 * Requires the WooCommerce Bookings plugin to be active.
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
class WC_Bookings_Module extends Booking_Module_Base {

	use WC_Bookings_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '2.1';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'service' => 'product.product',
		'booking' => 'calendar.event',
	];

	/**
	 * Default field mappings.
	 *
	 * Booking mappings are minimal because map_to_odoo() is overridden
	 * by Booking_Module_Base to pass handler-formatted data directly to
	 * Odoo (partner_ids M2M commands).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'service' => [
			'name'        => 'name',
			'description' => 'description_sale',
			'price'       => 'list_price',
		],
		'booking' => [
			'name'        => 'name',
			'start'       => 'start',
			'stop'        => 'stop',
			'partner_ids' => 'partner_ids',
			'description' => 'description',
		],
	];

	/**
	 * WC Bookings data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WC_Bookings_Handler
	 */
	private WC_Bookings_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                      $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_bookings', 'WooCommerce Bookings', $client_provider, $entity_map, $settings );
		$this->handler = new WC_Bookings_Handler( $this->logger );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_modules(): array {
		return [ 'woocommerce' ];
	}

	/**
	 * Boot the module: register WC Bookings hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->register_hooks();
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_products' => true,
			'sync_bookings' => true,
			'pull_services' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_products' => [
				'label'       => __( 'Sync products', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WC Booking products to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_bookings' => [
				'label'       => __( 'Sync bookings', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WC Bookings to Odoo as calendar events.', 'wp4odoo' ),
			],
			'pull_services' => [
				'label'       => __( 'Pull products', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo service products into WC Booking products.', 'wp4odoo' ),
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
		return class_exists( 'WC_Product_Booking' );
	}

	/**
	 * Get external dependency status for WC Bookings.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'WooCommerce Bookings' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WC_BOOKINGS_VERSION' ) ? WC_BOOKINGS_VERSION : '';
	}

	/**
	 * Get the handler instance (used by hooks trait).
	 *
	 * @return WC_Bookings_Handler
	 */
	public function get_handler(): WC_Bookings_Handler {
		return $this->handler;
	}

	// ─── Booking_Module_Base abstracts ──────────────────────

	/**
	 * {@inheritDoc}
	 */
	protected function get_booking_entity_type(): string {
		return 'booking';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_fallback_label(): string {
		return __( 'Booking', 'wp4odoo' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_load_service( int $service_id ): array {
		return $this->handler->load_product( $service_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_extract_booking_fields( int $booking_id ): array {
		return $this->handler->extract_booking_fields( $booking_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_get_service_id( int $booking_id ): int {
		return $this->handler->get_product_id_for_booking( $booking_id );
	}

	// ─── Pull: handler delegation ───────────────────────────

	/**
	 * {@inheritDoc}
	 */
	protected function handler_parse_service_from_odoo( array $odoo_data ): array {
		return $this->handler->parse_product_from_odoo( $odoo_data );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_save_service( array $data, int $wp_id ): int {
		return $this->handler->save_product( $data, $wp_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_delete_service( int $service_id ): bool {
		return $this->handler->delete_product( $service_id );
	}

	// ─── Data access override ───────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * Overrides parent to augment booking data with allday support.
	 * The base class's load_booking_data() returns name/start/stop/
	 * partner_ids/description but does not include allday.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       Plugin entity ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		$data = parent::load_wp_data( $entity_type, $wp_id );

		if ( 'booking' === $entity_type && ! empty( $data ) ) {
			if ( $this->handler->is_all_day( $wp_id ) ) {
				$data['allday'] = true;
			}
		}

		return $data;
	}
}
