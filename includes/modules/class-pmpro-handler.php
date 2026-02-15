<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PMPro Handler — data access for levels, orders, and memberships.
 *
 * Loads Paid Memberships Pro entities and maps statuses to Odoo equivalents.
 * Orders are pre-formatted as Odoo `account.move` data (invoice).
 *
 * PMPro stores data in custom DB tables (not CPTs):
 * - `pmpro_membership_levels` — level definitions
 * - `pmpro_membership_orders` — payment orders
 * - `pmpro_memberships_users` — user membership records
 *
 * Called by PMPro_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.6.5
 */
class PMPro_Handler extends Membership_Handler_Base {

	/**
	 * Order status mapping: PMPro → Odoo account.move state.
	 *
	 * @var array<string, string>
	 */
	private const ORDER_STATUS_MAP = [
		'success'  => 'posted',
		'pending'  => 'draft',
		'refunded' => 'cancel',
		'error'    => 'cancel',
		'review'   => 'draft',
		'token'    => 'draft',
	];

	/**
	 * Membership status mapping: PMPro → Odoo membership.membership_line state.
	 *
	 * @var array<string, string>
	 */
	private const MEMBERSHIP_STATUS_MAP = [
		'active'          => 'paid',
		'admin_cancelled' => 'cancelled',
		'admin_changed'   => 'old',
		'cancelled'       => 'cancelled',
		'changed'         => 'old',
		'expired'         => 'old',
		'inactive'        => 'none',
	];

	// ─── Load level ────────────────────────────────────────

	/**
	 * Load a PMPro membership level.
	 *
	 * Uses pmpro_getLevel() since levels are stored in a custom DB table,
	 * not as WordPress CPTs.
	 *
	 * @param int $level_id PMPro membership level ID.
	 * @return array<string, mixed> Level data for field mapping, or empty if not found.
	 */
	public function load_level( int $level_id ): array {
		$level = pmpro_getLevel( $level_id );
		if ( ! $level ) {
			$this->logger->warning( 'PMPro level not found.', [ 'level_id' => $level_id ] );
			return [];
		}

		// Use billing_amount for recurring levels, initial_payment for one-time.
		$billing = (float) $level->billing_amount;
		$price   = $billing > 0 ? $billing : (float) $level->initial_payment;

		return [
			'level_name' => $level->name,
			'list_price' => $price,
			'membership' => true,
			'type'       => 'service',
		];
	}

	// ─── Load order ────────────────────────────────────────

	/**
	 * Load a PMPro order as Odoo account.move data.
	 *
	 * Returns data pre-formatted for Odoo: includes invoice_line_ids
	 * as One2many tuples [(0, 0, {...})].
	 *
	 * @param int $order_id       PMPro order ID.
	 * @param int $partner_id     Resolved Odoo partner ID.
	 * @param int $level_odoo_id  Resolved Odoo product.product ID for the level.
	 * @return array<string, mixed> Invoice data, or empty if not found.
	 */
	public function load_order( int $order_id, int $partner_id, int $level_odoo_id ): array {
		$order = new \MemberOrder( $order_id );
		if ( ! $order->id ) {
			$this->logger->warning( 'PMPro order not found.', [ 'order_id' => $order_id ] );
			return [];
		}

		$level_name = '';
		$level      = pmpro_getLevel( $order->membership_id );
		if ( $level ) {
			$level_name = $level->name;
		}

		return Odoo_Accounting_Formatter::for_account_move(
			$partner_id,
			$level_odoo_id,
			(float) $order->total,
			substr( $order->timestamp, 0, 10 ),
			$order->code,
			$level_name,
			__( 'PMPro membership', 'wp4odoo' )
		);
	}

	// ─── Load membership ───────────────────────────────────

	/**
	 * Load a PMPro user membership from the pmpro_memberships_users table.
	 *
	 * Returns raw data with user_id and level_id for resolution by the module.
	 *
	 * @param int $row_id pmpro_memberships_users row ID.
	 * @return array<string, mixed> Membership data, or empty if not found.
	 */
	public function load_membership( int $row_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pmpro_memberships_users';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, membership_id, status, startdate, enddate FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$row_id
			)
		);

		if ( ! $row ) {
			$this->logger->warning( 'PMPro membership row not found.', [ 'row_id' => $row_id ] );
			return [];
		}

		$end_date = ( '0000-00-00 00:00:00' === $row->enddate || empty( $row->enddate ) )
			? false
			: substr( $row->enddate, 0, 10 );

		return [
			'user_id'   => (int) $row->user_id,
			'level_id'  => (int) $row->membership_id,
			'date_from' => substr( $row->startdate, 0, 10 ),
			'date_to'   => $end_date,
			'state'     => $this->map_membership_status_to_odoo( $row->status ),
		];
	}

	// ─── Level ID helpers ──────────────────────────────────

	/**
	 * Get the membership level ID for an order.
	 *
	 * @param int $order_id PMPro order ID.
	 * @return int Level ID, or 0 if not found.
	 */
	public function get_level_id_for_order( int $order_id ): int {
		$order = new \MemberOrder( $order_id );
		return $order->membership_id;
	}

	/**
	 * Get the membership level ID for a membership row.
	 *
	 * @param int $row_id pmpro_memberships_users row ID.
	 * @return int Level ID, or 0 if not found.
	 */
	public function get_level_id_for_membership( int $row_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'pmpro_memberships_users';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT membership_id FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$row_id
			)
		);
	}

	// ─── Status mapping ────────────────────────────────────

	/**
	 * Map a PMPro order status to an Odoo account.move state.
	 *
	 * @param string $status PMPro order status.
	 * @return string Odoo account.move state.
	 */
	public function map_order_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::ORDER_STATUS_MAP, 'wp4odoo_pmpro_order_status_map', 'draft' );
	}

	/**
	 * Map a PMPro membership status to an Odoo membership_line state.
	 *
	 * @param string $status PMPro membership status.
	 * @return string Odoo membership_line state.
	 */
	public function map_membership_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::MEMBERSHIP_STATUS_MAP, 'wp4odoo_pmpro_membership_status_map', 'none' );
	}
}
