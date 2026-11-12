<?php
/**
 * Plugin uninstall handler.
 *
 * Removes everything the plugin created: database tables, options (including
 * network options on multisite), transients, post meta added to other content,
 * the plugin's own custom post type posts, persistent object-cache entries and
 * scheduled events. Content created in other post types by synchronization
 * (products, orders, documents…) belongs to the site and is kept.
 *
 * Only runs when the plugin is deleted through the WordPress admin. On a
 * multisite network every site is cleaned.
 *
 * @package WP4Odoo
 * @since   1.0.2
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'wp4odoo_uninstall_site' ) ) {
	/**
	 * Remove the plugin's data from the current site.
	 *
	 * @return void
	 */
	function wp4odoo_uninstall_site(): void {
		global $wpdb;

		// Drop custom tables.
		foreach ( [ 'wp4odoo_sync_queue', 'wp4odoo_entity_map', 'wp4odoo_logs' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		// Delete the plugin's own custom post type posts (and, with them, their meta).
		$cpt_types = [ 'wp4odoo_lead', 'wp4odoo_order', 'wp4odoo_invoice', 'wp4odoo_spay', 'wp4odoo_fs_task' ];
		foreach ( $cpt_types as $cpt ) {
			$cpt_ids = get_posts(
				[
					'post_type'      => $cpt,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				]
			);
			foreach ( $cpt_ids as $cpt_post_id ) {
				wp_delete_post( $cpt_post_id, true );
			}
		}

		// Remove the meta the plugin added to other content (products, media, users, terms, HPOS orders).
		$meta_tables = [
			$wpdb->postmeta,
			$wpdb->usermeta,
			$wpdb->termmeta,
			$wpdb->prefix . 'wc_orders_meta',
		];
		foreach ( $meta_tables as $meta_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $meta_table ) ) ) !== $meta_table ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta_table} WHERE meta_key LIKE %s OR meta_key LIKE %s", '\_wp4odoo\_%', 'wp4odoo\_%' ) );
		}

		// Remove all plugin options, transients and site transients (stored as options on a single site).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				'wp4odoo\_%',
				'\_transient\_wp4odoo\_%',
				'\_transient\_timeout\_wp4odoo\_%',
				'\_site\_transient\_wp4odoo\_%',
				'\_site\_transient\_timeout\_wp4odoo\_%'
			)
		);

		// Direct SQL bypassed the options cache: drop it so a persistent object cache does not keep ghosts.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		// Clear scheduled cron events.
		foreach ( [ 'wp4odoo_scheduled_sync', 'wp4odoo_log_cleanup', 'wp4odoo_bookly_poll', 'wp4odoo_ecwid_poll' ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Drop persistent object-cache entries (Redis/Memcached) where the backend supports it.
		if ( function_exists( 'wp_cache_flush_group' ) && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'wp4odoo_entity_map' );
		}
	}
}

global $wpdb;

if ( is_multisite() ) {
	// Every site of the network has its own tables, options and cron events.
	$wp4odoo_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $wp4odoo_site_ids as $wp4odoo_site_id ) {
		switch_to_blog( (int) $wp4odoo_site_id );
		wp4odoo_uninstall_site();
		restore_current_blog();
	}

	// Network-level options (shared connection, site → company mapping) and site transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			'wp4odoo\_%',
			'\_site\_transient\_%wp4odoo\_%'
		)
	);
} else {
	wp4odoo_uninstall_site();
}
