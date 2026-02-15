<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advisory lock support for push dedup protection.
 *
 * Prevents TOCTOU race conditions in the search-before-create path
 * of push_to_odoo(). Uses MySQL GET_LOCK/RELEASE_LOCK — same proven
 * pattern as Partner_Service::get_or_create().
 *
 * @package WP4Odoo
 * @since   3.2.5
 */
trait Push_Lock {

	/**
	 * Acquire an advisory lock for push dedup protection.
	 *
	 * @param string $lock_name MySQL advisory lock name.
	 * @return bool True if lock acquired within 5 seconds.
	 */
	private function acquire_push_lock( string $lock_name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock_name, 5 )
		);

		return '1' === (string) $result;
	}

	/**
	 * Release an advisory lock for push dedup protection.
	 *
	 * @param string $lock_name MySQL advisory lock name.
	 * @return void
	 */
	private function release_push_lock( string $lock_name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var(
			$wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name )
		);
	}
}
