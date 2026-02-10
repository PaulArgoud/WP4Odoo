<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

use WP4Odoo\Sync_Engine;

/**
 * Integration tests for Sync_Engine advisory locking.
 *
 * Validates MySQL GET_LOCK / RELEASE_LOCK behavior
 * and empty queue handling.
 *
 * @package WP4Odoo\Tests\Integration
 */
class SyncEngineLockTest extends WP4Odoo_TestCase {

	public function test_process_queue_returns_zero_when_queue_empty(): void {
		$engine = new Sync_Engine();
		$result = $engine->process_queue();

		$this->assertSame( 0, $result );
	}

	public function test_advisory_lock_is_released_after_processing(): void {
		global $wpdb;

		$engine = new Sync_Engine();
		$engine->process_queue();

		$is_free = $wpdb->get_var(
			$wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', 'wp4odoo_sync' )
		);

		$this->assertSame( '1', $is_free );
	}
}
