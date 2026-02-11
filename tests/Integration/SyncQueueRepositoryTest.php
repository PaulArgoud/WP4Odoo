<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

use WP4Odoo\Sync_Queue_Repository;

/**
 * Integration tests for Sync_Queue_Repository.
 *
 * Validates real MySQL queue operations: enqueue with deduplication,
 * fetch ordering, status transitions, cleanup, and stats.
 *
 * @package WP4Odoo\Tests\Integration
 */
class SyncQueueRepositoryTest extends WP4Odoo_TestCase {

	private Sync_Queue_Repository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new Sync_Queue_Repository();
	}

	// ─── enqueue ───────────────────────────────────────────

	public function test_enqueue_creates_job(): void {
		$id = $this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'action'      => 'create',
		] );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_enqueue_deduplicates_pending_job(): void {
		$id1 = $this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'action'      => 'create',
		] );

		$id2 = $this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 10,
			'action'      => 'update',
		] );

		$this->assertSame( $id1, $id2 );
	}

	public function test_enqueue_returns_false_for_empty_module(): void {
		$result = $this->repo->enqueue( [
			'module'      => '',
			'entity_type' => 'contact',
		] );

		$this->assertFalse( $result );
	}

	// ─── fetch_pending ─────────────────────────────────────

	public function test_fetch_pending_respects_priority_ordering(): void {
		$this->repo->enqueue( [
			'module'      => 'woocommerce',
			'direction'   => 'odoo_to_wp',
			'entity_type' => 'product',
			'odoo_id'     => 1,
			'action'      => 'create',
			'priority'    => 8,
		] );

		$this->repo->enqueue( [
			'module'      => 'woocommerce',
			'direction'   => 'odoo_to_wp',
			'entity_type' => 'product',
			'odoo_id'     => 2,
			'action'      => 'create',
			'priority'    => 2,
		] );

		$now  = current_time( 'mysql', true );
		$jobs = $this->repo->fetch_pending( 10, $now );

		$this->assertCount( 2, $jobs );
		$this->assertSame( '2', $jobs[0]->priority );
		$this->assertSame( '8', $jobs[1]->priority );
	}

	public function test_fetch_pending_excludes_future_scheduled_jobs(): void {
		global $wpdb;

		$id = $this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 50,
			'action'      => 'update',
		] );

		$future = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$wpdb->update(
			$wpdb->prefix . 'wp4odoo_sync_queue',
			[ 'scheduled_at' => $future ],
			[ 'id' => $id ]
		);

		$now  = current_time( 'mysql', true );
		$jobs = $this->repo->fetch_pending( 10, $now );

		$ids = array_map( fn( $j ) => (int) $j->id, $jobs );
		$this->assertNotContains( $id, $ids );
	}

	// ─── update_status ─────────────────────────────────────

	public function test_update_status_changes_job_status(): void {
		global $wpdb;

		$id = $this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 20,
			'action'      => 'update',
		] );

		$this->repo->update_status( $id, 'completed', [
			'processed_at' => current_time( 'mysql', true ),
		] );

		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}wp4odoo_sync_queue WHERE id = %d",
				$id
			)
		);

		$this->assertSame( 'completed', $status );
	}

	// ─── cancel ────────────────────────────────────────────

	public function test_cancel_deletes_pending_job(): void {
		$id = $this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 30,
			'action'      => 'create',
		] );

		$this->assertTrue( $this->repo->cancel( $id ) );
	}

	public function test_cancel_ignores_non_pending_job(): void {
		$id = $this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 31,
			'action'      => 'create',
		] );

		$this->repo->update_status( $id, 'processing' );

		$this->assertFalse( $this->repo->cancel( $id ) );
	}

	// ─── cleanup ───────────────────────────────────────────

	public function test_cleanup_removes_old_completed_jobs(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'wp4odoo_sync_queue';

		$id = $this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 40,
			'action'      => 'update',
		] );

		$this->repo->update_status( $id, 'completed' );

		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$wpdb->update( $table, [ 'created_at' => $old_date ], [ 'id' => $id ] );

		$deleted = $this->repo->cleanup( 7 );
		$this->assertSame( 1, $deleted );
	}

	// ─── retry_failed ──────────────────────────────────────

	public function test_retry_failed_resets_to_pending(): void {
		$id = $this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 41,
			'action'      => 'update',
		] );

		$this->repo->update_status( $id, 'failed', [
			'error_message' => 'Connection timeout',
			'attempts'      => 3,
		] );

		$reset = $this->repo->retry_failed();
		$this->assertSame( 1, $reset );

		global $wpdb;
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}wp4odoo_sync_queue WHERE id = %d",
				$id
			)
		);
		$this->assertSame( 'pending', $status );
	}

	// ─── get_stats ─────────────────────────────────────────

	public function test_get_stats_returns_correct_counts(): void {
		$this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 60,
			'action'      => 'create',
		] );

		$id2 = $this->repo->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'wp_id'       => 61,
			'action'      => 'create',
		] );

		$this->repo->update_status( $id2, 'completed', [
			'processed_at' => current_time( 'mysql', true ),
		] );

		$stats = $this->repo->get_stats();

		$this->assertGreaterThanOrEqual( 1, $stats['pending'] );
		$this->assertGreaterThanOrEqual( 1, $stats['completed'] );
		$this->assertGreaterThanOrEqual( 2, $stats['total'] );
	}
}
