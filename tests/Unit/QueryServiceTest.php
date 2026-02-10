<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Query_Service;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Query_Service.
 *
 * Verifies correct pagination, filtering, and wpdb delegation.
 */
class QueryServiceTest extends TestCase {

	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;
	}

	// ─── get_queue_jobs() ──────────────────────────────────

	public function test_get_queue_jobs_returns_correct_structure(): void {
		$this->wpdb->get_var_return = 5;
		$this->wpdb->get_results_return = [
			(object) [ 'id' => 1, 'status' => 'pending' ],
			(object) [ 'id' => 2, 'status' => 'completed' ],
		];

		$result = Query_Service::get_queue_jobs();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'pages', $result );
		$this->assertCount( 2, $result['items'] );
		$this->assertSame( 5, $result['total'] );
	}

	public function test_get_queue_jobs_returns_empty_items_when_none_found(): void {
		$this->wpdb->get_var_return = 0;
		$this->wpdb->get_results_return = [];

		$result = Query_Service::get_queue_jobs();

		$this->assertSame( [], $result['items'] );
		$this->assertSame( 0, $result['total'] );
		$this->assertSame( 0, $result['pages'] );
	}

	public function test_get_queue_jobs_calculates_pages_correctly(): void {
		$this->wpdb->get_var_return = 95;
		$this->wpdb->get_results_return = [];

		$result = Query_Service::get_queue_jobs( 1, 30 );

		$this->assertSame( 4, $result['pages'] );
	}

	public function test_get_queue_jobs_page_1_offset_is_0(): void {
		$this->wpdb->get_var_return = 10;
		$this->wpdb->get_results_return = [];

		Query_Service::get_queue_jobs( 1, 30 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		// Prepare args: [query, per_page, offset]
		$this->assertSame( 30, $prepare[0]['args'][1] );
		$this->assertSame( 0, $prepare[0]['args'][2] );
	}

	public function test_get_queue_jobs_page_2_offset_is_per_page(): void {
		$this->wpdb->get_var_return = 100;
		$this->wpdb->get_results_return = [];

		Query_Service::get_queue_jobs( 2, 30 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		// Offset should be (2-1) * 30 = 30
		$this->assertSame( 30, $prepare[0]['args'][1] );
		$this->assertSame( 30, $prepare[0]['args'][2] );
	}

	public function test_get_queue_jobs_with_status_filter_adds_where(): void {
		$this->wpdb->get_var_return = 3;
		$this->wpdb->get_results_return = [];

		Query_Service::get_queue_jobs( 1, 30, 'failed' );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertCount( 2, $prepare );
		// First prepare call is for WHERE status = %s
		$this->assertStringContainsString( 'WHERE status = %s', $prepare[0]['args'][0] );
		$this->assertSame( 'failed', $prepare[0]['args'][1] );
		// Second prepare call is for SELECT with WHERE
		$this->assertStringContainsString( 'WHERE status = %s', $prepare[1]['args'][0] );
	}

	public function test_get_queue_jobs_without_status_has_no_where(): void {
		$this->wpdb->get_var_return = 10;
		$this->wpdb->get_results_return = [];

		Query_Service::get_queue_jobs( 1, 30, '' );

		$get_var = $this->get_calls( 'get_var' );
		$this->assertNotEmpty( $get_var );
		$query = $get_var[0]['args'][0];
		// Query should not contain WHERE clause
		$this->assertStringNotContainsString( 'WHERE', $query );
	}

	// ─── get_log_entries() ─────────────────────────────────

	public function test_get_log_entries_returns_correct_structure_including_page_key(): void {
		$this->wpdb->get_var_return = 8;
		$this->wpdb->get_results_return = [
			(object) [ 'id' => 1, 'level' => 'info' ],
		];

		$result = Query_Service::get_log_entries( [], 2, 50 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'pages', $result );
		$this->assertArrayHasKey( 'page', $result );
		$this->assertSame( 2, $result['page'] );
		$this->assertSame( 8, $result['total'] );
	}

	public function test_get_log_entries_with_level_filter(): void {
		$this->wpdb->get_var_return = 2;
		$this->wpdb->get_results_return = [];

		Query_Service::get_log_entries( [ 'level' => 'error' ], 1, 50 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		// First prepare is for count query
		$this->assertStringContainsString( 'WHERE', $prepare[0]['args'][0] );
		$this->assertStringContainsString( 'level = %s', $prepare[0]['args'][0] );
		// args[1] is the params array
		$this->assertIsArray( $prepare[0]['args'][1] );
		$this->assertContains( 'error', $prepare[0]['args'][1] );
	}

	public function test_get_log_entries_with_date_from_filter(): void {
		$this->wpdb->get_var_return = 5;
		$this->wpdb->get_results_return = [];

		Query_Service::get_log_entries( [ 'date_from' => '2025-01-01' ], 1, 50 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( 'created_at >= %s', $prepare[0]['args'][0] );
		$this->assertContains( '2025-01-01 00:00:00', $prepare[0]['args'][1] );
	}

	public function test_get_log_entries_with_multiple_filters(): void {
		$this->wpdb->get_var_return = 3;
		$this->wpdb->get_results_return = [];

		Query_Service::get_log_entries(
			[
				'level'  => 'warning',
				'module' => 'crm',
			],
			1,
			50
		);

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		// Count query should have both filters
		$query = $prepare[0]['args'][0];
		$this->assertStringContainsString( 'WHERE', $query );
		$this->assertStringContainsString( 'level = %s', $query );
		$this->assertStringContainsString( 'module = %s', $query );
		$this->assertStringContainsString( 'AND', $query );
		$this->assertContains( 'warning', $prepare[0]['args'][1] );
		$this->assertContains( 'crm', $prepare[0]['args'][1] );
	}

	public function test_get_log_entries_with_no_filters(): void {
		$this->wpdb->get_var_return = 15;
		$this->wpdb->get_results_return = [];

		Query_Service::get_log_entries( [], 1, 50 );

		$get_var = $this->get_calls( 'get_var' );
		$this->assertNotEmpty( $get_var );
		$query = $get_var[0]['args'][0];
		// Query should not contain WHERE clause
		$this->assertStringNotContainsString( 'WHERE', $query );
	}

	public function test_get_log_entries_pages_calculation_with_zero_total(): void {
		$this->wpdb->get_var_return = 0;
		$this->wpdb->get_results_return = [];

		$result = Query_Service::get_log_entries( [], 1, 50 );

		$this->assertSame( 0, $result['pages'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_get_log_entries_with_date_to_filter(): void {
		$this->wpdb->get_var_return = 10;
		$this->wpdb->get_results_return = [];

		Query_Service::get_log_entries( [ 'date_to' => '2025-12-31' ], 1, 50 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( 'created_at <= %s', $prepare[0]['args'][0] );
		$this->assertContains( '2025-12-31 23:59:59', $prepare[0]['args'][1] );
	}

	public function test_get_log_entries_with_module_filter(): void {
		$this->wpdb->get_var_return = 7;
		$this->wpdb->get_results_return = [];

		Query_Service::get_log_entries( [ 'module' => 'sales' ], 1, 50 );

		$prepare = $this->get_calls( 'prepare' );
		$this->assertNotEmpty( $prepare );
		$this->assertStringContainsString( 'module = %s', $prepare[0]['args'][0] );
		$this->assertContains( 'sales', $prepare[0]['args'][1] );
	}

	// ─── Helpers ───────────────────────────────────────────

	private function get_calls( string $method ): array {
		return array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === $method )
		);
	}
}
