<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Error_Type;
use WP4Odoo\Field_Mapper;
use WP4Odoo\Module_Base;
use WP4Odoo\Partner_Service;
use WP4Odoo\Sync_Result;

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
 * - Product_Handler   — product / variant load, save, delete
 * - Order_Handler     — order load, save, status mapping
 * - Variant_Handler   — variant pull from Odoo (product.product → WC variation)
 * - Image_Handler     — product featured image import
 * - Pricelist_Handler — pricelist price pull (product.pricelist → WC sale_price)
 * - Shipment_Handler  — shipment tracking pull (stock.picking → WC order meta)
 * - Currency_Guard    — currency mismatch detection (static utility)
 *
 * Mutually exclusive with Sales_Module: only one can be active at a time.
 *
 * @package WP4Odoo
 * @since   1.3.0
 */
class WooCommerce_Module extends Module_Base {

	use WooCommerce_Hooks;

	protected const PLUGIN_MIN_VERSION  = '7.0';
	protected const PLUGIN_TESTED_UP_TO = '10.5';

	protected string $exclusive_group = 'ecommerce';
	protected int $exclusive_priority = 30;

	protected array $odoo_models = [
		'product'   => 'product.template',
		'variant'   => 'product.product',
		'order'     => 'sale.order',
		'stock'     => 'stock.quant',
		'invoice'   => 'account.move',
		'pricelist' => 'product.pricelist',
		'shipment'  => 'stock.picking',
		'category'  => 'product.category',
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
			'_wp4odoo_categ_id' => 'categ_id',
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
	 * Pricelist handler for pricelist price import from Odoo.
	 *
	 * @var Pricelist_Handler
	 */
	private Pricelist_Handler $pricelist_handler;

	/**
	 * Shipment handler for tracking data import from Odoo.
	 *
	 * @var Shipment_Handler
	 */
	private Shipment_Handler $shipment_handler;

	/**
	 * Pull orchestration coordinator.
	 *
	 * @var WC_Pull_Coordinator
	 */
	private WC_Pull_Coordinator $pull_coordinator;

	/**
	 * Constructor.
	 *
	 * Handlers are initialized here (not in boot()) because Sync_Engine
	 * can call push_to_odoo / pull_from_odoo on non-booted modules for
	 * residual queue jobs.
	 *
	 * @param \Closure                         $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository   $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository     $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'woocommerce', 'WooCommerce', $client_provider, $entity_map, $settings );

		$module_settings  = $this->get_settings();
		$convert_currency = ! empty( $module_settings['convert_currency'] );
		$rate_service     = new Exchange_Rate_Service( $this->logger, fn() => $this->client() );

		$this->partner_service   = new Partner_Service( fn() => $this->client(), $this->entity_map() );
		$this->product_handler   = new Product_Handler( $this->logger, $rate_service, $convert_currency );
		$this->order_handler     = new Order_Handler( $this->logger, $this->partner_service );
		$this->variant_handler   = new Variant_Handler( $this->logger, fn() => $this->client(), $this->entity_map(), $rate_service, $convert_currency );
		$this->image_handler     = new Image_Handler( $this->logger );
		$this->pricelist_handler = new Pricelist_Handler( $this->logger, fn() => $this->client(), (int) ( $module_settings['pricelist_id'] ?? 0 ), $rate_service, $convert_currency );
		$this->shipment_handler  = new Shipment_Handler( $this->logger, fn() => $this->client() );

		$this->pull_coordinator = new WC_Pull_Coordinator(
			$this->logger,
			fn() => $this->get_settings(),
			fn() => $this->client(),
			fn( string $type, int $odoo_id ) => $this->get_wp_mapping( $type, $odoo_id ),
			$this->variant_handler,
			$this->image_handler,
			$this->pricelist_handler,
			$this->shipment_handler,
			fn() => $this->translation_service()
		);
	}

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

		$settings = $this->get_settings();

		// Capture raw Odoo data during product pull for image processing.
		add_filter( "wp4odoo_map_from_odoo_{$this->id}_product", [ $this->pull_coordinator, 'capture_odoo_data' ], 1, 3 );

		// Products.
		if ( ! empty( $settings['sync_products'] ) ) {
			add_action( 'woocommerce_update_product', $this->safe_callback( [ $this, 'on_product_save' ] ) );
			add_action( 'before_delete_post', $this->safe_callback( [ $this, 'on_product_delete' ] ) );
		}

		// Orders.
		if ( ! empty( $settings['sync_orders'] ) ) {
			add_action( 'woocommerce_new_order', $this->safe_callback( [ $this, 'on_new_order' ] ) );
			add_action( 'woocommerce_order_status_changed', $this->safe_callback( [ $this, 'on_order_status_changed' ] ), 10, 3 );
		}

		// Stock: pull-only (Odoo → WC), no WC hooks needed.

		// Invoices: CPT (WC has no native invoice type).
		add_action( 'init', [ Invoice_Helper::class, 'register_cpt' ] );

		// Translation flush: deferred batch operation after queue processing.
		add_action( 'wp4odoo_batch_processed', [ $this, 'on_batch_processed' ] );
	}

	// ─── Translation push override ─────────────────────────

	/**
	 * Push entity to Odoo, intercepting translation payloads.
	 *
	 * When a queue job carries a _translate payload flag, the translated
	 * product fields are pushed to the existing Odoo record using
	 * Translation_Service (context-based write for Odoo 16+ or
	 * ir.translation CRUD for Odoo 14-15).
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo entity ID.
	 * @param array  $payload     Queue payload.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		if ( 'product' === $entity_type && ! empty( $payload['_translate'] ) ) {
			return $this->push_product_translation( $odoo_id, $payload );
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	/**
	 * Push translated product fields to an existing Odoo record.
	 *
	 * @param int   $odoo_id Odoo product ID.
	 * @param array $payload Queue payload with _lang and _translated_id.
	 * @return Sync_Result
	 */
	private function push_product_translation( int $odoo_id, array $payload ): Sync_Result {
		if ( $odoo_id <= 0 ) {
			return Sync_Result::failure( __( 'No Odoo mapping for translation.', 'wp4odoo' ), Error_Type::Permanent );
		}

		$lang          = $payload['_lang'] ?? '';
		$translated_id = (int) ( $payload['_translated_id'] ?? 0 );

		if ( '' === $lang || $translated_id <= 0 ) {
			return Sync_Result::failure( __( 'Missing translation parameters.', 'wp4odoo' ), Error_Type::Permanent );
		}

		$product = wc_get_product( $translated_id );
		if ( ! $product ) {
			return Sync_Result::failure( __( 'Translated product not found.', 'wp4odoo' ), Error_Type::Permanent );
		}

		$values = [
			'name'             => $product->get_name(),
			'description_sale' => $product->get_description(),
		];

		$this->translation_service()->push_translation(
			$this->get_odoo_model( 'product' ),
			$odoo_id,
			$values,
			$lang
		);

		$this->logger->info(
			'Pushed product translation.',
			[
				'odoo_id' => $odoo_id,
				'lang'    => $lang,
				'wp_id'   => $translated_id,
			]
		);

		return Sync_Result::success( $odoo_id );
	}

	/**
	 * Handle post-batch translation flush.
	 *
	 * Wraps the flush in anti-loop guards so that WP save hooks
	 * triggered by creating translation posts are silenced.
	 *
	 * @return void
	 */
	public function on_batch_processed(): void {
		$this->mark_importing();
		try {
			$this->pull_coordinator->flush_translations();
		} finally {
			$this->clear_importing();
		}
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
			'sync_pricelists'     => false,
			'sync_shipments'      => false,
			'auto_confirm_orders' => true,
			'convert_currency'    => false,
			'pricelist_id'        => 0,
			'sync_translations'   => [],
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
			'sync_pricelists'     => [
				'label'       => __( 'Sync pricelist prices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull pricelist prices from Odoo and set as WooCommerce sale prices.', 'wp4odoo' ),
			],
			'pricelist_id'        => [
				'label'       => __( 'Pricelist ID', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'The Odoo pricelist ID to use for pricing (Sales > Configuration > Pricelists).', 'wp4odoo' ),
			],
			'sync_shipments'      => [
				'label'       => __( 'Sync shipments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull shipment tracking from Odoo into WooCommerce orders (AST compatible).', 'wp4odoo' ),
			],
			'convert_currency'    => [
				'label'       => __( 'Convert currency', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Convert prices using Odoo exchange rates when the product currency differs from the shop currency.', 'wp4odoo' ),
			],
			'sync_translations'   => [
				'label'       => __( 'Sync translations', 'wp4odoo' ),
				'type'        => 'languages',
				'description' => __( 'Select languages to pull product translations from Odoo (requires WPML or Polylang).', 'wp4odoo' ),
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

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WC_VERSION' ) ? WC_VERSION : '';
	}

	// ─── Pull Override ───────────────────────────────────────

	/**
	 * Pull an Odoo entity into WordPress.
	 *
	 * Extends the base pull to handle variant and shipment entities
	 * (delegated to WC_Pull_Coordinator) and triggers post-pull
	 * actions (image, variants, pricelist, shipments).
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo entity ID.
	 * @param int    $wp_id       WordPress ID (0 if creating).
	 * @param array  $payload     Additional data from the queue.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		// Variants: delegate to pull coordinator with anti-loop guard.
		if ( 'variant' === $entity_type ) {
			$this->mark_importing();
			try {
				return $this->pull_coordinator->pull_variant( $odoo_id, $wp_id, $payload );
			} finally {
				$this->clear_importing();
			}
		}

		// Shipments: delegate to pull coordinator.
		if ( 'shipment' === $entity_type ) {
			return $this->pull_coordinator->pull_shipment_for_picking( $odoo_id );
		}

		// Standard pull for all other entity types.
		$result = parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );

		// After product template pull: image + variants + pricelist.
		if ( $result->succeeded() && 'product' === $entity_type && 'delete' !== $action ) {
			$pulled_wp_id = $wp_id ?: ( $this->get_wp_mapping( 'product', $odoo_id ) ?? 0 );
			if ( $pulled_wp_id > 0 ) {
				$this->pull_coordinator->on_product_pulled( $pulled_wp_id, $odoo_id );
			}
		}

		// After order pull: fetch related shipment tracking.
		if ( $result->succeeded() && 'order' === $entity_type && 'delete' !== $action ) {
			$pulled_wp_id = $wp_id ?: ( $this->get_wp_mapping( 'order', $odoo_id ) ?? 0 );
			if ( $pulled_wp_id > 0 ) {
				$this->pull_coordinator->on_order_pulled( $odoo_id, $pulled_wp_id );
			}
		}

		return $result;
	}

	// ─── Map to Odoo ──────────────────────────────────────

	/**
	 * Map WP data to Odoo values, enriching products with gallery images.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		$mapped = parent::map_to_odoo( $entity_type, $wp_data );

		if ( 'product' === $entity_type ) {
			$settings = $this->get_settings();
			if ( ! empty( $settings['sync_product_images'] ) ) {
				$wp_id   = (int) ( $wp_data['ID'] ?? $wp_data['id'] ?? 0 );
				$gallery = $this->image_handler->export_gallery( $wp_id );
				if ( ! empty( $gallery ) ) {
					$mapped['product_image_ids'] = $gallery;
				}
			}
		}

		return $mapped;
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

	/**
	 * Dedup domain for products: match by internal reference (SKU).
	 *
	 * @param string               $entity_type Entity type.
	 * @param array<string, mixed> $odoo_values Odoo values.
	 * @return array<int, mixed>
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'product' === $entity_type && ! empty( $odoo_values['default_code'] ) ) {
			return [ [ 'default_code', '=', $odoo_values['default_code'] ] ];
		}

		return [];
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
