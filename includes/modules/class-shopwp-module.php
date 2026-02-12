<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShopWP Module — push Shopify products to Odoo.
 *
 * ShopWP syncs Shopify products into WordPress as custom post types
 * (`wps_products`). This module pushes those products to Odoo.
 *
 * Push-only (WP → Odoo). Products only — orders stay on Shopify
 * (ShopWP does not sync orders to WordPress).
 *
 * Mutually exclusive with other e-commerce modules (WooCommerce, EDD, Sales, Ecwid).
 *
 * Requires the ShopWP plugin to be active.
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
class ShopWP_Module extends Module_Base {

	use ShopWP_Hooks;

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
	];

	/**
	 * ShopWP data handler.
	 *
	 * @var ShopWP_Handler
	 */
	private ShopWP_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                      $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'shopwp', 'ShopWP', $client_provider, $entity_map, $settings );
		$this->handler = new ShopWP_Handler( $this->logger );
	}

	/**
	 * Boot the module: register ShopWP hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'SHOPWP_PLUGIN_DIR' ) ) {
			$this->logger->warning( __( 'ShopWP module enabled but ShopWP is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_products'] ) ) {
			add_action( 'save_post_wps_products', [ $this, 'on_product_save' ], 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_products' => true,
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
				'description' => __( 'Push ShopWP products to Odoo.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for ShopWP.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'SHOPWP_PLUGIN_DIR' ), 'ShopWP' );
	}

	// ─── Data access ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'product' => $this->handler->load_product( $wp_id ),
			default   => [],
		};
	}
}
