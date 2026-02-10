<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\CPT_Helper;
use WP4Odoo\Field_Mapper;
use WP4Odoo\Module_Base;
use WP4Odoo\Partner_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sales Module — products, orders, invoices, and customer portal.
 *
 * Order/invoice sync is handled directly by this class.
 * Portal rendering is delegated to Portal_Manager.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Sales_Module extends Module_Base {

	/**
	 * Order meta fields: data key => post meta key.
	 */
	private const ORDER_META = [
		'_order_total'        => '_order_total',
		'_order_date'         => '_order_date',
		'_order_state'        => '_order_state',
		'_wp4odoo_partner_id' => '_wp4odoo_partner_id',
		'_order_currency'     => '_order_currency',
	];

	/**
	 * Invoice meta fields: data key => post meta key.
	 */
	private const INVOICE_META = [
		'_invoice_total'      => '_invoice_total',
		'_invoice_date'       => '_invoice_date',
		'_invoice_state'      => '_invoice_state',
		'_payment_state'      => '_payment_state',
		'_wp4odoo_partner_id' => '_wp4odoo_partner_id',
		'_invoice_currency'   => '_invoice_currency',
	];

	protected string $id   = 'sales';
	protected string $name = 'Sales';

	protected array $odoo_models = [
		'product' => 'product.template',
		'order'   => 'sale.order',
		'invoice' => 'account.move',
	];

	protected array $default_mappings = [
		'product' => [
			'post_title'   => 'name',
			'post_content' => 'description_sale',
			'_price'       => 'list_price',
			'_sku'         => 'default_code',
		],
		'order'   => [
			'post_title'          => 'name',
			'_order_total'        => 'amount_total',
			'_order_date'         => 'date_order',
			'_order_state'        => 'state',
			'_wp4odoo_partner_id' => 'partner_id',
			'_order_currency'     => 'currency_id',
		],
		'invoice' => [
			'post_title'          => 'name',
			'_invoice_total'      => 'amount_total',
			'_invoice_date'       => 'invoice_date',
			'_invoice_state'      => 'state',
			'_payment_state'      => 'payment_state',
			'_wp4odoo_partner_id' => 'partner_id',
			'_invoice_currency'   => 'currency_id',
		],
	];

	/**
	 * Portal rendering delegate.
	 *
	 * @var Portal_Manager
	 */
	private Portal_Manager $portal_manager;

	/**
	 * Boot the module: register CPTs, shortcode, AJAX handlers.
	 *
	 * @return void
	 */
	public function boot(): void {
		$partner_service      = new Partner_Service( fn() => $this->client() );
		$this->portal_manager = new Portal_Manager( $this->logger, fn() => $this->get_settings(), $partner_service );

		// Register CPTs.
		add_action( 'init', [ $this, 'register_order_cpt' ] );
		add_action( 'init', [ $this, 'register_invoice_cpt' ] );

		// Customer portal shortcode + AJAX (delegated to Portal_Manager).
		add_shortcode( 'wp4odoo_customer_portal', [ $this->portal_manager, 'render_portal' ] );
		add_action( 'wp_ajax_wp4odoo_portal_data', [ $this->portal_manager, 'handle_portal_data' ] );
	}

	/**
	 * Get default settings for the Sales module.
	 *
	 * @return array
	 */
	public function get_default_settings(): array {
		return [
			'import_products' => true,
			'portal_enabled'  => false,
			'orders_per_page' => 10,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array>
	 */
	public function get_settings_fields(): array {
		return [
			'import_products' => [
				'label'       => __( 'Import products', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull product data from Odoo.', 'wp4odoo' ),
			],
			'portal_enabled'  => [
				'label'       => __( 'Enable customer portal', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable the [wp4odoo_customer_portal] shortcode for logged-in users.', 'wp4odoo' ),
			],
			'orders_per_page' => [
				'label'       => __( 'Orders per page', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'Number of orders/invoices shown per page in the customer portal.', 'wp4odoo' ),
			],
		];
	}

	// ─── CPT Registration ────────────────────────────────────

	/**
	 * Register the wp4odoo_order custom post type.
	 *
	 * @return void
	 */
	public function register_order_cpt(): void {
		CPT_Helper::register(
			'wp4odoo_order',
			[
				'name'               => __( 'Orders', 'wp4odoo' ),
				'singular_name'      => __( 'Order', 'wp4odoo' ),
				'add_new_item'       => __( 'Add New Order', 'wp4odoo' ),
				'edit_item'          => __( 'Edit Order', 'wp4odoo' ),
				'view_item'          => __( 'View Order', 'wp4odoo' ),
				'search_items'       => __( 'Search Orders', 'wp4odoo' ),
				'not_found'          => __( 'No orders found.', 'wp4odoo' ),
				'not_found_in_trash' => __( 'No orders found in Trash.', 'wp4odoo' ),
			]
		);
	}

	/**
	 * Register the wp4odoo_invoice custom post type.
	 *
	 * @return void
	 */
	public function register_invoice_cpt(): void {
		CPT_Helper::register(
			'wp4odoo_invoice',
			[
				'name'               => __( 'Invoices', 'wp4odoo' ),
				'singular_name'      => __( 'Invoice', 'wp4odoo' ),
				'add_new_item'       => __( 'Add New Invoice', 'wp4odoo' ),
				'edit_item'          => __( 'Edit Invoice', 'wp4odoo' ),
				'view_item'          => __( 'View Invoice', 'wp4odoo' ),
				'search_items'       => __( 'Search Invoices', 'wp4odoo' ),
				'not_found'          => __( 'No invoices found.', 'wp4odoo' ),
				'not_found_in_trash' => __( 'No invoices found in Trash.', 'wp4odoo' ),
			]
		);
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
			'order'   => $this->load_order_data( $wp_id ),
			'invoice' => $this->load_invoice_data( $wp_id ),
			default   => $this->unsupported_entity( $entity_type, 'load' ),
		};
	}

	/**
	 * Load order data from an wp4odoo_order CPT post.
	 *
	 * @param int $wp_id Post ID.
	 * @return array
	 */
	private function load_order_data( int $wp_id ): array {
		return CPT_Helper::load( $wp_id, 'wp4odoo_order', self::ORDER_META );
	}

	/**
	 * Load invoice data from an wp4odoo_invoice CPT post.
	 *
	 * @param int $wp_id Post ID.
	 * @return array
	 */
	private function load_invoice_data( int $wp_id ): array {
		return CPT_Helper::load( $wp_id, 'wp4odoo_invoice', self::INVOICE_META );
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
		if ( ! in_array( $entity_type, [ 'order', 'invoice' ], true ) ) {
			$this->unsupported_entity( $entity_type, 'save' );
			return 0;
		}

		return match ( $entity_type ) {
			'order'   => $this->save_order_data( $data, $wp_id ),
			'invoice' => $this->save_invoice_data( $data, $wp_id ),
		};
	}

	/**
	 * Save order data as an wp4odoo_order CPT post.
	 *
	 * @param array $data  Mapped order data.
	 * @param int   $wp_id Existing post ID (0 to create).
	 * @return int Post ID or 0 on failure.
	 */
	private function save_order_data( array $data, int $wp_id = 0 ): int {
		// Resolve currency_id Many2one to code string.
		if ( isset( $data['_order_currency'] ) ) {
			$data['_order_currency'] = Field_Mapper::many2one_to_name( $data['_order_currency'] ) ?? '';
		}
		return CPT_Helper::save( $data, $wp_id, 'wp4odoo_order', self::ORDER_META, __( 'Order', 'wp4odoo' ), $this->logger );
	}

	/**
	 * Save invoice data as an wp4odoo_invoice CPT post.
	 *
	 * @param array $data  Mapped invoice data.
	 * @param int   $wp_id Existing post ID (0 to create).
	 * @return int Post ID or 0 on failure.
	 */
	private function save_invoice_data( array $data, int $wp_id = 0 ): int {
		// Resolve currency_id Many2one to code string.
		if ( isset( $data['_invoice_currency'] ) ) {
			$data['_invoice_currency'] = Field_Mapper::many2one_to_name( $data['_invoice_currency'] ) ?? '';
		}
		return CPT_Helper::save( $data, $wp_id, 'wp4odoo_invoice', self::INVOICE_META, __( 'Invoice', 'wp4odoo' ), $this->logger );
	}

	/**
	 * Delete a WordPress entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( in_array( $entity_type, [ 'order', 'invoice' ], true ) ) {
			$result = wp_delete_post( $wp_id, true );
			return false !== $result && null !== $result;
		}

		$this->unsupported_entity( $entity_type, 'delete' );
		return false;
	}

	/**
	 * Log a warning for an unsupported entity type.
	 *
	 * Product sync is declared in $odoo_models but not yet implemented.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $operation   Operation attempted (load, save, delete).
	 * @return array Empty array (convenience return for load_wp_data match).
	 */
	private function unsupported_entity( string $entity_type, string $operation ): array {
		$this->logger->warning(
			"Sales: {$operation} not implemented for entity type '{$entity_type}'.",
			[
				'entity_type' => $entity_type,
			]
		);
		return [];
	}
}
