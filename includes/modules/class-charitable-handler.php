<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Charitable Handler — data access for campaigns and donations.
 *
 * Loads WP Charitable entities and delegates Odoo formatting to
 * Odoo_Accounting_Formatter (shared with GiveWP and SimplePay).
 *
 * Called by Charitable_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class Charitable_Handler {

	/**
	 * Donation status mapping: WP Charitable → Odoo.
	 *
	 * @var array<string, string>
	 */
	private const DONATION_STATUS_MAP = [
		'charitable-completed'   => 'completed',
		'charitable-pending'     => 'pending',
		'charitable-failed'      => 'draft',
		'charitable-refunded'    => 'refunded',
		'charitable-cancelled'   => 'draft',
		'charitable-preapproval' => 'pending',
	];

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

	// ─── Donor name extraction ────────────────────────────

	/**
	 * Extract the donor display name for a donation.
	 *
	 * @param int $wp_id Charitable donation post ID.
	 * @return string Donor name, or empty string.
	 */
	public function get_donor_name( int $wp_id ): string {
		$first_name = (string) get_post_meta( $wp_id, '_charitable_donor_first_name', true );
		$last_name  = (string) get_post_meta( $wp_id, '_charitable_donor_last_name', true );
		return trim( $first_name . ' ' . $last_name );
	}

	// ─── Load campaign ────────────────────────────────────

	/**
	 * Load a WP Charitable campaign as a service product.
	 *
	 * @param int $post_id Charitable campaign post ID.
	 * @return array<string, mixed> Campaign data for field mapping, or empty if not found.
	 */
	public function load_campaign( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'campaign' !== $post->post_type ) {
			$this->logger->warning( 'Charitable campaign not found.', [ 'post_id' => $post_id ] );
			return [];
		}

		return [
			'form_name'  => $post->post_title,
			'list_price' => 0.0,
			'type'       => 'service',
		];
	}

	// ─── Load donation ────────────────────────────────────

	/**
	 * Load a WP Charitable donation, formatted for the target Odoo model.
	 *
	 * When $use_donation_model is true, returns data for OCA donation.donation.
	 * Otherwise returns data for core account.move (invoice).
	 *
	 * @param int  $donation_id        Charitable donation ID.
	 * @param int  $partner_id         Resolved Odoo partner ID.
	 * @param int  $campaign_odoo_id   Resolved Odoo product.product ID for the campaign.
	 * @param bool $use_donation_model True for OCA donation.donation, false for account.move.
	 * @return array<string, mixed> Donation data, or empty if not found.
	 */
	public function load_donation( int $donation_id, int $partner_id, int $campaign_odoo_id, bool $use_donation_model ): array {
		$post = get_post( $donation_id );
		if ( ! $post || 'donation' !== $post->post_type ) {
			$this->logger->warning( 'Charitable donation not found.', [ 'donation_id' => $donation_id ] );
			return [];
		}

		$amount = (float) get_post_meta( $donation_id, '_charitable_donation_amount', true );

		// Resolve campaign title for invoice line name.
		$campaign_id    = (int) get_post_meta( $donation_id, '_charitable_campaign_id', true );
		$campaign_post  = $campaign_id > 0 ? get_post( $campaign_id ) : null;
		$campaign_title = $campaign_post ? $campaign_post->post_title : '';

		$date = substr( $post->post_date, 0, 10 );
		$ref  = 'charitable-donation-' . $donation_id;

		if ( $use_donation_model ) {
			return Odoo_Accounting_Formatter::for_donation_model( $partner_id, $campaign_odoo_id, $amount, $date, $ref );
		}

		return Odoo_Accounting_Formatter::for_account_move( $partner_id, $campaign_odoo_id, $amount, $date, $ref, $campaign_title, __( 'Donation', 'wp4odoo' ) );
	}

	// ─── Status mapping ───────────────────────────────────

	/**
	 * Map a WP Charitable donation status to an Odoo status string.
	 *
	 * @param string $status Charitable donation status.
	 * @return string Odoo status.
	 */
	public function map_donation_status( string $status ): string {
		return Status_Mapper::resolve( $status, self::DONATION_STATUS_MAP, 'wp4odoo_charitable_donation_status_map', 'draft' );
	}
}
