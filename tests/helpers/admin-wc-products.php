<?php
/**
 * Namespaced override of wc_get_products() for the admin classes under test.
 *
 * PHP resolves an unqualified function call in namespace WP4Odoo\Admin to this
 * function first, so tests can feed product IDs through $GLOBALS['_wc_products'].
 * Without that global it returns an empty list, like the global stub.
 *
 * @package WP4Odoo\Tests
 */

namespace WP4Odoo\Admin;

if ( ! function_exists( __NAMESPACE__ . '\\wc_get_products' ) ) {
	/**
	 * @param array<string, mixed> $args Query arguments (ignored).
	 * @return array<int, int>
	 */
	function wc_get_products( $args = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $GLOBALS['_wc_products'] ?? [];
	}
}
