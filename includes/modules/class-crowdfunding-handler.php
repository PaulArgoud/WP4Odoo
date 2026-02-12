<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Crowdfunding Handler — data access for crowdfunding campaigns.
 *
 * Loads WC product data + crowdfunding meta (wpneo_*) and formats it
 * for Odoo product.product (service type).
 *
 * Called by Crowdfunding_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
class Crowdfunding_Handler {

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

	// ─── Load campaign ────────────────────────────────────

	/**
	 * Load a crowdfunding campaign as an Odoo service product.
	 *
	 * Reads the WC product + crowdfunding-specific meta fields
	 * (funding goal, end date, minimum amount).
	 *
	 * @param int $product_id WC product ID.
	 * @return array<string, mixed> Campaign data for field mapping, or empty if not found.
	 */
	public function load_campaign( int $product_id ): array {
		$product = \wc_get_product( $product_id );
		if ( ! $product ) {
			$this->logger->warning( 'Crowdfunding campaign product not found.', [ 'product_id' => $product_id ] );
			return [];
		}

		$funding_goal = (float) get_post_meta( $product_id, '_wpneo_funding_goal', true );
		$end_date     = (string) get_post_meta( $product_id, '_wpneo_funding_end_date', true );
		$min_amount   = (float) get_post_meta( $product_id, '_wpneo_funding_minimum_amount', true );

		return [
			'campaign_name' => $product->get_name(),
			'list_price'    => $funding_goal > 0 ? $funding_goal : (float) $product->get_price(),
			'description'   => $this->build_description( $product->get_description(), $funding_goal, $end_date, $min_amount ),
			'type'          => 'service',
		];
	}

	// ─── Helpers ──────────────────────────────────────────

	/**
	 * Check if a product is a crowdfunding campaign.
	 *
	 * Detects by the presence of the _wpneo_funding_goal meta key.
	 *
	 * @param int $product_id WC product ID.
	 * @return bool
	 */
	public function is_crowdfunding( int $product_id ): bool {
		$goal = get_post_meta( $product_id, '_wpneo_funding_goal', true );
		return '' !== $goal && false !== $goal;
	}

	// ─── Private ──────────────────────────────────────────

	/**
	 * Build a campaign description with funding info.
	 *
	 * @param string $description  Product description.
	 * @param float  $funding_goal Funding goal amount.
	 * @param string $end_date     Campaign end date.
	 * @param float  $min_amount   Minimum pledge amount.
	 * @return string
	 */
	private function build_description( string $description, float $funding_goal, string $end_date, float $min_amount ): string {
		$parts = [];

		if ( $funding_goal > 0 ) {
			/* translators: %s: funding goal amount. */
			$parts[] = sprintf( __( 'Funding goal: %s', 'wp4odoo' ), number_format( $funding_goal, 2 ) );
		}

		if ( '' !== $end_date ) {
			/* translators: %s: campaign end date. */
			$parts[] = sprintf( __( 'End date: %s', 'wp4odoo' ), $end_date );
		}

		if ( $min_amount > 0 ) {
			/* translators: %s: minimum pledge amount. */
			$parts[] = sprintf( __( 'Min pledge: %s', 'wp4odoo' ), number_format( $min_amount, 2 ) );
		}

		$info_line = implode( ' | ', $parts );

		$clean_desc = wp_strip_all_tags( $description );

		if ( '' !== $clean_desc && '' !== $info_line ) {
			return $clean_desc . "\n\n" . $info_line;
		}

		if ( '' !== $clean_desc ) {
			return $clean_desc;
		}

		return $info_line;
	}
}
