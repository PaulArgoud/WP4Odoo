<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dokan Handler — data access for marketplace vendors, sub-orders,
 * commissions, and payouts.
 *
 * Loads Dokan vendor data and formats sub-orders as purchase orders,
 * commissions as vendor bills (`account.move` with `move_type=in_invoice`),
 * and payouts as account payments (`account.payment`).
 *
 * Called by Dokan_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
class Dokan_Handler extends Marketplace_Handler_Base {

	/**
	 * {@inheritDoc}
	 */
	protected function get_marketplace_label(): string {
		return 'Dokan';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_hook_prefix(): string {
		return 'dokan';
	}

	// ─── Vendor ─────────────────────────────────────────────

	/**
	 * Load vendor data as Odoo partner fields.
	 *
	 * Returns data suitable for `res.partner` with `supplier_rank = 1`.
	 *
	 * @param int $user_id WordPress user ID (vendor).
	 * @return array<string, mixed> Partner data, or empty if not found.
	 */
	public function load_vendor( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning( 'Dokan vendor user not found.', [ 'user_id' => $user_id ] );
			return [];
		}

		$store_info = $this->get_vendor_store_info( $user_id );
		$store_name = $store_info['store_name'] ?? '';
		$address    = $store_info['address'] ?? [];

		$name         = $store_name ?: $user->display_name;
		$street_parts = array_filter(
			[
				$address['street_1'] ?? '',
			]
		);

		return [
			'store_name'    => $store_name,
			'name'          => $name,
			'email'         => $user->user_email,
			'phone'         => $store_info['phone'] ?? '',
			'street'        => implode( ', ', $street_parts ),
			'city'          => $address['city'] ?? '',
			'zip'           => $address['zip'] ?? '',
			'supplier_rank' => 1,
			'is_company'    => $this->is_company_name( $name ),
		];
	}

	/**
	 * Save vendor status from Odoo pull.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $status  Odoo-side status string.
	 * @return bool True on success.
	 */
	public function save_vendor_status( int $user_id, string $status ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning( 'Cannot save vendor status — user not found.', [ 'user_id' => $user_id ] );
			return false;
		}

		$enabled = 'active' === $status;
		update_user_meta( $user_id, 'dokan_enable_selling', $enabled ? 'yes' : 'no' );

		$this->logger->info(
			'Updated Dokan vendor status from Odoo.',
			[
				'user_id' => $user_id,
				'status'  => $status,
				'enabled' => $enabled,
			]
		);

		return true;
	}

	// ─── Commission ─────────────────────────────────────────

	/**
	 * Load a commission as Odoo vendor bill fields.
	 *
	 * Uses Odoo_Accounting_Formatter::for_vendor_bill() for consistent
	 * formatting with other modules (AffiliateWP, etc.).
	 *
	 * @param int   $order_id   WC order ID the commission is for.
	 * @param int   $partner_id Resolved Odoo partner ID (vendor).
	 * @param float $amount     Commission amount.
	 * @return array<string, mixed> Vendor bill data.
	 */
	public function load_commission( int $order_id, int $partner_id, float $amount ): array {
		/* translators: 1: order ID */
		$line_name = sprintf( __( 'Marketplace commission — Order #%d', 'wp4odoo' ), $order_id );

		$order        = wc_get_order( $order_id );
		$date_created = $order ? $order->get_date_created() : null;
		$date         = $date_created ? $date_created->format( 'Y-m-d' ) : gmdate( 'Y-m-d' );

		return Odoo_Accounting_Formatter::for_vendor_bill(
			$partner_id,
			$amount,
			$date,
			'dokan-comm-' . $order_id,
			$line_name
		);
	}

	/**
	 * Load a payout (withdraw request) as Odoo account.payment fields.
	 *
	 * @param int $withdraw_id Dokan withdraw ID.
	 * @param int $partner_id  Resolved Odoo partner ID (vendor).
	 * @return array<string, mixed> Payment data, or empty if not found.
	 */
	public function load_payout( int $withdraw_id, int $partner_id ): array {
		$withdraw = $this->fetch_withdraw( $withdraw_id );
		if ( ! $withdraw ) {
			$this->logger->warning( 'Dokan withdraw not found.', [ 'withdraw_id' => $withdraw_id ] );
			return [];
		}

		$date = substr( $withdraw->date, 0, 10 );

		return [
			'partner_id'   => $partner_id,
			'amount'       => (float) $withdraw->amount,
			'date'         => $date,
			'ref'          => 'dokan-payout-' . $withdraw_id,
			'payment_type' => 'outbound',
			'partner_type' => 'supplier',
		];
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Get the vendor user ID for a Dokan sub-order.
	 *
	 * @param int $order_id WC sub-order ID.
	 * @return int Vendor user ID, or 0 if not found.
	 */
	public function get_vendor_id_for_order( int $order_id ): int {
		return (int) dokan_get_seller_id_by_order( $order_id );
	}

	/**
	 * Get the vendor user ID for a Dokan withdraw request.
	 *
	 * @param int $withdraw_id Dokan withdraw ID.
	 * @return int Vendor user ID, or 0 if not found.
	 */
	public function get_vendor_id_for_withdraw( int $withdraw_id ): int {
		$withdraw = $this->fetch_withdraw( $withdraw_id );
		return $withdraw ? (int) $withdraw->user_id : 0;
	}

	/**
	 * Get the admin (marketplace) commission earned on a Dokan sub-order.
	 *
	 * @param int $order_id WC sub-order ID.
	 * @return float Commission amount, or 0.0 when it cannot be determined.
	 */
	public function get_admin_earning( int $order_id ): float {
		// Legacy helper (removed in current Dokan releases).
		if ( function_exists( 'dokan_get_earning_by_order' ) ) {
			return (float) dokan_get_earning_by_order( $order_id, 'admin' );
		}

		// Dokan 3+: commission service (returns WP_Error / null on failure).
		$commission = function_exists( 'dokan' ) ? dokan()->commission : null;
		if ( is_object( $commission ) && method_exists( $commission, 'get_earning_by_order' ) ) {
			$earning = $commission->get_earning_by_order( $order_id, 'admin' );
			return is_numeric( $earning ) ? (float) $earning : 0.0;
		}

		return 0.0;
	}

	/**
	 * Fetch a Dokan withdraw request as a plain object (user_id, amount, date).
	 *
	 * Supports the legacy `dokan_get_withdraw()` helper and the withdraw
	 * manager of current Dokan releases (`dokan()->withdraw->get()`).
	 *
	 * @param int $withdraw_id Dokan withdraw ID.
	 * @return object|null Object with user_id, amount and date properties, or null.
	 */
	private function fetch_withdraw( int $withdraw_id ): ?object {
		if ( function_exists( 'dokan_get_withdraw' ) ) {
			$legacy = dokan_get_withdraw( $withdraw_id );
			return $legacy ? (object) [
				'user_id' => $legacy->user_id,
				'amount'  => $legacy->amount,
				'date'    => $legacy->date,
			] : null;
		}

		$manager = function_exists( 'dokan' ) ? dokan()->withdraw : null;
		if ( is_object( $manager ) && method_exists( $manager, 'get' ) ) {
			$withdraw = $manager->get( $withdraw_id );
			if ( is_object( $withdraw ) && method_exists( $withdraw, 'get_user_id' ) ) {
				return (object) [
					'user_id' => $withdraw->get_user_id(),
					'amount'  => $withdraw->get_amount(),
					'date'    => $withdraw->get_date(),
				];
			}
		}

		return null;
	}

	/**
	 * Get vendor store info from Dokan.
	 *
	 * @param int $user_id Vendor user ID.
	 * @return array<string, mixed> Store info array.
	 */
	private function get_vendor_store_info( int $user_id ): array {
		$store_info = (array) get_user_meta( $user_id, 'dokan_profile_settings', true );

		if ( empty( $store_info ) ) {
			return [
				'store_name' => '',
				'address'    => [],
				'phone'      => '',
			];
		}

		return $store_info;
	}
}
