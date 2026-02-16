<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dokan hook callbacks.
 *
 * Listens to Dokan marketplace hooks and enqueues sync jobs for
 * vendors, sub-orders, commissions, and payouts.
 *
 * Composed into Dokan_Module via `use Dokan_Hooks`.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
trait Dokan_Hooks {

	/**
	 * Register Dokan hooks.
	 *
	 * Called from boot() after plugin detection.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_vendors'] ) ) {
			add_action( 'dokan_new_seller_created', $this->safe_callback( [ $this, 'on_vendor_created' ] ), 10, 1 );
			add_action( 'dokan_store_profile_saved', $this->safe_callback( [ $this, 'on_vendor_updated' ] ), 10, 2 );
		}

		if ( ! empty( $settings['sync_sub_orders'] ) ) {
			add_action( 'dokan_checkout_update_order_meta', $this->safe_callback( [ $this, 'on_sub_order_created' ] ), 10, 1 );
			add_action( 'dokan_order_status_change', $this->safe_callback( [ $this, 'on_sub_order_updated' ] ), 10, 3 );
		}

		if ( ! empty( $settings['sync_commissions'] ) ) {
			add_action( 'dokan_order_status_change', $this->safe_callback( [ $this, 'on_commission_sync' ] ), 20, 3 );
		}

		if ( ! empty( $settings['sync_payouts'] ) ) {
			add_action( 'dokan_withdraw_request_approved', $this->safe_callback( [ $this, 'on_payout_created' ] ), 10, 1 );
		}
	}

	/**
	 * Handle new vendor creation.
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
	 * Handle vendor profile update.
	 *
	 * @param int   $store_id Vendor user ID.
	 * @param array $data     Updated profile data.
	 * @return void
	 */
	public function on_vendor_updated( int $store_id, array $data = [] ): void {
		if ( ! $this->should_sync( 'sync_vendors' ) ) {
			return;
		}

		$this->push_entity( 'vendor', 'sync_vendors', $store_id );
	}

	/**
	 * Handle sub-order creation during checkout.
	 *
	 * @param int $order_id WC sub-order ID.
	 * @return void
	 */
	public function on_sub_order_created( int $order_id ): void {
		if ( ! $this->should_sync( 'sync_sub_orders' ) ) {
			return;
		}

		$this->push_entity( 'sub_order', 'sync_sub_orders', $order_id );
	}

	/**
	 * Handle sub-order status change.
	 *
	 * @param int    $order_id   WC sub-order ID.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @return void
	 */
	public function on_sub_order_updated( int $order_id, string $old_status = '', string $new_status = '' ): void {
		if ( ! $this->should_sync( 'sync_sub_orders' ) ) {
			return;
		}

		$this->push_entity( 'sub_order', 'sync_sub_orders', $order_id );
	}

	/**
	 * Handle commission sync when order status changes to completed.
	 *
	 * Commissions are generated when orders reach completed status.
	 *
	 * @param int    $order_id   WC sub-order ID.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @return void
	 */
	public function on_commission_sync( int $order_id, string $old_status = '', string $new_status = '' ): void {
		if ( 'completed' !== $new_status ) {
			return;
		}

		if ( ! $this->should_sync( 'sync_commissions' ) ) {
			return;
		}

		$this->push_entity( 'commission', 'sync_commissions', $order_id );
	}

	/**
	 * Handle payout (withdrawal) approval.
	 *
	 * @param int $withdraw_id Dokan withdraw ID.
	 * @return void
	 */
	public function on_payout_created( int $withdraw_id ): void {
		if ( ! $this->should_sync( 'sync_payouts' ) ) {
			return;
		}

		$this->push_entity( 'payout', 'sync_payouts', $withdraw_id );
	}
}
