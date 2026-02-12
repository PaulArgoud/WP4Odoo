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

	// ─── Reverse status mapping ────────────────────────────

	/**
	 * Reverse status mapping: Odoo membership_line state → WC membership status.
	 *
	 * @var array<string, string>
	 */
	private const REVERSE_STATUS_MAP = [
		'paid'      => 'wcm-active',
		'free'      => 'wcm-complimentary',
		'waiting'   => 'wcm-delayed',
		'cancelled' => 'wcm-cancelled',
		'none'      => 'wcm-expired',
	];

	/**
	 * Map an Odoo membership_line state to a WC membership status.
	 *
	 * @param string $odoo_state Odoo membership_line state.
	 * @return string WC membership status (e.g. 'wcm-active').
	 */
	public function map_odoo_status_to_wc( string $odoo_state ): string {
		$map = apply_filters( 'wp4odoo_membership_reverse_status_map', self::REVERSE_STATUS_MAP );

		return $map[ $odoo_state ] ?? 'wcm-expired';
	}

	// ─── Pull: parse plan from Odoo ────────────────────────

	/**
	 * Parse Odoo product data into WC membership plan format.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> Plan data for save_plan().
	 */
	public function parse_plan_from_odoo( array $odoo_data ): array {
		return [
			'plan_name'  => $odoo_data['name'] ?? '',
			'list_price' => (float) ( $odoo_data['list_price'] ?? 0 ),
		];
	}

	// ─── Pull: save plan ───────────────────────────────────

	/**
	 * Save a membership plan pulled from Odoo as a wc_membership_plan CPT post.
	 *
	 * Creates a new post when $wp_id is 0, updates an existing one otherwise.
	 *
	 * @param array<string, mixed> $data  Parsed plan data.
	 * @param int                  $wp_id Existing post ID (0 to create new).
	 * @return int The post ID, or 0 on failure.
	 */
	public function save_plan( array $data, int $wp_id = 0 ): int {
		$post_args = [
			'post_title'  => $data['plan_name'] ?? '',
			'post_type'   => 'wc_membership_plan',
			'post_status' => 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_args['ID'] = $wp_id;
			$result          = \wp_update_post( $post_args, true );
		} else {
			$result = \wp_insert_post( $post_args, true );
		}

		if ( \is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to save membership plan post.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		return $result;
	}

	// ─── Pull: parse membership from Odoo ──────────────────

	/**
	 * Parse Odoo membership_line data into WC membership format.
	 *
	 * Reverses the state to a WC status and extracts date fields.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> Membership data for save_membership_from_odoo().
	 */
	public function parse_membership_from_odoo( array $odoo_data ): array {
		return [
			'state'       => $this->map_odoo_status_to_wc( $odoo_data['state'] ?? 'none' ),
			'date_from'   => $odoo_data['date_from'] ?? '',
			'date_to'     => $odoo_data['date_to'] ?? false,
			'date_cancel' => $odoo_data['date_cancel'] ?? false,
		];
	}

	// ─── Pull: save membership ─────────────────────────────

	/**
	 * Update an existing WC user membership with pulled Odoo data.
	 *
	 * Only updates existing memberships — cannot create from Odoo
	 * (memberships originate in WooCommerce checkout).
	 *
	 * @param array<string, mixed> $data  Parsed membership data.
	 * @param int                  $wp_id Existing user membership ID.
	 * @return int The membership ID on success, 0 on failure.
	 */
	public function save_membership_from_odoo( array $data, int $wp_id ): int {
		if ( $wp_id <= 0 ) {
			$this->logger->warning( 'Cannot create membership from Odoo — memberships originate in WooCommerce.' );
			return 0;
		}

		$args = [ 'ID' => $wp_id ];

		if ( isset( $data['state'] ) ) {
			$args['post_status'] = $data['state'];
		}

		$result = \wp_update_post( $args, true );
		if ( \is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to update membership from Odoo.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		if ( ! empty( $data['date_from'] ) ) {
			\update_post_meta( $wp_id, '_start_date', $data['date_from'] );
		}
		if ( ! empty( $data['date_to'] ) ) {
			\update_post_meta( $wp_id, '_end_date', $data['date_to'] );
		}
		if ( ! empty( $data['date_cancel'] ) ) {
			\update_post_meta( $wp_id, '_cancelled_date', $data['date_cancel'] );
		}

		return $wp_id;
	}
}
