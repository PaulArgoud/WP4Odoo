<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\CPT_Helper;
use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Field_Mapper;
use WP4Odoo\Module_Base;
use WP4Odoo\Partner_Service;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Module — bidirectional product/order/stock/invoice sync.
 *
 * Uses WooCommerce native post types for products and orders.
 * Invoices use a custom post type (WC has no native invoice type).
 * Customer (res.partner) management is delegated to Partner_Service.
 *
 * Mutually exclusive with Sales_Module: only one can be active at a time.
 *
 * @package WP4Odoo
 * @since   1.3.0
 */
class WooCommerce_Module extends Module_Base {

	/**
	 * Invoice meta fields: data key => post meta key.
	 */
	private const INVOICE_META = [
		'_invoice_total'       => '_invoice_total',
		'_invoice_date'        => '_invoice_date',
		'_invoice_state'       => '_invoice_state',
		'_payment_state'       => '_payment_state',
		'_wp4odoo_partner_id'  => '_wp4odoo_partner_id',
	];

	protected string $id   = 'woocommerce';
	protected string $name = 'WooCommerce';

	protected array $odoo_models = [
		'product' => 'product.template',
		'variant' => 'product.product',
		'order'   => 'sale.order',
		'stock'   => 'stock.quant',
		'invoice' => 'account.move',
	];

	protected array $default_mappings = [
		'product' => [
			'name'          => 'name',
			'sku'           => 'default_code',
			'regular_price' => 'list_price',
			'stock_quantity' => 'qty_available',
			'weight'        => 'weight',
			'description'   => 'description_sale',
		],
		'variant' => [
			'sku'            => 'default_code',
			'regular_price'  => 'lst_price',
			'stock_quantity' => 'qty_available',
			'weight'         => 'weight',
			'display_name'   => 'display_name',
		],
		'order' => [
			'total'      => 'amount_total',
			'date_created' => 'date_order',
			'status'     => 'state',
			'partner_id' => 'partner_id',
		],
		'stock' => [
			'stock_quantity' => 'quantity',
			'product_id'     => 'product_id',
		],
		'invoice' => [
			'post_title'           => 'name',
			'_invoice_total'       => 'amount_total',
			'_invoice_date'        => 'invoice_date',
			'_invoice_state'       => 'state',
			'_payment_state'       => 'payment_state',
			'_wp4odoo_partner_id'  => 'partner_id',
		],
	];

	/**
	 * Partner service for customer ↔ res.partner resolution.
	 *
	 * @var Partner_Service
	 */
	private Partner_Service $partner_service;

	/**
	 * Variant handler for product.product → WC variation import.
	 *
	 * @var Variant_Handler
	 */
	private Variant_Handler $variant_handler;

	/**
	 * Image handler for product featured image import from Odoo.
	 *
	 * @var Image_Handler
	 */
	private Image_Handler $image_handler;

	/**
	 * Raw Odoo data captured during pull for post-save image processing.
	 *
	 * @var array<string, mixed>
	 */
	private array $last_odoo_data = [];

	/**
	 * Boot the module: register WC hooks, invoice CPT.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->logger->warning( 'WooCommerce module enabled but WooCommerce is not active.' );
			return;
		}

		$this->partner_service  = new Partner_Service( fn() => $this->client() );
		$this->variant_handler = new Variant_Handler( $this->logger, fn() => $this->client() );
		$this->image_handler   = new Image_Handler( $this->logger );
		$settings = $this->get_settings();

		// Capture raw Odoo data during product pull for image processing.
		add_filter( "wp4odoo_map_from_odoo_{$this->id}_product", [ $this, 'capture_odoo_data' ], 1, 3 );

		// Products.
		if ( ! empty( $settings['sync_products'] ) ) {
			add_action( 'woocommerce_update_product', [ $this, 'on_product_save' ] );
			add_action( 'before_delete_post', [ $this, 'on_product_delete' ] );
		}

		// Orders.
		if ( ! empty( $settings['sync_orders'] ) ) {
			add_action( 'woocommerce_new_order', [ $this, 'on_new_order' ] );
			add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 3 );
		}

		// Stock: pull-only (Odoo → WC), no WC hooks needed.

		// Invoices: CPT (WC has no native invoice type).
		add_action( 'init', [ $this, 'register_invoice_cpt' ] );
	}

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	public function get_default_settings(): array {
		return [
			'sync_products'       => true,
			'sync_orders'         => true,
			'sync_stock'          => true,
			'sync_product_images' => true,
			'auto_confirm_orders' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_products' => [
				'label'       => __( 'Sync products', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Synchronize WooCommerce products with Odoo.', 'wp4odoo' ),
			],
			'sync_orders' => [
				'label'       => __( 'Sync orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Synchronize WooCommerce orders with Odoo.', 'wp4odoo' ),
			],
			'sync_stock' => [
				'label'       => __( 'Sync stock', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull stock levels from Odoo into WooCommerce products.', 'wp4odoo' ),
			],
			'sync_product_images' => [
				'label'       => __( 'Sync product images', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull product featured images from Odoo.', 'wp4odoo' ),
			],
			'auto_confirm_orders' => [
				'label'       => __( 'Auto-confirm orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm orders in Odoo when created from WooCommerce.', 'wp4odoo' ),
			],
		];
	}

	// ─── Pull Override (variants) ────────────────────────────

	/**
	 * Pull an Odoo entity into WordPress.
	 *
	 * Extends the base pull to handle variant entities and auto-enqueue
	 * variant pulls after a product template is successfully pulled.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo entity ID.
	 * @param int    $wp_id       WordPress ID (0 if creating).
	 * @param array  $payload     Additional data from the queue.
	 * @return bool True on success.
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): bool {
		// Variants: delegate directly to Variant_Handler.
		if ( 'variant' === $entity_type ) {
			return $this->pull_variant( $odoo_id, $wp_id, $payload );
		}

		// Standard pull for all other entity types.
		$result = parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );

		// After product template pull: import image + enqueue variant pulls.
		if ( $result && 'product' === $entity_type && 'delete' !== $action ) {
			$pulled_wp_id = $wp_id ?: ( $this->get_wp_mapping( 'product', $odoo_id ) ?? 0 );
			if ( $pulled_wp_id > 0 ) {
				$this->maybe_pull_product_image( $pulled_wp_id );
				$this->enqueue_variants_for_template( $odoo_id, $pulled_wp_id );
			}
			$this->last_odoo_data = [];
		}

		return $result;
	}

	/**
	 * Capture raw Odoo data during product pull for post-save image processing.
	 *
	 * Registered as a filter on wp4odoo_map_from_odoo_woocommerce_product
	 * at priority 1 so it runs before any user filters.
	 *
	 * @param array  $wp_data     The mapped WordPress data.
	 * @param array  $odoo_data   The raw Odoo record data.
	 * @param string $entity_type The entity type.
	 * @return array Unmodified WordPress data.
	 */
	public function capture_odoo_data( array $wp_data, array $odoo_data, string $entity_type ): array {
		$this->last_odoo_data = $odoo_data;
		return $wp_data;
	}

	/**
	 * Import the featured image for a product if image sync is enabled.
	 *
	 * @param int $wp_product_id WC product ID.
	 * @return void
	 */
	private function maybe_pull_product_image( int $wp_product_id ): void {
		$settings = $this->get_settings();

		if ( empty( $settings['sync_product_images'] ) ) {
			return;
		}

		$image_data   = $this->last_odoo_data['image_1920'] ?? false;
		$product_name = $this->last_odoo_data['name'] ?? '';

		$this->image_handler->import_featured_image( $wp_product_id, $image_data, $product_name );
	}

	/**
	 * Pull a single product.product variant from Odoo.
	 *
	 * Reads variant data, finds the parent WC product via the template
	 * mapping, and delegates to Variant_Handler.
	 *
	 * @param int   $odoo_id Odoo product.product ID.
	 * @param int   $wp_id   Existing WC variation ID (0 if unknown).
	 * @param array $payload Queue payload (may contain parent_wp_id, template_odoo_id).
	 * @return bool True on success.
	 */
	private function pull_variant( int $odoo_id, int $wp_id, array $payload ): bool {
		if ( ! defined( 'WP4ODOO_IMPORTING' ) ) {
			define( 'WP4ODOO_IMPORTING', true );
		}

		$parent_wp_id     = (int) ( $payload['parent_wp_id'] ?? 0 );
		$template_odoo_id = (int) ( $payload['template_odoo_id'] ?? 0 );

		// If parent not in payload, read the variant to find the template, then look up mapping.
		if ( 0 === $parent_wp_id && 0 === $template_odoo_id ) {
			$records = $this->client()->read( 'product.product', [ $odoo_id ], [ 'product_tmpl_id' ] );
			if ( ! empty( $records[0]['product_tmpl_id'] ) ) {
				$template_odoo_id = is_array( $records[0]['product_tmpl_id'] )
					? (int) $records[0]['product_tmpl_id'][0]
					: (int) $records[0]['product_tmpl_id'];
			}
		}

		if ( 0 === $parent_wp_id && $template_odoo_id > 0 ) {
			$parent_wp_id = $this->get_wp_mapping( 'product', $template_odoo_id ) ?? 0;
		}

		if ( 0 === $parent_wp_id ) {
			$this->logger->warning( 'Cannot pull variant: parent product not mapped.', [
				'variant_odoo_id'  => $odoo_id,
				'template_odoo_id' => $template_odoo_id,
			] );
			return false;
		}

		return $this->variant_handler->pull_variants( $template_odoo_id, $parent_wp_id );
	}

	/**
	 * Enqueue variant pulls for a product template.
	 *
	 * Searches for product.product records linked to the template.
	 * If more than one variant exists, queues a pull for each.
	 *
	 * @param int $template_odoo_id Odoo product.template ID.
	 * @param int $wp_parent_id     WC parent product ID.
	 * @return void
	 */
	private function enqueue_variants_for_template( int $template_odoo_id, int $wp_parent_id ): void {
		$variant_ids = $this->client()->search(
			'product.product',
			[ [ 'product_tmpl_id', '=', $template_odoo_id ] ]
		);

		// Single variant or none: simple product, nothing to enqueue.
		if ( count( $variant_ids ) <= 1 ) {
			return;
		}

		foreach ( $variant_ids as $variant_odoo_id ) {
			Queue_Manager::pull(
				'woocommerce',
				'variant',
				'update',
				(int) $variant_odoo_id,
				0,
				[
					'parent_wp_id'     => $wp_parent_id,
					'template_odoo_id' => $template_odoo_id,
				]
			);
		}

		$this->logger->info( 'Enqueued variant pulls for template.', [
			'template_odoo_id' => $template_odoo_id,
			'variant_count'    => count( $variant_ids ),
		] );
	}

	// ─── WC Hook Callbacks (push) ────────────────────────────

	/**
	 * Handle product save in WooCommerce.
	 *
	 * @param int $product_id WC product ID.
	 * @return void
	 */
	public function on_product_save( int $product_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'product', $product_id );
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'woocommerce', 'product', $action, $product_id, $odoo_id ?? 0 );
	}

	/**
	 * Handle product deletion.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_product_delete( int $post_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'product', $post_id );
		if ( $odoo_id ) {
			Queue_Manager::push( 'woocommerce', 'product', 'delete', $post_id, $odoo_id );
		}
	}

	/**
	 * Handle new WooCommerce order.
	 *
	 * @param int $order_id WC order ID.
	 * @return void
	 */
	public function on_new_order( int $order_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		// Link customer to Odoo partner via Partner_Service.
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$email = $order->get_billing_email();
			$name  = $order->get_formatted_billing_full_name();
			if ( $email ) {
				$user_id = $order->get_customer_id();
				$this->partner_service->get_or_create( $email, [ 'name' => $name ], $user_id );
			}
		}

		Queue_Manager::push( 'woocommerce', 'order', 'create', $order_id );
	}

	/**
	 * Handle order status change.
	 *
	 * @param int    $order_id  WC order ID.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @return void
	 */
	public function on_order_status_changed( int $order_id, string $old_status, string $new_status ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'order', $order_id ) ?? 0;
		Queue_Manager::push( 'woocommerce', 'order', 'update', $order_id, $odoo_id );
	}

	// ─── Invoice CPT ─────────────────────────────────────────

	/**
	 * Register the wp4odoo_invoice custom post type.
	 *
	 * @return void
	 */
	public function register_invoice_cpt(): void {
		CPT_Helper::register( 'wp4odoo_invoice', [
			'name'               => __( 'Invoices', 'wp4odoo' ),
			'singular_name'      => __( 'Invoice', 'wp4odoo' ),
			'add_new_item'       => __( 'Add New Invoice', 'wp4odoo' ),
			'edit_item'          => __( 'Edit Invoice', 'wp4odoo' ),
			'view_item'          => __( 'View Invoice', 'wp4odoo' ),
			'search_items'       => __( 'Search Invoices', 'wp4odoo' ),
			'not_found'          => __( 'No invoices found.', 'wp4odoo' ),
			'not_found_in_trash' => __( 'No invoices found in Trash.', 'wp4odoo' ),
		] );
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
			'product' => $this->load_product_data( $wp_id ),
			'variant' => $this->load_variant_data( $wp_id ),
			'order'   => $this->load_order_data( $wp_id ),
			'invoice' => $this->load_invoice_data( $wp_id ),
			default   => $this->unsupported_entity( $entity_type, 'load' ),
		};
	}

	/**
	 * Load WooCommerce product data.
	 *
	 * @param int $wp_id Product ID.
	 * @return array
	 */
	private function load_product_data( int $wp_id ): array {
		$product = wc_get_product( $wp_id );
		if ( ! $product ) {
			return [];
		}

		return [
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'regular_price'  => $product->get_regular_price(),
			'stock_quantity' => $product->get_stock_quantity(),
			'weight'         => $product->get_weight(),
			'description'    => $product->get_description(),
		];
	}

	/**
	 * Load WooCommerce variation data.
	 *
	 * @param int $wp_id Variation ID.
	 * @return array
	 */
	private function load_variant_data( int $wp_id ): array {
		$product = wc_get_product( $wp_id );
		if ( ! $product ) {
			return [];
		}

		return [
			'sku'            => $product->get_sku(),
			'regular_price'  => $product->get_regular_price(),
			'stock_quantity' => $product->get_stock_quantity(),
			'weight'         => $product->get_weight(),
			'display_name'   => $product->get_name(),
		];
	}

	/**
	 * Load WooCommerce order data.
	 *
	 * @param int $wp_id Order ID.
	 * @return array
	 */
	private function load_order_data( int $wp_id ): array {
		$order = wc_get_order( $wp_id );
		if ( ! $order ) {
			return [];
		}

		// Resolve partner_id via Partner_Service.
		$partner_id = null;
		$email      = $order->get_billing_email();
		if ( $email ) {
			$user_id    = $order->get_customer_id();
			$partner_id = $this->partner_service->get_or_create(
				$email,
				[ 'name' => $order->get_formatted_billing_full_name() ],
				$user_id
			);
		}

		return [
			'total'        => $order->get_total(),
			'date_created' => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '',
			'status'       => $order->get_status(),
			'partner_id'   => $partner_id,
		];
	}

	/**
	 * Load invoice data from the wp4odoo_invoice CPT.
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
		return match ( $entity_type ) {
			'product' => $this->save_product_data( $data, $wp_id ),
			'variant' => $this->save_variant_data( $data, $wp_id ),
			'order'   => $this->save_order_data( $data, $wp_id ),
			'stock'   => $this->save_stock_data( $data ),
			'invoice' => $this->save_invoice_data( $data, $wp_id ),
			default   => $this->unsupported_entity_save( $entity_type ),
		};
	}

	/**
	 * Save product data to WooCommerce.
	 *
	 * @param array $data  Mapped product data.
	 * @param int   $wp_id Existing product ID (0 to create).
	 * @return int Product ID or 0 on failure.
	 */
	private function save_product_data( array $data, int $wp_id = 0 ): int {
		if ( $wp_id > 0 ) {
			$product = wc_get_product( $wp_id );
		} else {
			$product = new \WC_Product();
		}

		if ( ! $product ) {
			$this->logger->error( 'Failed to get or create WC product.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		if ( isset( $data['name'] ) ) {
			$product->set_name( $data['name'] );
		}
		if ( isset( $data['sku'] ) ) {
			$product->set_sku( $data['sku'] );
		}
		if ( isset( $data['regular_price'] ) ) {
			$product->set_regular_price( (string) $data['regular_price'] );
		}
		if ( isset( $data['weight'] ) ) {
			$product->set_weight( (string) $data['weight'] );
		}
		if ( isset( $data['description'] ) ) {
			$product->set_description( $data['description'] );
		}
		if ( isset( $data['stock_quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $data['stock_quantity'] );
		}

		$saved_id = $product->save();

		return $saved_id > 0 ? $saved_id : 0;
	}

	/**
	 * Save variant (variation) data to WooCommerce.
	 *
	 * Delegates to the Variant_Handler for creating/updating variations.
	 *
	 * @param array $data  Mapped variant data.
	 * @param int   $wp_id Existing variation ID (0 to create).
	 * @return int Variation ID or 0 on failure.
	 */
	private function save_variant_data( array $data, int $wp_id = 0 ): int {
		// Variant saving is handled by pull_variant() → Variant_Handler.
		// This method covers the base class save_wp_data path.
		if ( $wp_id > 0 ) {
			$variation = wc_get_product( $wp_id );
			if ( ! $variation ) {
				return 0;
			}

			if ( isset( $data['sku'] ) && '' !== $data['sku'] ) {
				$variation->set_sku( $data['sku'] );
			}
			if ( isset( $data['regular_price'] ) ) {
				$variation->set_regular_price( (string) $data['regular_price'] );
			}
			if ( isset( $data['stock_quantity'] ) ) {
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( (int) $data['stock_quantity'] );
			}
			if ( isset( $data['weight'] ) && $data['weight'] ) {
				$variation->set_weight( (string) $data['weight'] );
			}

			$saved_id = $variation->save();
			return $saved_id > 0 ? $saved_id : 0;
		}

		// Cannot create variation without parent context; handled by Variant_Handler.
		$this->logger->warning( 'Variant creation without parent context is not supported via save_wp_data.' );
		return 0;
	}

	/**
	 * Save order data to WooCommerce.
	 *
	 * Primarily used for status updates from Odoo.
	 *
	 * @param array $data  Mapped order data.
	 * @param int   $wp_id Existing order ID (0 to skip — order creation from Odoo is not supported).
	 * @return int Order ID or 0 on failure.
	 */
	private function save_order_data( array $data, int $wp_id = 0 ): int {
		if ( 0 === $wp_id ) {
			$this->logger->warning( 'Order creation from Odoo is not supported. Use WooCommerce to create orders.' );
			return 0;
		}

		$order = wc_get_order( $wp_id );
		if ( ! $order ) {
			$this->logger->error( 'WC order not found.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		if ( isset( $data['status'] ) ) {
			$order->set_status( $this->map_odoo_status_to_wc( $data['status'] ) );
		}

		$order->save();

		return $wp_id;
	}

	/**
	 * Update WooCommerce product stock from Odoo stock.quant data.
	 *
	 * @param array $data Mapped stock data (must include product_id).
	 * @return int Product ID or 0 on failure.
	 */
	private function save_stock_data( array $data ): int {
		// Resolve the WC product via entity_map (product_id is the Odoo Many2one).
		$odoo_product_id = is_array( $data['product_id'] ?? null )
			? Field_Mapper::many2one_to_id( $data['product_id'] )
			: ( (int) ( $data['product_id'] ?? 0 ) );

		if ( ! $odoo_product_id ) {
			$this->logger->warning( 'Stock update: no product_id in data.' );
			return 0;
		}

		$wp_product_id = $this->get_wp_mapping( 'product', $odoo_product_id );
		if ( ! $wp_product_id ) {
			// stock.quant references product.product (variant), not product.template.
			$wp_product_id = $this->get_wp_mapping( 'variant', $odoo_product_id );
		}
		if ( ! $wp_product_id ) {
			$this->logger->warning( 'Stock update: no WC product mapped for Odoo product.', [
				'odoo_product_id' => $odoo_product_id,
			] );
			return 0;
		}

		$quantity = (int) ( $data['stock_quantity'] ?? 0 );
		wc_update_product_stock( $wp_product_id, $quantity );

		$this->logger->info( 'Updated WC product stock.', [
			'wp_product_id' => $wp_product_id,
			'quantity'       => $quantity,
		] );

		return $wp_product_id;
	}

	/**
	 * Save invoice data as a wp4odoo_invoice CPT post.
	 *
	 * @param array $data  Mapped invoice data.
	 * @param int   $wp_id Existing post ID (0 to create).
	 * @return int Post ID or 0 on failure.
	 */
	private function save_invoice_data( array $data, int $wp_id = 0 ): int {
		return CPT_Helper::save( $data, $wp_id, 'wp4odoo_invoice', self::INVOICE_META, __( 'Invoice', 'wp4odoo' ), $this->logger );
	}

	// ─── Delete ──────────────────────────────────────────────

	/**
	 * Delete a WordPress entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'product' === $entity_type || 'variant' === $entity_type ) {
			$product = wc_get_product( $wp_id );
			if ( $product ) {
				$product->delete( true );
				return true;
			}
			return false;
		}

		if ( 'invoice' === $entity_type ) {
			$result = wp_delete_post( $wp_id, true );
			return false !== $result && null !== $result;
		}

		// Orders and stock are not deleted via sync.
		$this->logger->warning( "WooCommerce: delete not supported for entity type '{$entity_type}'.", [
			'entity_type' => $entity_type,
		] );
		return false;
	}

	// ─── Helpers ─────────────────────────────────────────────

	/**
	 * Map an Odoo sale.order state to a WooCommerce order status.
	 *
	 * @param string $odoo_state Odoo state value.
	 * @return string WC status (without 'wc-' prefix).
	 */
	private function map_odoo_status_to_wc( string $odoo_state ): string {
		return match ( $odoo_state ) {
			'draft'  => 'pending',
			'sent'   => 'on-hold',
			'sale'   => 'processing',
			'done'   => 'completed',
			'cancel' => 'cancelled',
			default  => 'on-hold',
		};
	}

	/**
	 * Log a warning for an unsupported entity type (load context).
	 *
	 * @param string $entity_type Entity type.
	 * @param string $operation   Operation attempted.
	 * @return array Empty array.
	 */
	private function unsupported_entity( string $entity_type, string $operation ): array {
		$this->logger->warning( "WooCommerce: {$operation} not implemented for entity type '{$entity_type}'.", [
			'entity_type' => $entity_type,
		] );
		return [];
	}

	/**
	 * Log a warning for an unsupported entity type (save context).
	 *
	 * @param string $entity_type Entity type.
	 * @return int Always 0.
	 */
	private function unsupported_entity_save( string $entity_type ): int {
		$this->logger->warning( "WooCommerce: save not implemented for entity type '{$entity_type}'.", [
			'entity_type' => $entity_type,
		] );
		return 0;
	}
}
