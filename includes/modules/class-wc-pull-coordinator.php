<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Error_Type;
use WP4Odoo\Field_Mapper;
use WP4Odoo\Logger;
use WP4Odoo\Queue_Manager;
use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pull orchestration coordinator for the WooCommerce module.
 *
 * Extracted from WooCommerce_Module to separate pull-side complexity
 * (variant dispatch, shipment dispatch, image/pricelist/shipment
 * post-pull hooks) from the main module class.
 *
 * @package WP4Odoo
 * @since   2.9.0
 */
class WC_Pull_Coordinator {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Closure returning the module settings array.
	 *
	 * @var \Closure
	 */
	private \Closure $settings_fn;

	/**
	 * Closure returning the Odoo_Client.
	 *
	 * @var \Closure
	 */
	private \Closure $client_fn;

	/**
	 * Closure resolving wp_id from (entity_type, odoo_id).
	 *
	 * @var \Closure
	 */
	private \Closure $mapping_fn;

	/**
	 * Variant handler.
	 *
	 * @var Variant_Handler
	 */
	private Variant_Handler $variant_handler;

	/**
	 * Image handler.
	 *
	 * @var Image_Handler
	 */
	private Image_Handler $image_handler;

	/**
	 * Pricelist handler.
	 *
	 * @var Pricelist_Handler
	 */
	private Pricelist_Handler $pricelist_handler;

	/**
	 * Shipment handler.
	 *
	 * @var Shipment_Handler
	 */
	private Shipment_Handler $shipment_handler;

	/**
	 * Raw Odoo data captured during pull for post-save image processing.
	 *
	 * @var array<string, mixed>
	 */
	private array $last_odoo_data = [];

	/**
	 * Constructor.
	 *
	 * @param Logger            $logger            Logger instance.
	 * @param \Closure          $settings_fn       Returns the module settings array.
	 * @param \Closure          $client_fn         Returns the Odoo_Client.
	 * @param \Closure          $mapping_fn        Resolves wp_id from (entity_type, odoo_id).
	 * @param Variant_Handler   $variant_handler   Variant handler.
	 * @param Image_Handler     $image_handler     Image handler.
	 * @param Pricelist_Handler $pricelist_handler Pricelist handler.
	 * @param Shipment_Handler  $shipment_handler  Shipment handler.
	 */
	public function __construct(
		Logger $logger,
		\Closure $settings_fn,
		\Closure $client_fn,
		\Closure $mapping_fn,
		Variant_Handler $variant_handler,
		Image_Handler $image_handler,
		Pricelist_Handler $pricelist_handler,
		Shipment_Handler $shipment_handler
	) {
		$this->logger            = $logger;
		$this->settings_fn       = $settings_fn;
		$this->client_fn         = $client_fn;
		$this->mapping_fn        = $mapping_fn;
		$this->variant_handler   = $variant_handler;
		$this->image_handler     = $image_handler;
		$this->pricelist_handler = $pricelist_handler;
		$this->shipment_handler  = $shipment_handler;
	}

	/**
	 * Capture raw Odoo data during product pull for post-save image processing.
	 *
	 * Registered as a filter callback on `wp4odoo_map_from_odoo_woocommerce_product`.
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
	 * Clear captured Odoo data after processing.
	 *
	 * @return void
	 */
	public function clear_odoo_data(): void {
		$this->last_odoo_data = [];
	}

	/**
	 * Pull a single product.product variant from Odoo.
	 *
	 * @param int   $odoo_id Odoo product.product ID.
	 * @param int   $wp_id   Existing WC variation ID (0 if unknown).
	 * @param array $payload Queue payload (may contain parent_wp_id, template_odoo_id).
	 * @return Sync_Result
	 */
	public function pull_variant( int $odoo_id, int $wp_id, array $payload ): Sync_Result {
		$parent_wp_id     = (int) ( $payload['parent_wp_id'] ?? 0 );
		$template_odoo_id = (int) ( $payload['template_odoo_id'] ?? 0 );

		// If parent not in payload, read the variant to find the template, then look up mapping.
		if ( 0 === $parent_wp_id && 0 === $template_odoo_id ) {
			$records = ( $this->client_fn )()->read( 'product.product', [ $odoo_id ], [ 'product_tmpl_id' ] );
			if ( ! empty( $records[0]['product_tmpl_id'] ) ) {
				$template_odoo_id = is_array( $records[0]['product_tmpl_id'] )
					? (int) $records[0]['product_tmpl_id'][0]
					: (int) $records[0]['product_tmpl_id'];
			}
		}

		if ( 0 === $parent_wp_id && $template_odoo_id > 0 ) {
			$parent_wp_id = ( $this->mapping_fn )( 'product', $template_odoo_id ) ?? 0;
		}

		if ( 0 === $parent_wp_id ) {
			$this->logger->warning(
				'Cannot pull variant: parent product not mapped.',
				[
					'variant_odoo_id'  => $odoo_id,
					'template_odoo_id' => $template_odoo_id,
				]
			);
			return Sync_Result::failure( 'Cannot pull variant: parent product not mapped.', Error_Type::Permanent );
		}

		$ok = $this->variant_handler->pull_variants( $template_odoo_id, $parent_wp_id );
		return $ok
			? Sync_Result::success( $parent_wp_id )
			: Sync_Result::failure( 'Variant pull failed.', Error_Type::Transient );
	}

	/**
	 * Handle a direct shipment pull from a stock.picking webhook/queue job.
	 *
	 * @param int $picking_odoo_id Odoo stock.picking ID.
	 * @return Sync_Result
	 */
	public function pull_shipment_for_picking( int $picking_odoo_id ): Sync_Result {
		$settings = ( $this->settings_fn )();

		if ( empty( $settings['sync_shipments'] ) ) {
			return Sync_Result::failure(
				__( 'Shipment sync is disabled.', 'wp4odoo' ),
				Error_Type::Permanent
			);
		}

		try {
			$records = ( $this->client_fn )()->read(
				'stock.picking',
				[ $picking_odoo_id ],
				[ 'sale_id', 'state', 'picking_type_code' ]
			);
		} catch ( \Throwable $e ) {
			return Sync_Result::failure( $e->getMessage(), Error_Type::Transient );
		}

		if ( empty( $records ) ) {
			return Sync_Result::failure(
				__( 'stock.picking not found.', 'wp4odoo' ),
				Error_Type::Permanent
			);
		}

		$picking = $records[0];

		// Only process outgoing + done pickings.
		if ( 'outgoing' !== ( $picking['picking_type_code'] ?? '' )
			|| 'done' !== ( $picking['state'] ?? '' ) ) {
			return Sync_Result::success( 0 );
		}

		$sale_id = Field_Mapper::many2one_to_id( $picking['sale_id'] ?? false );
		if ( ! $sale_id ) {
			return Sync_Result::failure(
				__( 'stock.picking has no linked sale.order.', 'wp4odoo' ),
				Error_Type::Permanent
			);
		}

		$wc_order_id = ( $this->mapping_fn )( 'order', $sale_id );
		if ( ! $wc_order_id ) {
			return Sync_Result::failure(
				__( 'No WC order mapped for the linked sale.order.', 'wp4odoo' ),
				Error_Type::Transient
			);
		}

		$ok = $this->shipment_handler->pull_shipments( $sale_id, $wc_order_id );

		return $ok
			? Sync_Result::success( $wc_order_id )
			: Sync_Result::failure(
				__( 'Shipment pull failed.', 'wp4odoo' ),
				Error_Type::Transient
			);
	}

	/**
	 * Post-pull actions after a product template is pulled.
	 *
	 * Imports the featured image, enqueues variant pulls, and applies
	 * pricelist pricing.
	 *
	 * @param int $wp_id   WC product ID.
	 * @param int $odoo_id Odoo product.template ID.
	 * @return void
	 */
	public function on_product_pulled( int $wp_id, int $odoo_id ): void {
		$this->maybe_pull_product_image( $wp_id );
		$this->enqueue_variants_for_template( $odoo_id, $wp_id );
		$this->maybe_apply_pricelist_price( $wp_id, $odoo_id );
		$this->clear_odoo_data();
	}

	/**
	 * Post-pull actions after an order is pulled.
	 *
	 * Fetches related shipment tracking data.
	 *
	 * @param int $odoo_order_id Odoo sale.order ID.
	 * @param int $wc_order_id   WC order ID.
	 * @return void
	 */
	public function on_order_pulled( int $odoo_order_id, int $wc_order_id ): void {
		$this->maybe_pull_shipments( $odoo_order_id, $wc_order_id );
	}

	/**
	 * Import the featured image for a product if image sync is enabled.
	 *
	 * @param int $wp_product_id WC product ID.
	 * @return void
	 */
	private function maybe_pull_product_image( int $wp_product_id ): void {
		$settings = ( $this->settings_fn )();

		if ( empty( $settings['sync_product_images'] ) ) {
			return;
		}

		$image_data   = $this->last_odoo_data['image_1920'] ?? false;
		$product_name = $this->last_odoo_data['name'] ?? '';

		$this->image_handler->import_featured_image( $wp_product_id, $image_data, $product_name );
	}

	/**
	 * Apply pricelist price to a product if pricelist sync is enabled.
	 *
	 * @param int $wp_product_id    WC product ID.
	 * @param int $odoo_template_id Odoo product.template ID.
	 * @return void
	 */
	private function maybe_apply_pricelist_price( int $wp_product_id, int $odoo_template_id ): void {
		$settings = ( $this->settings_fn )();

		if ( empty( $settings['sync_pricelists'] ) ) {
			return;
		}

		$this->pricelist_handler->apply_pricelist_price( $wp_product_id, $odoo_template_id );
	}

	/**
	 * Pull shipment tracking for an order if shipment sync is enabled.
	 *
	 * @param int $odoo_order_id Odoo sale.order ID.
	 * @param int $wc_order_id   WC order ID.
	 * @return void
	 */
	private function maybe_pull_shipments( int $odoo_order_id, int $wc_order_id ): void {
		$settings = ( $this->settings_fn )();

		if ( empty( $settings['sync_shipments'] ) || $wc_order_id <= 0 ) {
			return;
		}

		$this->shipment_handler->pull_shipments( $odoo_order_id, $wc_order_id );
	}

	/**
	 * Enqueue variant pulls for a product template.
	 *
	 * @param int $template_odoo_id Odoo product.template ID.
	 * @param int $wp_parent_id     WC parent product ID.
	 * @return void
	 */
	private function enqueue_variants_for_template( int $template_odoo_id, int $wp_parent_id ): void {
		$variant_ids = ( $this->client_fn )()->search(
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
}
