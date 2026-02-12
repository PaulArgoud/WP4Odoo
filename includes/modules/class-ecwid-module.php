<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ecwid Module — push Ecwid products and orders to Odoo.
 *
 * Ecwid is a cloud-hosted e-commerce platform. Product and order data
 * lives on Ecwid servers, not in WordPress. This module uses WP-Cron
 * polling (every 5 minutes) with the Ecwid REST API to detect changes
 * via SHA-256 hash comparison (same pattern as Bookly).
 *
 * Push-only (Ecwid → Odoo via WP-Cron).
 *
 * Mutually exclusive with other e-commerce modules (WooCommerce, EDD, Sales, ShopWP).
 *
 * Requires the Ecwid plugin to be active and API credentials configured.
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
class Ecwid_Module extends Module_Base {

	use Ecwid_Cron_Hooks;

	protected string $exclusive_group = 'ecommerce';
	protected int $exclusive_priority = 5;

	/**
	 * Sync direction: push-only (WP → Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'product' => 'product.product',
		'order'   => 'sale.order',
	];

	/**
	 * Default field mappings.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'product' => [
			'product_name' => 'name',
			'list_price'   => 'list_price',
			'default_code' => 'default_code',
			'description'  => 'description_sale',
			'type'         => 'type',
		],
		'order'   => [
			'partner_id'       => 'partner_id',
			'date_order'       => 'date_order',
			'client_order_ref' => 'client_order_ref',
			'order_line'       => 'order_line',
		],
	];

	/**
	 * Ecwid data handler.
	 *
	 * @var Ecwid_Handler
	 */
	private Ecwid_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                      $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'ecwid', 'Ecwid', $client_provider, $entity_map, $settings );
		$this->handler = new Ecwid_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WP-Cron polling.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->register_cron();
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'ecwid_store_id'  => '',
			'ecwid_api_token' => '',
			'sync_products'   => true,
			'sync_orders'     => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'ecwid_store_id'  => [
				'label'       => __( 'Store ID', 'wp4odoo' ),
				'type'        => 'text',
				'description' => __( 'Your Ecwid store ID.', 'wp4odoo' ),
			],
			'ecwid_api_token' => [
				'label'       => __( 'API token', 'wp4odoo' ),
				'type'        => 'text',
				'description' => __( 'Your Ecwid API secret token.', 'wp4odoo' ),
			],
			'sync_products'   => [
				'label'       => __( 'Sync products', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Ecwid products to Odoo.', 'wp4odoo' ),
			],
			'sync_orders'     => [
				'label'       => __( 'Sync orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Ecwid orders to Odoo.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for Ecwid.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'ECWID_PLUGIN_DIR' ), 'Ecwid' );
	}

	/**
	 * Get the handler instance (used by cron trait).
	 *
	 * @return Ecwid_Handler
	 */
	public function get_handler(): Ecwid_Handler {
		return $this->handler;
	}

	// ─── Data access ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * For Ecwid, data comes from the API (stored transiently during poll).
	 * When called by Sync_Engine for queued jobs, re-fetches from API.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       Ecwid entity ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		$settings = $this->get_settings();
		$store_id = (string) ( $settings['ecwid_store_id'] ?? '' );
		$token    = (string) ( $settings['ecwid_api_token'] ?? '' );

		if ( 'product' === $entity_type ) {
			$products = $this->handler->fetch_products( $store_id, $token );
			foreach ( $products as $product ) {
				if ( (int) ( $product['id'] ?? 0 ) === $wp_id ) {
					return $this->handler->load_product( $product );
				}
			}
		}

		if ( 'order' === $entity_type ) {
			$orders = $this->handler->fetch_orders( $store_id, $token );
			foreach ( $orders as $order ) {
				if ( (int) ( $order['orderNumber'] ?? 0 ) === $wp_id ) {
					$email = (string) ( $order['email'] ?? '' );
					$name  = (string) ( $order['billingPerson']['name'] ?? $email );

					$partner_id = 0;
					if ( $email ) {
						$partner_id = $this->partner_service()->get_or_create( $email, [ 'name' => $name ] ) ?? 0;
					}

					if ( ! $partner_id ) {
						$this->logger->warning( 'Cannot resolve partner for Ecwid order.', [ 'order_number' => $wp_id ] );
						return [];
					}

					return $this->handler->load_order( $order, $partner_id );
				}
			}
		}

		return [];
	}
}
