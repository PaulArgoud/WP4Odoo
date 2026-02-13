<?php
/**
 * WooCommerce Points & Rewards stub classes for unit tests.
 *
 * @package WP4Odoo\Tests
 */

// ─── Global store ───────────────────────────────────────

$GLOBALS['_wc_points_rewards'] = [];

// ─── Detection class ────────────────────────────────────

if ( ! class_exists( 'WC_Points_Rewards' ) ) {
	class WC_Points_Rewards {
		public static string $version = '1.7.0';
	}
}

// ─── Manager class ──────────────────────────────────────

if ( ! class_exists( 'WC_Points_Rewards_Manager' ) ) {
	/**
	 * Stub for WC_Points_Rewards_Manager.
	 *
	 * Uses $GLOBALS['_wc_points_rewards'][$user_id] for point balances.
	 */
	class WC_Points_Rewards_Manager {

		/**
		 * Get the points balance for a user.
		 *
		 * @param int $user_id WordPress user ID.
		 * @return int Points balance.
		 */
		public static function get_users_points( int $user_id ): int {
			return (int) ( $GLOBALS['_wc_points_rewards'][ $user_id ] ?? 0 );
		}

		/**
		 * Set the points balance for a user.
		 *
		 * @param int    $user_id WordPress user ID.
		 * @param int    $points  New points balance.
		 * @param string $type    Log event type.
		 * @return void
		 */
		public static function set_points_balance( int $user_id, int $points, string $type = 'admin-adjustment' ): void {
			$GLOBALS['_wc_points_rewards'][ $user_id ] = $points;
		}

		/**
		 * Increase points for a user.
		 *
		 * @param int    $user_id WordPress user ID.
		 * @param int    $points  Points to add.
		 * @param string $type    Log event type.
		 * @param mixed  $data    Optional data.
		 * @param int    $order_id Order ID.
		 * @return void
		 */
		public static function increase_points( int $user_id, int $points, string $type = 'order-placed', $data = null, int $order_id = 0 ): void {
			$current = self::get_users_points( $user_id );
			self::set_points_balance( $user_id, $current + $points, $type );
		}

		/**
		 * Decrease points for a user.
		 *
		 * @param int    $user_id WordPress user ID.
		 * @param int    $points  Points to remove.
		 * @param string $type    Log event type.
		 * @param mixed  $data    Optional data.
		 * @param int    $order_id Order ID.
		 * @return void
		 */
		public static function decrease_points( int $user_id, int $points, string $type = 'order-redeem', $data = null, int $order_id = 0 ): void {
			$current = self::get_users_points( $user_id );
			self::set_points_balance( $user_id, max( 0, $current - $points ), $type );
		}
	}
}
