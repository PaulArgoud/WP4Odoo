<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Membership Handler — WooCommerce Memberships data access.
 *
 * Loads membership plans and user memberships from WC Memberships,
 * and maps WC membership statuses to Odoo membership_line states.
 *
 * Called by Memberships_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
class Membership_Handler {

	/**
	 * Status mapping: WC membership status → Odoo membership.membership_line state.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'wcm-active'         => 'paid',
		'wcm-free_trial'     => 'free',
		'wcm-complimentary'  => 'free',
		'wcm-delayed'        => 'waiting',
		'wcm-pending-cancel' => 'paid',
		'wcm-paused'         => 'waiting',
		'wcm-cancelled'      => 'cancelled',
		'wcm-expired'        => 'none',
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

	// ─── Load plan ──────────────────────────────────────────

	/**
	 * Load a WC membership plan.
	 *
	 * @param int $plan_id WC membership plan ID.
	 * @return array<string, mixed> Plan data for field mapping, or empty if not found.
	 */
	public function load_plan( int $plan_id ): array {
		$plan = wc_memberships_get_membership_plan( $plan_id );
		if ( ! $plan ) {
			$this->logger->warning( 'Membership plan not found.', [ 'plan_id' => $plan_id ] );
			return [];
		}

		$data = [
			'plan_name'  => $plan->get_name(),
			'membership' => true,
			'type'       => 'service',
		];

		// Resolve price from the first linked product.
		$product_ids = $plan->get_product_ids();
		if ( ! empty( $product_ids ) ) {
			$product = wc_get_product( $product_ids[0] );
			if ( $product ) {
				$data['list_price'] = (float) $product->get_regular_price();
			}
		}

		return $data;
	}

	// ─── Load membership ────────────────────────────────────

	/**
	 * Load a WC user membership.
	 *
	 * @param int $membership_id WC user membership ID.
	 * @return array<string, mixed> Membership data for field mapping, or empty if not found.
	 */
	public function load_membership( int $membership_id ): array {
		$membership = wc_memberships_get_user_membership( $membership_id );
		if ( ! $membership ) {
			$this->logger->warning( 'User membership not found.', [ 'membership_id' => $membership_id ] );
			return [];
		}

		$start_date  = $membership->get_start_date( 'Y-m-d' );
		$end_date    = $membership->get_end_date( 'Y-m-d' );
		$cancel_date = $membership->get_cancelled_date( 'Y-m-d' );

		return [
			'user_id'     => $membership->get_user_id(),
			'plan_id'     => $membership->get_plan_id(),
			'date_from'   => $start_date,
			'date_to'     => $end_date ?: false,
			'date_cancel' => $cancel_date ?: false,
			'state'       => $this->map_status_to_odoo( $membership->get_status() ),
		];
	}

	// ─── Status mapping ─────────────────────────────────────

	/**
	 * Map a WC membership status to an Odoo membership_line state.
	 *
	 * @param string $wc_status WC membership status (e.g. 'wcm-active').
	 * @return string Odoo membership_line state.
	 */
	public function map_status_to_odoo( string $wc_status ): string {
		$map = apply_filters( 'wp4odoo_membership_status_map', self::STATUS_MAP );

		return $map[ $wc_status ] ?? 'none';
	}
}
