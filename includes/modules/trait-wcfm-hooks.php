<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCFM Marketplace hook callbacks.
 *
 * Listens to WCFM marketplace hooks and enqueues sync jobs for
 * vendors, sub-orders, commissions, and payouts.
 *
 * Composed into WCFM_Module via `use WCFM_Hooks`.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
trait WCFM_Hooks {

	/**
	 * Register WCFM hooks.
	 *
	 * Called from boot() after plugin detection.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_vendors'] ) ) {
			add_action( 'wcfm_membership_registration', $this->safe_callback( [ $this, 'on_vendor_created' ] ), 10, 1 );
			add_action( 'wcfm_vendor_settings_update', $this->safe_callback( [ $this, 'on_vendor_updated' ] ), 10, 2 );
		}

		if ( ! empty( $settings['sync_sub_orders'] ) ) {
			add_action( 'wcfmmp_order_status_updated', $this->safe_callback( [ $this, 'on_sub_order_updated' ] ), 10, 2 );
		}

		if ( ! empty( $settings['sync_commissions'] ) ) {
			add_action( 'wcfm_commission_paid', $this->safe_callback( [ $this, 'on_commission_paid' ] ), 10, 2 );
		}

		if ( ! empty( $settings['sync_payouts'] ) ) {
			add_action( 'wcfm_withdrawal_request_approved', $this->safe_callback( [ $this, 'on_payout_created' ] ), 10, 1 );
		}
	}

	/**
	 * Handle new vendor registration.
	 *
	 * @param int $user_id Vendor user ID.
	 * @return void
	 */
	public function on_vendor_created( int $user_id ): void {
		if ( ! $this->should_sync( 'sync_vendors' ) ) {
			return;
		}

		$this->push_entity( 'vendor', 'sync_vendors', $user_id );
	}

	/**
	 * Handle vendor settings update.
	 *
	 * @param int   $vendor_id Vendor user ID.
	 * @param array $data      Updated settings data.
	 * @return void
	 */
	public function on_vendor_updated( int $vendor_id, array $data = [] ): void {
		if ( ! $this->should_sync( 'sync_vendors' ) ) {
			return;
		}

		$this->push_entity( 'vendor', 'sync_vendors', $vendor_id );
	}

	/**
	 * Handle sub-order status update.
	 *
	 * @param int    $order_id   WC sub-order ID.
	 * @param string $new_status New status.
	 * @return void
	 */
	public function on_sub_order_updated( int $order_id, string $new_status = '' ): void {
		if ( ! $this->should_sync( 'sync_sub_orders' ) ) {
			return;
		}

		$this->push_entity( 'sub_order', 'sync_sub_orders', $order_id );
	}

	/**
	 * Handle commission payment.
	 *
	 * @param int $commission_id WCFM commission ID.
	 * @param int $order_id      WC order ID.
	 * @return void
	 */
	public function on_commission_paid( int $commission_id, int $order_id = 0 ): void {
		if ( ! $this->should_sync( 'sync_commissions' ) ) {
			return;
		}

		$this->push_entity( 'commission', 'sync_commissions', $commission_id );
	}

	/**
	 * Handle payout (withdrawal) approval.
	 *
	 * @param int $withdrawal_id WCFM withdrawal ID.
	 * @return void
	 */
	public function on_payout_created( int $withdrawal_id ): void {
		if ( ! $this->should_sync( 'sync_payouts' ) ) {
			return;
		}

		$this->push_entity( 'payout', 'sync_payouts', $withdrawal_id );
	}
}
