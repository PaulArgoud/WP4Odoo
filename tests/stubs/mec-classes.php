<?php
/**
 * Modern Events Calendar stub for unit tests.
 *
 * Defines the MEC_VERSION constant and MEC class used for plugin detection.
 *
 * @package WP4Odoo\Tests\Stubs
 */

if ( ! defined( 'MEC_VERSION' ) ) {
	define( 'MEC_VERSION', '7.12.0' );
}

if ( ! class_exists( 'MEC' ) ) {
	class MEC {
		public static string $version = '7.12.0';
	}
}
