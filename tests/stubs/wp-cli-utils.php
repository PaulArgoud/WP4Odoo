<?php
/**
 * WP_CLI\Utils namespace stubs for PHPUnit tests.
 *
 * @package WP4Odoo\Tests
 */

namespace WP_CLI\Utils;

if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
	function format_items( $format, $items, $fields ) {
		// Recorded so tests can inspect what a command printed.
		\WP_CLI::$calls[] = [ 'method' => 'format_items', 'args' => [ $format, $items, $fields ] ];
	}
}
