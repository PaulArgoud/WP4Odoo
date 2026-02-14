<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Module_Base;
use WP4Odoo\Sync_Result;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub exposing safe_callback() for testing.
 */
class SafeCallbackTestModule extends Module_Base {

	/**
	 * Captured log calls: [ [ level, message, context ], ... ].
	 *
	 * @var array<int, array{string, string, array}>
	 */
	public array $log_calls = [];

	public function __construct() {
		parent::__construct( 'safe_cb', 'SafeCallback', wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		// Replace logger with a spy that captures calls.
		$spy          = new class() extends \WP4Odoo\Logger {
			/** @var array */
			public array $calls = [];

			public function __construct() {
				// Skip parent constructor (needs $wpdb).
			}

			public function critical( string $message, array $context = [] ): bool {
				$this->calls[] = [ 'critical', $message, $context ];
				return true;
			}
		};
		$this->logger = $spy;
	}

	public function boot(): void {}
	public function get_default_settings(): array {
		return [];
	}

	/**
	 * Expose safe_callback() for testing.
	 *
	 * @param callable $callback Callback to wrap.
	 * @return \Closure
	 */
	public function wrap( callable $callback ): \Closure {
		return $this->safe_callback( $callback );
	}

	/**
	 * Get the spy logger's captured calls.
	 *
	 * @return array
	 */
	public function get_log_calls(): array {
		return $this->logger->calls;
	}
}

/**
 * Tests for Module_Base::safe_callback().
 */
class SafeCallbackTest extends TestCase {

	private SafeCallbackTestModule $module;

	protected function setUp(): void {
		$this->module = new SafeCallbackTestModule();
	}

	// ─── Argument forwarding ──────────────────────────────

	public function test_forwards_zero_arguments(): void {
		$called = false;
		$cb     = $this->module->wrap( function () use ( &$called ): void {
			$called = true;
		} );

		$cb();

		$this->assertTrue( $called );
	}

	public function test_forwards_one_argument(): void {
		$received = null;
		$cb       = $this->module->wrap( function ( int $id ) use ( &$received ): void {
			$received = $id;
		} );

		$cb( 42 );

		$this->assertSame( 42, $received );
	}

	public function test_forwards_two_arguments(): void {
		$received = [];
		$cb       = $this->module->wrap( function ( string $a, string $b ) use ( &$received ): void {
			$received = [ $a, $b ];
		} );

		$cb( 'foo', 'bar' );

		$this->assertSame( [ 'foo', 'bar' ], $received );
	}

	public function test_forwards_four_arguments(): void {
		$received = [];
		$cb       = $this->module->wrap( function ( $a, $b, $c, $d ) use ( &$received ): void {
			$received = [ $a, $b, $c, $d ];
		} );

		$cb( 1, 2, 3, 4 );

		$this->assertSame( [ 1, 2, 3, 4 ], $received );
	}

	// ─── Exception handling ───────────────────────────────

	public function test_catches_runtime_exception(): void {
		$cb = $this->module->wrap( function (): void {
			throw new \RuntimeException( 'Third-party broke' );
		} );

		// Must not throw.
		$cb();

		$this->assertCount( 1, $this->module->get_log_calls() );
	}

	public function test_catches_type_error(): void {
		$cb = $this->module->wrap( function (): void {
			throw new \TypeError( 'Type mismatch' );
		} );

		$cb();

		$this->assertCount( 1, $this->module->get_log_calls() );
	}

	public function test_catches_error(): void {
		$cb = $this->module->wrap( function (): void {
			throw new \Error( 'Fatal error simulation' );
		} );

		$cb();

		$this->assertCount( 1, $this->module->get_log_calls() );
	}

	// ─── Logging verification ─────────────────────────────

	public function test_logs_critical_with_expected_context_keys(): void {
		$cb = $this->module->wrap( function (): void {
			throw new \RuntimeException( 'Something broke' );
		} );

		$cb();

		$calls = $this->module->get_log_calls();
		$this->assertCount( 1, $calls );

		[ $level, $message, $context ] = $calls[0];

		$this->assertSame( 'critical', $level );
		$this->assertStringContainsString( 'graceful degradation', $message );
		$this->assertArrayHasKey( 'module', $context );
		$this->assertArrayHasKey( 'exception', $context );
		$this->assertArrayHasKey( 'message', $context );
		$this->assertArrayHasKey( 'file', $context );
		$this->assertArrayHasKey( 'line', $context );
		$this->assertSame( 'safe_cb', $context['module'] );
		$this->assertSame( 'RuntimeException', $context['exception'] );
		$this->assertSame( 'Something broke', $context['message'] );
	}

	// ─── No exception = no log ────────────────────────────

	public function test_no_log_when_no_exception(): void {
		$cb = $this->module->wrap( function (): void {
			// Normal execution — no throw.
		} );

		$cb();

		$this->assertEmpty( $this->module->get_log_calls() );
	}
}
