<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShopWP Handler — data access for Shopify products synced by ShopWP.
 *
 * ShopWP stores Shopify products as custom post types (`wps_products`)
 * with variant data (price, SKU) in a custom table (`{prefix}shopwp_variants`).
 * This handler reads from both sources.
 *
 * Called by ShopWP_Module via its load_wp_data dispatch and hooks.
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
class ShopWP_Handler {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	// ─── Load product ─────────────────────────────────────

	/**
	 * Load a ShopWP product as an Odoo product.product.
	 *
	 * Reads from the wps_products CPT for name/description and from the
	 * shopwp_variants custom table for price/SKU (first variant).
	 *
	 * @param int $post_id ShopWP product post ID.
	 * @return array<string, mixed> Product data for field mapping, or empty if not found.
	 */
	public function load_product( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'wps_products' !== $post->post_type ) {
			$this->logger->warning( 'ShopWP product not found.', [ 'post_id' => $post_id ] );
			return [];
		}

		$name = $post->post_title;
		if ( '' === $name ) {
			$this->logger->warning( 'ShopWP product has no name.', [ 'post_id' => $post_id ] );
			return [];
		}

		$variant = $this->get_first_variant( $post_id );

		$price = $variant ? (float) ( $variant['price'] ?? 0 ) : 0.0;
		$sku   = $variant ? (string) ( $variant['sku'] ?? '' ) : '';
		$desc  = wp_strip_all_tags( $post->post_content );

		return [
			'product_name' => $name,
			'list_price'   => $price,
			'default_code' => $sku,
			'description'  => $desc,
			'type'         => 'consu',
		];
	}

	// ─── Variant lookup ───────────────────────────────────

	/**
	 * Get the first variant for a ShopWP product from the custom table.
	 *
	 * @param int $post_id Product post ID.
	 * @return array<string, mixed>|null Variant data, or null if none found.
	 */
	private function get_first_variant( int $post_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'shopwp_variants';

		$result = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT price, sku FROM {$table} WHERE product_id = %d ORDER BY position ASC LIMIT 1", $post_id ),
			ARRAY_A
		);

		if ( ! is_array( $result ) ) {
			return null;
		}

		return $result;
	}
}
