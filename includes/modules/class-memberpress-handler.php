<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MemberPress Handler — data access for plans, transactions, and subscriptions.
 *
 * Loads MemberPress entities and maps statuses to Odoo equivalents.
 * Transactions are pre-formatted as Odoo `account.move` data (invoice).
 *
 * Called by MemberPress_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
class MemberPress_Handler {

	/**
	 * Transaction status mapping: MemberPress → Odoo account.move state.
	 *
	 * @var array<string, string>
	 */
	private const TXN_STATUS_MAP = [
		'complete' => 'posted',
		'pending'  => 'draft',
		'failed'   => 'cancel',
		'refunded' => 'cancel',
	];

	/**
	 * Subscription status mapping: MemberPress → Odoo membership.membership_line state.
	 *
	 * @var array<string, string>
	 */
	private const SUB_STATUS_MAP = [
		'active'    => 'paid',
		'suspended' => 'waiting',
		'cancelled' => 'cancelled',
		'expired'   => 'old',
		'paused'    => 'waiting',
		'stopped'   => 'cancelled',
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
	 * Load a MemberPress plan (product).
	 *
	 * @param int $post_id MemberPress product post ID.
	 * @return array<string, mixed> Plan data for field mapping, or empty if not found.
	 */
	public function load_plan( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'memberpressproduct' !== $post->post_type ) {
			$this->logger->warning( 'MemberPress plan not found.', [ 'post_id' => $post_id ] );
			return [];
		}

		$product = new \MeprProduct( $post_id );

		return [
			'plan_name'  => $post->post_title,
			'list_price' => (float) $product->get_price(),
			'membership' => true,
			'type'       => 'service',
		];
	}

	// ─── Load transaction ───────────────────────────────────

	/**
	 * Load a MemberPress transaction as Odoo account.move data.
	 *
	 * Returns data pre-formatted for Odoo: includes invoice_line_ids
	 * as One2many tuples [(0, 0, {...})].
	 *
	 * @param int $txn_id         MemberPress transaction ID.
	 * @param int $partner_id     Resolved Odoo partner ID.
	 * @param int $plan_odoo_id   Resolved Odoo product.product ID for the plan.
	 * @return array<string, mixed> Invoice data, or empty if not found.
	 */
	public function load_transaction( int $txn_id, int $partner_id, int $plan_odoo_id ): array {
		$txn = new \MeprTransaction( $txn_id );
		if ( ! $txn->id ) {
			$this->logger->warning( 'MemberPress transaction not found.', [ 'txn_id' => $txn_id ] );
			return [];
		}

		$plan_name = '';
		if ( $txn->product_id > 0 ) {
			$plan_post = get_post( $txn->product_id );
			if ( $plan_post ) {
				$plan_name = $plan_post->post_title;
			}
		}

		return Odoo_Accounting_Formatter::for_account_move(
			$partner_id,
			$plan_odoo_id,
			(float) $txn->amount,
			substr( $txn->created_at, 0, 10 ),
			$txn->trans_num,
			$plan_name,
			__( 'MemberPress subscription', 'wp4odoo' )
		);
	}

	// ─── Load subscription ──────────────────────────────────

	/**
	 * Load a MemberPress subscription.
	 *
	 * Returns raw data with user_id and plan_id for resolution by the module.
	 *
	 * @param int $sub_id MemberPress subscription ID.
	 * @return array<string, mixed> Subscription data, or empty if not found.
	 */
	public function load_subscription( int $sub_id ): array {
		$sub = new \MeprSubscription( $sub_id );
		if ( ! $sub->id ) {
			$this->logger->warning( 'MemberPress subscription not found.', [ 'sub_id' => $sub_id ] );
			return [];
		}

		return [
			'user_id'   => (int) $sub->user_id,
			'plan_id'   => (int) $sub->product_id,
			'date_from' => substr( $sub->created_at, 0, 10 ),
			'date_to'   => false,
			'state'     => $this->map_sub_status_to_odoo( $sub->status ),
		];
	}

	// ─── Status mapping ─────────────────────────────────────

	/**
	 * Map a MemberPress transaction status to an Odoo account.move state.
	 *
	 * @param string $status MemberPress transaction status.
	 * @return string Odoo account.move state.
	 */
	public function map_txn_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::TXN_STATUS_MAP, 'wp4odoo_mepr_txn_status_map', 'draft' );
	}

	/**
	 * Map a MemberPress subscription status to an Odoo membership_line state.
	 *
	 * @param string $status MemberPress subscription status.
	 * @return string Odoo membership_line state.
	 */
	public function map_sub_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::SUB_STATUS_MAP, 'wp4odoo_mepr_sub_status_map', 'none' );
	}
}
