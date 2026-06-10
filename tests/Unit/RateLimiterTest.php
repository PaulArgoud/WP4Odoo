<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Rate_Limiter;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Rate_Limiter.
 *
 * Tests both the transient-based fallback path (no external object cache)
 * and the atomic object cache path (wp_cache_add + wp_cache_incr).
 */
class RateLimiterTest extends TestCase {

	/**
	 * Reset transient and cache stores before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']               = [];
		$GLOBALS['_wp_transients']            = [];
		$GLOBALS['_wp_cache']                 = [];
		$GLOBALS['_wp_using_ext_object_cache'] = false;

		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'enabled' => true,
			'level'   => 'debug',
		];
	}

	/**
	 * Reset object-cache globals so the "external cache" flag does not leak
	 * into later test classes (e.g. Entity_Map_Repository reads it).
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['_wp_cache']                  = [];
		$GLOBALS['_wp_using_ext_object_cache'] = false;
	}

	// ─── Transient path (no external object cache) ───────

	/**
	 * Test that the first request is allowed when under the limit.
	 *
	 * @return void
	 */
	public function test_check_allows_request_under_limit(): void {
		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 5, 60 );

		$result = $limiter->check( '127.0.0.1' );

		$this->assertTrue( $result );
	}

	/**
	 * Test that each check increments the transient counter.
	 *
	 * @return void
	 */
	public function test_check_increments_counter(): void {
		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 5, 60 );
		$key     = 'wp4odoo_rl_' . md5( '127.0.0.1' );

		$limiter->check( '127.0.0.1' );
		$this->assertSame( 1, $GLOBALS['_wp_transients'][ $key ] );

		$limiter->check( '127.0.0.1' );
		$this->assertSame( 2, $GLOBALS['_wp_transients'][ $key ] );

		$limiter->check( '127.0.0.1' );
		$this->assertSame( 3, $GLOBALS['_wp_transients'][ $key ] );
	}

	/**
	 * Test that exceeding the limit returns a WP_Error with code 429.
	 *
	 * @return void
	 */
	public function test_check_returns_error_when_limit_exceeded(): void {
		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 3, 60 );

		// Use up all 3 allowed requests.
		$this->assertTrue( $limiter->check( '10.0.0.1' ) );
		$this->assertTrue( $limiter->check( '10.0.0.1' ) );
		$this->assertTrue( $limiter->check( '10.0.0.1' ) );

		// Fourth request should be rate-limited.
		$result = $limiter->check( '10.0.0.1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp4odoo_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/**
	 * Test that the transient key uses the prefix + md5 of the identifier.
	 *
	 * @return void
	 */
	public function test_check_uses_prefix_and_md5_for_key(): void {
		$limiter    = new Rate_Limiter( 'wp4odoo_rl_', 10, 60 );
		$identifier = '192.168.1.100';
		$expected   = 'wp4odoo_rl_' . md5( $identifier );

		$limiter->check( $identifier );

		$this->assertArrayHasKey( $expected, $GLOBALS['_wp_transients'] );
		$this->assertSame( 1, $GLOBALS['_wp_transients'][ $expected ] );
	}

	/**
	 * Test that a warning is logged when the limit is exceeded and a logger is provided.
	 *
	 * @return void
	 */
	public function test_check_logs_when_limit_exceeded(): void {
		$logger = $this->createMock( Logger::class );
		$logger->expects( $this->once() )
			->method( 'warning' )
			->with(
				'Rate limit exceeded.',
				$this->callback( function ( array $ctx ): bool {
					return '10.0.0.1' === $ctx['identifier'] && 1 === $ctx['count'];
				} )
			);

		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 1, 60, $logger );

		// First request fills the limit.
		$limiter->check( '10.0.0.1' );

		// Second request triggers the warning.
		$limiter->check( '10.0.0.1' );
	}

	/**
	 * Test that rate limiting works without error when no logger is provided.
	 *
	 * @return void
	 */
	public function test_check_works_without_logger(): void {
		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 1, 60 );

		$this->assertTrue( $limiter->check( '10.0.0.1' ) );

		$result = $limiter->check( '10.0.0.1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp4odoo_rate_limited', $result->get_error_code() );
	}

	/**
	 * Test that different identifiers have separate counters.
	 *
	 * @return void
	 */
	public function test_different_identifiers_have_separate_counters(): void {
		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 2, 60 );

		// Exhaust limit for IP A.
		$this->assertTrue( $limiter->check( '10.0.0.1' ) );
		$this->assertTrue( $limiter->check( '10.0.0.1' ) );
		$this->assertInstanceOf( \WP_Error::class, $limiter->check( '10.0.0.1' ) );

		// IP B should still be allowed — separate counter.
		$this->assertTrue( $limiter->check( '10.0.0.2' ) );
		$this->assertTrue( $limiter->check( '10.0.0.2' ) );
		$this->assertInstanceOf( \WP_Error::class, $limiter->check( '10.0.0.2' ) );
	}

	// ─── Object cache path (external cache active) ──────

	/**
	 * Test that the object cache path allows requests under the limit.
	 *
	 * @return void
	 */
	public function test_object_cache_allows_request_under_limit(): void {
		$GLOBALS['_wp_using_ext_object_cache'] = true;

		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 5, 60 );

		$this->assertTrue( $limiter->check( '127.0.0.1' ) );
	}

	/**
	 * Test that the object cache path increments via wp_cache_incr.
	 *
	 * @return void
	 */
	public function test_object_cache_increments_counter(): void {
		$GLOBALS['_wp_using_ext_object_cache'] = true;

		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 5, 60 );
		$key     = 'wp4odoo_rl_' . md5( '127.0.0.1' );
		$group   = 'wp4odoo_rate_limit';

		$limiter->check( '127.0.0.1' );
		$this->assertSame( 1, $GLOBALS['_wp_cache'][ $group ][ $key ] );

		$limiter->check( '127.0.0.1' );
		$this->assertSame( 2, $GLOBALS['_wp_cache'][ $group ][ $key ] );

		$limiter->check( '127.0.0.1' );
		$this->assertSame( 3, $GLOBALS['_wp_cache'][ $group ][ $key ] );
	}

	/**
	 * Test that the object cache path returns WP_Error when limit exceeded.
	 *
	 * @return void
	 */
	public function test_object_cache_returns_error_when_limit_exceeded(): void {
		$GLOBALS['_wp_using_ext_object_cache'] = true;

		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 2, 60 );

		$this->assertTrue( $limiter->check( '10.0.0.1' ) );
		$this->assertTrue( $limiter->check( '10.0.0.1' ) );

		$result = $limiter->check( '10.0.0.1' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp4odoo_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/**
	 * Test that the object cache path logs when limit exceeded.
	 *
	 * @return void
	 */
	public function test_object_cache_logs_when_limit_exceeded(): void {
		$GLOBALS['_wp_using_ext_object_cache'] = true;

		$logger = $this->createMock( Logger::class );
		$logger->expects( $this->once() )
			->method( 'warning' )
			->with(
				'Rate limit exceeded.',
				$this->callback( function ( array $ctx ): bool {
					return '10.0.0.1' === $ctx['identifier'] && 2 === $ctx['count'];
				} )
			);

		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 1, 60, $logger );

		// First request is allowed (wp_cache_add succeeds).
		$limiter->check( '10.0.0.1' );

		// Second request triggers the warning (count = 2 > max 1).
		$limiter->check( '10.0.0.1' );
	}

	/**
	 * Test that the object cache path uses separate counters per identifier.
	 *
	 * @return void
	 */
	public function test_object_cache_separate_counters_per_identifier(): void {
		$GLOBALS['_wp_using_ext_object_cache'] = true;

		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 1, 60 );

		$this->assertTrue( $limiter->check( '10.0.0.1' ) );
		$this->assertInstanceOf( \WP_Error::class, $limiter->check( '10.0.0.1' ) );

		// Different IP should still be allowed.
		$this->assertTrue( $limiter->check( '10.0.0.2' ) );
		$this->assertInstanceOf( \WP_Error::class, $limiter->check( '10.0.0.2' ) );
	}

	/**
	 * Test that transient path is used when external object cache is not available.
	 *
	 * @return void
	 */
	public function test_uses_transient_path_without_ext_object_cache(): void {
		$GLOBALS['_wp_using_ext_object_cache'] = false;

		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 5, 60 );
		$key     = 'wp4odoo_rl_' . md5( '127.0.0.1' );

		$limiter->check( '127.0.0.1' );

		// Should use transient, not object cache.
		$this->assertSame( 1, $GLOBALS['_wp_transients'][ $key ] );
		$this->assertArrayNotHasKey( 'wp4odoo_rate_limit', $GLOBALS['_wp_cache'] );
	}

	/**
	 * Test that object cache path is used when external object cache is available.
	 *
	 * @return void
	 */
	public function test_uses_object_cache_path_with_ext_object_cache(): void {
		$GLOBALS['_wp_using_ext_object_cache'] = true;

		$limiter = new Rate_Limiter( 'wp4odoo_rl_', 5, 60 );
		$key     = 'wp4odoo_rl_' . md5( '127.0.0.1' );

		$limiter->check( '127.0.0.1' );

		// Should use object cache, not transient.
		$this->assertSame( 1, $GLOBALS['_wp_cache']['wp4odoo_rate_limit'][ $key ] );
		$this->assertArrayNotHasKey( $key, $GLOBALS['_wp_transients'] );
	}
}
