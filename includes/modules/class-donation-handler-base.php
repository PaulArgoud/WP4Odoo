<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for donation/payment plugin handlers.
 *
 * Extracts shared patterns from GiveWP_Handler, Charitable_Handler,
 * and SimplePay_Handler: Logger, CPT-based form loading, and
 * dual-model donation formatting.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
abstract class Donation_Handler_Base {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	protected Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Load a form/campaign CPT as a service product.
	 *
	 * Shared logic for GiveWP forms, Charitable campaigns, and
	 * WP Simple Pay forms — all follow the same CPT-check pattern.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $post_type    Expected post type.
	 * @param string $entity_label Label for log warning (e.g. 'GiveWP form').
	 * @return array<string, mixed> Form data, or empty if not found.
	 */
	protected function load_form_by_cpt( int $post_id, string $post_type, string $entity_label ): array {
		$post = get_post( $post_id );
		if ( ! $post || $post_type !== $post->post_type ) {
			$this->logger->warning( $entity_label . ' not found.', [ 'post_id' => $post_id ] );
			return [];
		}

		return [
			'form_name'  => $post->post_title,
			'list_price' => 0.0,
			'type'       => 'service',
		];
	}

	/**
	 * Format donation/payment data for the target Odoo model.
	 *
	 * Routes to OCA donation.donation or core account.move depending
	 * on the $use_donation_model flag.
	 *
	 * @param int    $partner_id         Resolved Odoo partner ID.
	 * @param int    $product_odoo_id    Resolved Odoo product.product ID.
	 * @param float  $amount             Donation/payment amount.
	 * @param string $date               Date (Y-m-d).
	 * @param string $ref                Payment reference.
	 * @param string $product_name       Product/form title for invoice line name.
	 * @param string $fallback_name      Default line name if $product_name is empty.
	 * @param bool   $use_donation_model True for OCA donation.donation, false for account.move.
	 * @return array<string, mixed> Odoo-formatted data.
	 */
	protected function format_donation( int $partner_id, int $product_odoo_id, float $amount, string $date, string $ref, string $product_name, string $fallback_name, bool $use_donation_model ): array {
		if ( $use_donation_model ) {
			return Odoo_Accounting_Formatter::for_donation_model( $partner_id, $product_odoo_id, $amount, $date, $ref );
		}

		return Odoo_Accounting_Formatter::for_account_move( $partner_id, $product_odoo_id, $amount, $date, $ref, $product_name, $fallback_name );
	}
}
