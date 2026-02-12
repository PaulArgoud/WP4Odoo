<?php
/**
 * WooCommerce Subscriptions stub classes for unit tests.
 *
 * @package WP4Odoo\Tests
 */

// ─── Global store ───────────────────────────────────────

$GLOBALS['_wc_subscriptions'] = [];

// ─── Detection class ────────────────────────────────────

if ( ! class_exists( 'WC_Subscriptions' ) ) {
	class WC_Subscriptions {
		public static string $version = '6.0.0';
	}
}

// ─── Subscription class ─────────────────────────────────

if ( ! class_exists( 'WC_Subscription' ) ) {
	/**
	 * Stub for WC_Subscription (extends WC_Order).
	 *
	 * Loads data from $GLOBALS['_wc_subscriptions'][$id].
	 */
	class WC_Subscription extends WC_Order {
		public function __construct( int $id = 0 ) {
			parent::__construct( $id );
			if ( isset( $GLOBALS['_wc_subscriptions'][ $id ] ) ) {
				$this->data = $GLOBALS['_wc_subscriptions'][ $id ];
			}
		}

		public function get_billing_period(): string {
			return $this->data['billing_period'] ?? 'month';
		}

		public function get_billing_interval(): int {
			return (int) ( $this->data['billing_interval'] ?? 1 );
		}

		/**
		 * Get a subscription date.
		 *
		 * @param string $type Date type: 'start_date', 'next_payment', 'end', 'trial_end'.
		 * @return string
		 */
		public function get_date( string $type ): string {
			return $this->data[ $type ] ?? '';
		}

		public function get_parent_id(): int {
			return (int) ( $this->data['parent_id'] ?? 0 );
		}

		/**
		 * @return array<int, array<string, mixed>>
		 */
		public function get_items(): array {
			return $this->data['items'] ?? [];
		}

		public function get_user_id(): int {
			return (int) ( $this->data['user_id'] ?? $this->data['customer_id'] ?? 0 );
		}

		/**
		 * Update subscription status.
		 *
		 * @param string $new_status New status (without 'wc-' prefix).
		 * @return void
		 */
		public function update_status( string $new_status ): void {
			$this->data['status'] = $new_status;
			$GLOBALS['_wc_subscriptions'][ $this->id ]['status'] = $new_status;
		}
	}
}

// ─── Helper functions ───────────────────────────────────

if ( ! function_exists( 'wcs_get_subscription' ) ) {
	/**
	 * @param int $subscription_id Subscription ID.
	 * @return WC_Subscription|false
	 */
	function wcs_get_subscription( int $subscription_id = 0 ) {
		if ( isset( $GLOBALS['_wc_subscriptions'][ $subscription_id ] ) ) {
			return new WC_Subscription( $subscription_id );
		}
		return false;
	}
}
