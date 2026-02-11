<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

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
 * Domain logic is split into dedicated handlers:
 * - Product_Handler  — product / variant load, save, delete
 * - Order_Handler    — order load, save, status mapping
 * - Variant_Handler  — variant pull from Odoo (product.product → WC variation)
 * - Image_Handler    — product featured image import
 * - Currency_Guard   — currency mismatch detection (static utility)
 *
 * Mutually exclusive with Sales_Module: only one can be active at a time.
 *
 * @package WP4Odoo
 * @since   1.3.0
 */
class WooCommerce_Module extends Module_Base {

	use WooCommerce_Hooks;

	protected string $id   = 'woocommerce';
	protected string $name = 'WooCommerce';

	protected string $exclusive_group = 'commerce';
	protected int $exclusive_priority = 30;

	protected array $odoo_models = [
		'product' => 'product.template',
		'variant' => 'product.product',
		'order'   => 'sale.order',
		'stock'   => 'stock.quant',
		'invoice' => 'account.move',
	];

	protected array $default_mappings = [
		'product' => [
			'name'              => 'name',
			'sku'               => 'default_code',
			'regular_price'     => 'list_price',
			'stock_quantity'    => 'qty_available',
			'weight'            => 'weight',
			'description'       => 'description_sale',
			'_wp4odoo_currency' => 'currency_id',
		],
		'variant' => [
			'sku'               => 'default_code',
			'regular_price'     => 'lst_price',
			'stock_quantity'    => 'qty_available',
			'weight'            => 'weight',
			'display_name'      => 'display_name',
			'_wp4odoo_currency' => 'currency_id',
		],
		'order'   => [
			'total'        => 'amount_total',
			'date_created' => 'date_order',
			'status'       => 'state',
			'partner_id'   => 'partner_id',
		],
		'stock'   => [
			'stock_quantity' => 'quantity',
			'product_id'     => 'product_id',
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
	 * Partner service for customer ↔ res.partner resolution.
	 *
	 * @var Partner_Service
	 */
	private Partner_Service $partner_service;

	/**
	 * Product handler for WC product/variant CRUD.
	 *
	 * @var Product_Handler
	 */
	private Product_Handler $product_handler;

	/**
	 * Order handler for WC order CRUD and status mapping.
	 *
	 * @var Order_Handler
	 */
	private Order_Handler $order_handler;

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

		$settings         = $this->get_settings();
		$convert_currency = ! empty( $settings['convert_currency'] );
		$rate_service     = new Exchange_Rate_Service( $this->logger, fn() => $this->client() );

		$this->partner_service = new Partner_Service( fn() => $this->client(), $this->entity_map() );
		$this->product_handler = new Product_Handler( $this->logger, $rate_service, $convert_currency );
		$this->order_handler   = new Order_Handler( $this->logger, $this->partner_service );
		$this->variant_handler = new Variant_Handler( $this->logger, fn() => $this->client(), $this->entity_map(), $rate_service, $convert_currency );
		$this->image_handler   = new Image_Handler( $this->logger );

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
		add_action( 'init', [ Invoice_Helper::class, 'register_cpt' ] );
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
			'convert_currency'    => false,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_products'       => [
				'label'       => __( 'Sync products', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Synchronize WooCommerce products with Odoo.', 'wp4odoo' ),
			],
			'sync_orders'         => [
				'label'       => __( 'Sync orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Synchronize WooCommerce orders with Odoo.', 'wp4odoo' ),
			],
			'sync_stock'          => [
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
			'convert_currency'    => [
				'label'       => __( 'Convert currency', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Convert prices using Odoo exchange rates when the product currency differs from the shop currency.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for WooCommerce.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'WooCommerce' ), 'WooCommerce' );
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
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		// Variants: delegate directly to Variant_Handler.
		if ( 'variant' === $entity_type ) {
			return $this->pull_variant( $odoo_id, $wp_id, $payload );
		}

		// Standard pull for all other entity types.
		$result = parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );

		// After product template pull: import image + enqueue variant pulls.
		if ( $result->succeeded() && 'product' === $entity_type && 'delete' !== $action ) {
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
	 * @param int   $odoo_id Odoo product.product ID.
	 * @param int   $wp_id   Existing WC variation ID (0 if unknown).
	 * @param array $payload Queue payload (may contain parent_wp_id, template_odoo_id).
	 * @return \WP4Odoo\Sync_Result
	 */
	private function pull_variant( int $odoo_id, int $wp_id, array $payload ): \WP4Odoo\Sync_Result {
		self::mark_importing();

		try {
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
				$this->logger->warning(
					'Cannot pull variant: parent product not mapped.',
					[
						'variant_odoo_id'  => $odoo_id,
						'template_odoo_id' => $template_odoo_id,
					]
				);
				return \WP4Odoo\Sync_Result::failure( 'Cannot pull variant: parent product not mapped.', \WP4Odoo\Error_Type::Permanent );
			}

			$ok = $this->variant_handler->pull_variants( $template_odoo_id, $parent_wp_id );
			return $ok
				? \WP4Odoo\Sync_Result::success( $parent_wp_id )
				: \WP4Odoo\Sync_Result::failure( 'Variant pull failed.', \WP4Odoo\Error_Type::Transient );
		} finally {
			self::clear_importing();
		}
	}

	/**
	 * Enqueue variant pulls for a product template.
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

		$this->logger->info(
			'Enqueued variant pulls for template.',
			[
				'template_odoo_id' => $template_odoo_id,
				'variant_count'    => count( $variant_ids ),
			]
		);
	}

	// ─── Data Loading (delegates to handlers) ───────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'product' => $this->product_handler->load( $wp_id ),
			'variant' => $this->product_handler->load_variant( $wp_id ),
			'order'   => $this->order_handler->load( $wp_id ),
			'invoice' => $this->load_invoice_data( $wp_id ),
			default   => $this->unsupported_entity( $entity_type, 'load' ),
		};
	}

	/**
	 * Load invoice data from the wp4odoo_invoice CPT.
	 *
	 * @param int $wp_id Post ID.
	 * @return array
	 */
	private function load_invoice_data( int $wp_id ): array {
		return Invoice_Helper::load( $wp_id );
	}

	// ─── Data Saving (delegates to handlers) ────────────────

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
			'product' => $this->product_handler->save( $data, $wp_id ),
			'variant' => $this->product_handler->save_variant( $data, $wp_id ),
			'order'   => $this->order_handler->save( $data, $wp_id ),
			'stock'   => $this->save_stock_data( $data ),
			'invoice' => $this->save_invoice_data( $data, $wp_id ),
			default   => $this->unsupported_entity_save( $entity_type ),
		};
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
			$this->logger->warning(
				'Stock update: no WC product mapped for Odoo product.',
				[
					'odoo_product_id' => $odoo_product_id,
				]
			);
			return 0;
		}

		$quantity = (int) ( $data['stock_quantity'] ?? 0 );
		wc_update_product_stock( $wp_product_id, $quantity );

		$this->logger->info(
			'Updated WC product stock.',
			[
				'wp_product_id' => $wp_product_id,
				'quantity'      => $quantity,
			]
		);

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
		return Invoice_Helper::save( $data, $wp_id, $this->logger );
	}

	// ─── Delete (delegates to handler) ──────────────────────

	/**
	 * Delete a WordPress entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'product' === $entity_type || 'variant' === $entity_type ) {
			return $this->product_handler->delete( $wp_id );
		}

		if ( 'invoice' === $entity_type ) {
			return $this->delete_wp_post( $wp_id );
		}

		// Orders and stock are not deleted via sync.
		$this->log_unsupported_entity( $entity_type, 'delete' );
		return false;
	}

	// ─── Helpers ─────────────────────────────────────────────

	/**
	 * Log a warning for an unsupported entity type (load context).
	 *
	 * @param string $entity_type Entity type.
	 * @param string $operation   Operation attempted.
	 * @return array Empty array.
	 */
	private function unsupported_entity( string $entity_type, string $operation ): array {
		$this->log_unsupported_entity( $entity_type, $operation );
		return [];
	}

	/**
	 * Log a warning for an unsupported entity type (save context).
	 *
	 * @param string $entity_type Entity type.
	 * @return int Always 0.
	 */
	private function unsupported_entity_save( string $entity_type ): int {
		$this->log_unsupported_entity( $entity_type, 'save' );
		return 0;
	}
}
