<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RCP Handler — data access for levels, payments, and memberships.
 *
 * Loads Restrict Content Pro entities and maps statuses to Odoo equivalents.
 * Payments are pre-formatted as Odoo `account.move` data (invoice).
 *
 * RCP v3.0+ stores data in custom DB tables accessed via object classes:
 * - Membership levels — via `rcp_get_membership_level()`
 * - Payments — via `RCP_Payments::get_payment()`
 * - Memberships — via `rcp_get_membership()`
 *
 * Called by RCP_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.6.5
 */
class RCP_Handler {

	/**
	 * Payment status mapping: RCP → Odoo account.move state.
	 *
	 * @var array<string, string>
	 */
	private const PAYMENT_STATUS_MAP = [
		'complete'  => 'posted',
		'pending'   => 'draft',
		'failed'    => 'cancel',
		'abandoned' => 'cancel',
	];

	/**
	 * Membership status mapping: RCP → Odoo membership.membership_line state.
	 *
	 * @var array<string, string>
	 */
	private const MEMBERSHIP_STATUS_MAP = [
		'active'   => 'paid',
		'pending'  => 'none',
		'canceled' => 'cancelled',
		'expired'  => 'old',
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

	// ─── Load level ────────────────────────────────────────

	/**
	 * Load an RCP membership level.
	 *
	 * @param int $level_id RCP membership level ID.
	 * @return array<string, mixed> Level data for field mapping, or empty if not found.
	 */
	public function load_level( int $level_id ): array {
		$level = rcp_get_membership_level( $level_id );
		if ( ! $level ) {
			$this->logger->warning( 'RCP level not found.', [ 'level_id' => $level_id ] );
			return [];
		}

		// Use recurring_amount for recurring levels, initial_amount for one-time.
		$recurring = (float) ( $level->recurring_amount ?? 0 );
		$price     = $recurring > 0 ? $recurring : (float) ( $level->initial_amount ?? 0 );

		return [
			'level_name' => $level->name ?? '',
			'list_price' => $price,
			'membership' => true,
			'type'       => 'service',
		];
	}

	// ─── Load payment ──────────────────────────────────────

	/**
	 * Load an RCP payment as Odoo account.move data.
	 *
	 * Returns data pre-formatted for Odoo: includes invoice_line_ids
	 * as One2many tuples [(0, 0, {...})].
	 *
	 * @param int $payment_id    RCP payment ID.
	 * @param int $partner_id    Resolved Odoo partner ID.
	 * @param int $level_odoo_id Resolved Odoo product.product ID for the level.
	 * @return array<string, mixed> Invoice data, or empty if not found.
	 */
	public function load_payment( int $payment_id, int $partner_id, int $level_odoo_id ): array {
		$payments = new \RCP_Payments();
		$payment  = $payments->get_payment( $payment_id );
		if ( ! $payment ) {
			$this->logger->warning( 'RCP payment not found.', [ 'payment_id' => $payment_id ] );
			return [];
		}

		$level_name = $payment->subscription_name ?? '';
		if ( empty( $level_name ) ) {
			$level_id = (int) ( $payment->object_id ?? 0 );
			$level    = rcp_get_membership_level( $level_id );
			if ( $level ) {
				$level_name = $level->name ?? '';
			}
		}

		return Odoo_Accounting_Formatter::for_account_move(
			$partner_id,
			$level_odoo_id,
			(float) ( $payment->amount ?? 0 ),
			substr( $payment->date ?? '', 0, 10 ),
			sprintf( 'RCP-%d', $payment_id ),
			$level_name,
			__( 'RCP membership', 'wp4odoo' )
		);
	}

	// ─── Load membership ───────────────────────────────────

	/**
	 * Load an RCP membership record.
	 *
	 * Returns raw data with user_id and level_id for resolution by the module.
	 *
	 * @param int $membership_id RCP membership ID.
	 * @return array<string, mixed> Membership data, or empty if not found.
	 */
	public function load_membership( int $membership_id ): array {
		$membership = rcp_get_membership( $membership_id );
		if ( ! $membership ) {
			$this->logger->warning( 'RCP membership not found.', [ 'membership_id' => $membership_id ] );
			return [];
		}

		$customer = $membership->get_customer();
		$user_id  = $customer ? $customer->get_user_id() : 0;

		$expiration = $membership->get_expiration_date( false );
		$date_to    = ( empty( $expiration ) || 'none' === $expiration ) ? false : substr( $expiration, 0, 10 );

		return [
			'user_id'   => $user_id,
			'level_id'  => $membership->get_object_id(),
			'date_from' => substr( $membership->get_created_date(), 0, 10 ),
			'date_to'   => $date_to,
			'state'     => $this->map_membership_status_to_odoo( $membership->get_status() ),
		];
	}

	// ─── Level ID helpers ──────────────────────────────────

	/**
	 * Get the membership level ID for a payment.
	 *
	 * @param int $payment_id RCP payment ID.
	 * @return int Level ID, or 0 if not found.
	 */
	public function get_level_id_for_payment( int $payment_id ): int {
		$payments = new \RCP_Payments();
		$payment  = $payments->get_payment( $payment_id );
		if ( ! $payment ) {
			return 0;
		}
		return (int) ( $payment->object_id ?? 0 );
	}

	/**
	 * Get the membership level ID for a membership.
	 *
	 * @param int $membership_id RCP membership ID.
	 * @return int Level ID, or 0 if not found.
	 */
	public function get_level_id_for_membership( int $membership_id ): int {
		$membership = rcp_get_membership( $membership_id );
		if ( ! $membership ) {
			return 0;
		}
		return $membership->get_object_id();
	}

	// ─── Status mapping ────────────────────────────────────

	/**
	 * Map an RCP payment status to an Odoo account.move state.
	 *
	 * @param string $status RCP payment status.
	 * @return string Odoo account.move state.
	 */
	public function map_payment_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::PAYMENT_STATUS_MAP, 'wp4odoo_rcp_payment_status_map', 'draft' );
	}

	/**
	 * Map an RCP membership status to an Odoo membership_line state.
	 *
	 * @param string $status RCP membership status.
	 * @return string Odoo membership_line state.
	 */
	public function map_membership_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::MEMBERSHIP_STATUS_MAP, 'wp4odoo_rcp_membership_status_map', 'none' );
	}
}
