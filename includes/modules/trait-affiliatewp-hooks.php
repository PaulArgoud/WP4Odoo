<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AffiliateWP hook callbacks.
 *
 * Listens to AffiliateWP status change hooks and enqueues
 * sync jobs for affiliates and referrals.
 *
 * Composed into AffiliateWP_Module via `use AffiliateWP_Hooks`.
 *
 * @package WP4Odoo
 * @since   3.1.0
 */
trait AffiliateWP_Hooks {

	/**
	 * Register AffiliateWP hooks.
	 *
	 * Called from boot() after plugin detection.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_affiliates'] ) ) {
			add_action( 'affwp_set_affiliate_status', $this->safe_callback( [ $this, 'on_affiliate_status_change' ] ), 10, 3 );
		}

		if ( ! empty( $settings['sync_referrals'] ) ) {
			add_action( 'affwp_set_referral_status', $this->safe_callback( [ $this, 'on_referral_status_change' ] ), 10, 3 );
		}
	}

	/**
	 * Handle affiliate status change.
	 *
	 * Only syncs when an affiliate becomes active (approved).
	 *
	 * @param int    $affiliate_id Affiliate ID.
	 * @param string $new_status   New status.
	 * @param string $old_status   Previous status.
	 * @return void
	 */
	public function on_affiliate_status_change( int $affiliate_id, string $new_status, string $old_status ): void {
		if ( 'active' !== $new_status ) {
			return;
		}

		$this->push_entity( 'affiliatewp', 'affiliate', 'sync_affiliates', $affiliate_id );
	}

	/**
	 * Handle referral status change.
	 *
	 * Only syncs when a referral becomes unpaid (confirmed) or paid.
	 * Pending referrals are not synced (not yet confirmed).
	 *
	 * @param int    $referral_id Referral ID.
	 * @param string $new_status  New status.
	 * @param string $old_status  Previous status.
	 * @return void
	 */
	public function on_referral_status_change( int $referral_id, string $new_status, string $old_status ): void {
		if ( ! in_array( $new_status, [ 'unpaid', 'paid' ], true ) ) {
			return;
		}

		$this->push_entity( 'affiliatewp', 'referral', 'sync_referrals', $referral_id );
	}
}
