<?php
/**
 * Plugin uninstall handler.
 *
 * Removes all plugin data: database tables, options, and cron events.
 * Only runs when the plugin is deleted through the WordPress admin.
 *
 * @package WP4Odoo
 * @since   1.0.2
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp4odoo_sync_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp4odoo_entity_map" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wp4odoo_logs" );

// Remove all plugin options.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'wp4odoo\_%' ) );

// Delete custom post type posts (leads, orders, invoices).
$cpt_types = [ 'wp4odoo_lead', 'wp4odoo_order', 'wp4odoo_invoice', 'wp4odoo_spay' ];
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

// Remove plugin transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_wp4odoo\_%', '_transient_timeout_wp4odoo\_%' ) );

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'wp4odoo_scheduled_sync' );
wp_clear_scheduled_hook( 'wp4odoo_bookly_poll' );
wp_clear_scheduled_hook( 'wp4odoo_ecwid_poll' );
