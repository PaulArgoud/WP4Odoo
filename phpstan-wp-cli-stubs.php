<?php
/**
 * WP-CLI namespace stubs for PHPStan.
 *
 * Separated from phpstan-bootstrap.php because PHP requires
 * namespace declarations to be the first statement in a file
 * (or after declare).
 *
 * @package WP4Odoo
 */

namespace WP_CLI\Utils;

if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
	/**
	 * @param string $format
	 * @param array  $items
	 * @param array  $fields
	 */
	function format_items( string $format, array $items, array $fields ): void {}
}
