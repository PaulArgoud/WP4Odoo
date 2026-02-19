<?php
/**
 * FooEvents for WooCommerce stub for unit tests.
 *
 * Defines the FOOEVENTS_VERSION constant and FooEvents class
 * used for plugin detection.
 *
 * @package WP4Odoo\Tests\Stubs
 */

if ( ! defined( 'FOOEVENTS_VERSION' ) ) {
	define( 'FOOEVENTS_VERSION', '1.19.0' );
}

if ( ! class_exists( 'FooEvents' ) ) {
	class FooEvents {
		public static string $version = '1.19.0';
	}
}
