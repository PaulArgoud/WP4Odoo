<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Logger;
use WP4Odoo\Sync_Queue_Repository;

/**
 * Integration tests for multisite blog_id scoping.
 *
 * These tests verify that entity_map, sync_queue, and logger
 * correctly isolate data by blog_id. They work in single-site
 * mode by using explicit blog_id constructor parameters — the
 * column exists in the tables regardless of multisite status.
 *
 * @package WP4Odoo\Tests\Integration
 */
class MultisiteScopingTest extends WP4Odoo_TestCase {

	// ─── Entity Map — blog_id isolation ───────────────────

	public function test_entity_map_saves_with_blog_id(): void {
		global $wpdb;

		$repo = new Entity_Map_Repository( 1 );
		$repo->save( 'crm', 'contact', 10, 42, 'res.partner' );

		$blog_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->prefix}wp4odoo_entity_map WHERE module = %s AND entity_type = %s AND wp_id = %d",
				'crm',
				'contact',
				10
			)
		);

		$this->assertSame( '1', $blog_id );
	}

	public function test_entity_map_isolates_by_blog_id(): void {
		$repo1 = new Entity_Map_Repository( 1 );
		$repo2 = new Entity_Map_Repository( 2 );

		$repo1->save( 'crm', 'contact', 10, 42, 'res.partner' );
		$repo2->save( 'crm', 'contact', 10, 99, 'res.partner' );

		$repo1->flush_cache();
		$repo2->flush_cache();

		// Blog 1 sees odoo_id=42.
		$this->assertSame( 42, $repo1->get_odoo_id( 'crm', 'contact', 10 ) );
		// Blog 2 sees odoo_id=99.
		$this->assertSame( 99, $repo2->get_odoo_id( 'crm', 'contact', 10 ) );
	}

	public function test_entity_map_remove_only_affects_own_blog(): void {
		$repo1 = new Entity_Map_Repository( 1 );
		$repo2 = new Entity_Map_Repository( 2 );

		$repo1->save( 'crm', 'contact', 10, 42, 'res.partner' );
		$repo2->save( 'crm', 'contact', 10, 99, 'res.partner' );

		// Remove from blog 1 only.
		$repo1->remove( 'crm', 'contact', 10 );

		$repo1->flush_cache();
		$repo2->flush_cache();

		$this->assertNull( $repo1->get_odoo_id( 'crm', 'contact', 10 ) );
		$this->assertSame( 99, $repo2->get_odoo_id( 'crm', 'contact', 10 ) );
	}

	// ─── Sync Queue — blog_id isolation ───────────────────

	public function test_sync_queue_enqueues_with_blog_id(): void {
		global $wpdb;

		$repo = new Sync_Queue_Repository( 1 );
		$id   = $repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'action'      => 'create',
		] );

		$blog_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->prefix}wp4odoo_sync_queue WHERE id = %d",
				$id
			)
		);

		$this->assertSame( '1', $blog_id );
	}

	public function test_sync_queue_fetch_pending_isolates_by_blog_id(): void {
		$repo1 = new Sync_Queue_Repository( 1 );
		$repo2 = new Sync_Queue_Repository( 2 );

		$repo1->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 100,
			'action'      => 'create',
		] );

		$repo2->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 200,
			'action'      => 'create',
		] );

		$now   = current_time( 'mysql', true );
		$jobs1 = $repo1->fetch_pending( 100, $now );
		$jobs2 = $repo2->fetch_pending( 100, $now );

		// Each repo should only see its own blog's jobs.
		$wp_ids_1 = array_map( fn( $j ) => $j->wp_id, $jobs1 );
		$wp_ids_2 = array_map( fn( $j ) => $j->wp_id, $jobs2 );

		$this->assertContains( 100, $wp_ids_1 );
		$this->assertNotContains( 200, $wp_ids_1 );
		$this->assertContains( 200, $wp_ids_2 );
		$this->assertNotContains( 100, $wp_ids_2 );
	}

	public function test_sync_queue_stats_scoped_by_blog_id(): void {
		$repo1 = new Sync_Queue_Repository( 1 );
		$repo2 = new Sync_Queue_Repository( 2 );

		$repo1->invalidate_stats_cache();

		$repo1->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 300,
			'action'      => 'create',
		] );

		$repo2->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 301,
			'action'      => 'create',
		] );

		$repo2->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 302,
			'action'      => 'create',
		] );

		// Stats for blog 1 should count only blog 1 jobs.
		$repo1->invalidate_stats_cache();
		$stats1 = $repo1->get_stats();

		$repo2->invalidate_stats_cache();
		$stats2 = $repo2->get_stats();

		// Blog 1 has 1 pending, blog 2 has 2 pending.
		$this->assertSame( 1, $stats1['pending'] );
		$this->assertSame( 2, $stats2['pending'] );
	}

	public function test_sync_queue_cleanup_scoped_by_blog_id(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wp4odoo_sync_queue';

		$repo1 = new Sync_Queue_Repository( 1 );
		$repo2 = new Sync_Queue_Repository( 2 );

		$id1 = $repo1->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 400,
			'action'      => 'create',
		] );

		$id2 = $repo2->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 401,
			'action'      => 'create',
		] );

		// Mark both completed with old dates.
		$repo1->update_status( $id1, 'completed' );
		$repo2->update_status( $id2, 'completed' );
		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$wpdb->update( $table, [ 'created_at' => $old_date ], [ 'id' => $id1 ] );
		$wpdb->update( $table, [ 'created_at' => $old_date ], [ 'id' => $id2 ] );

		// Cleanup for blog 1 only.
		$deleted = $repo1->cleanup( 7 );
		$this->assertSame( 1, $deleted );

		// Blog 2's job should still exist.
		$remaining = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id2 )
		);
		$this->assertSame( '1', $remaining );
	}

	public function test_sync_queue_retry_failed_scoped_by_blog_id(): void {
		$repo1 = new Sync_Queue_Repository( 1 );
		$repo2 = new Sync_Queue_Repository( 2 );

		$id1 = $repo1->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 500,
			'action'      => 'create',
		] );

		$id2 = $repo2->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 501,
			'action'      => 'create',
		] );

		$repo1->update_status( $id1, 'failed', [ 'error_message' => 'error 1' ] );
		$repo2->update_status( $id2, 'failed', [ 'error_message' => 'error 2' ] );

		// Retry only blog 1's failed jobs.
		$reset = $repo1->retry_failed();
		$this->assertSame( 1, $reset );

		// Blog 2's job should still be failed.
		global $wpdb;
		$status2 = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}wp4odoo_sync_queue WHERE id = %d",
				$id2
			)
		);
		$this->assertSame( 'failed', $status2 );
	}

	// ─── Logger — blog_id ─────────────────────────────────

	public function test_logger_inserts_blog_id(): void {
		global $wpdb;

		// Enable logging.
		update_option( 'wp4odoo_log_settings', [
			'enabled' => true,
			'level'   => 'debug',
		] );

		$logger = new Logger( 'test_module' );
		$logger->info( 'Integration test log entry.' );
		Logger::flush_buffer();

		$row = $wpdb->get_row(
			"SELECT blog_id, module, message FROM {$wpdb->prefix}wp4odoo_logs ORDER BY id DESC LIMIT 1"
		);

		$this->assertNotNull( $row );
		$this->assertSame( '1', $row->blog_id );
		$this->assertSame( 'test_module', $row->module );
		$this->assertSame( 'Integration test log entry.', $row->message );
	}

	public function test_logger_cleanup_scoped_by_blog_id(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wp4odoo_logs';

		// Enable logging.
		update_option( 'wp4odoo_log_settings', [
			'enabled'        => true,
			'level'          => 'debug',
			'retention_days' => 7,
		] );

		$logger = new Logger( 'test_module' );
		$logger->info( 'Blog 1 log.' );
		Logger::flush_buffer();

		// Get the log ID.
		$id1 = $wpdb->insert_id;

		// Insert a log for "blog 2" manually (can't switch_to_blog in single-site).
		$wpdb->insert( $table, [
			'blog_id' => 2,
			'level'   => 'info',
			'module'  => 'test_module',
			'message' => 'Blog 2 log.',
		] );
		$id2 = $wpdb->insert_id;

		// Set both to old dates.
		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$wpdb->update( $table, [ 'created_at' => $old_date ], [ 'id' => $id1 ] );
		$wpdb->update( $table, [ 'created_at' => $old_date ], [ 'id' => $id2 ] );

		// Cleanup (runs in blog_id=1 context).
		$deleted = $logger->cleanup();
		$this->assertGreaterThanOrEqual( 1, $deleted );

		// Blog 2's log should still exist.
		$remaining = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id2 )
		);
		$this->assertSame( '1', $remaining );
	}
}
