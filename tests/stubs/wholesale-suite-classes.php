<?php
/**
 * Wholesale Suite / B2BKing stub classes for unit tests.
 *
 * @package WP4Odoo\Tests
 */

// ─── Global stores ──────────────────────────────────────

$GLOBALS['_wwp_wholesale_roles']  = [];
$GLOBALS['_wwp_wholesale_prices'] = [];

// ─── Detection constant ────────────────────────────────

if ( ! defined( 'WWP_PLUGIN_VERSION' ) ) {
	define( 'WWP_PLUGIN_VERSION', '2.10.0' );
}

// ─── B2BKing detection class ───────────────────────────

if ( ! class_exists( 'B2bking' ) ) {
	class B2bking {
		public static string $version = '4.0.0';
	}
}

// ─── Helper functions ──────────────────────────────────

if ( ! function_exists( 'wwp_get_wholesale_role_for_user' ) ) {
	/**
	 * Get the wholesale role for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Wholesale role slug, or empty string.
	 */
	function wwp_get_wholesale_role_for_user( int $user_id ): string {
		return $GLOBALS['_wwp_wholesale_roles'][ $user_id ] ?? '';
	}
}

if ( ! function_exists( 'wwp_get_product_wholesale_price' ) ) {
	/**
	 * Get the wholesale price for a product.
	 *
	 * @param int    $product_id WC product ID.
	 * @param string $role       Wholesale role slug.
	 * @return float|false Wholesale price, or false if not set.
	 */
	function wwp_get_product_wholesale_price( int $product_id, string $role ) {
		return $GLOBALS['_wwp_wholesale_prices'][ $product_id ][ $role ] ?? false;
	}
}
