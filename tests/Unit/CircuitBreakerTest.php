<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Circuit_Breaker;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Circuit_Breaker.
 *
 * Verifies circuit state transitions: closed → open → half-open → closed,
 * threshold counting, and recovery delay behaviour.
 */
class CircuitBreakerTest extends TestCase {

	private Circuit_Breaker $breaker;

	protected function setUp(): void {
		$GLOBALS['_wp_transients'] = [];

		$logger        = new \WP4Odoo\Logger( 'test' );
		$this->breaker = new Circuit_Breaker( $logger );
	}

	// ─── Closed state (default) ──────────────────────────

	public function test_is_available_returns_true_by_default(): void {
		$this->assertTrue( $this->breaker->is_available() );
	}

	public function test_single_failure_does_not_open_circuit(): void {
		$this->breaker->record_failure();

		$this->assertTrue( $this->breaker->is_available() );
	}

	public function test_failures_below_threshold_keep_circuit_closed(): void {
		$this->breaker->record_failure();
		$this->breaker->record_failure();

		$this->assertTrue( $this->breaker->is_available() );
	}

	// ─── Open state ──────────────────────────────────────

	public function test_circuit_opens_after_threshold_failures(): void {
		$this->breaker->record_failure();
		$this->breaker->record_failure();
		$this->breaker->record_failure();

		$this->assertFalse( $this->breaker->is_available() );
	}

	public function test_circuit_stays_open_within_recovery_delay(): void {
		$this->breaker->record_failure();
		$this->breaker->record_failure();
		$this->breaker->record_failure();

		// Still within 5-minute window.
		$this->assertFalse( $this->breaker->is_available() );
	}

	// ─── Half-open state ─────────────────────────────────

	public function test_circuit_allows_probe_after_recovery_delay(): void {
		$this->breaker->record_failure();
		$this->breaker->record_failure();
		$this->breaker->record_failure();

		// Simulate recovery delay elapsed by backdating the opened_at transient.
		$GLOBALS['_wp_transients']['wp4odoo_cb_opened_at'] = time() - 301;

		$this->assertTrue( $this->breaker->is_available() );
	}

	// ─── Recovery ────────────────────────────────────────

	public function test_success_closes_circuit(): void {
		$this->breaker->record_failure();
		$this->breaker->record_failure();
		$this->breaker->record_failure();
		$this->assertFalse( $this->breaker->is_available() );

		$this->breaker->record_success();
		$this->assertTrue( $this->breaker->is_available() );
	}

	public function test_success_resets_failure_counter(): void {
		$this->breaker->record_failure();
		$this->breaker->record_failure();

		$this->breaker->record_success();

		// Two more failures should not open circuit (counter was reset).
		$this->breaker->record_failure();
		$this->breaker->record_failure();
		$this->assertTrue( $this->breaker->is_available() );
	}

	public function test_failure_after_probe_reopens_circuit(): void {
		// Open the circuit.
		$this->breaker->record_failure();
		$this->breaker->record_failure();
		$this->breaker->record_failure();

		// Simulate recovery delay.
		$GLOBALS['_wp_transients']['wp4odoo_cb_opened_at'] = time() - 301;

		// Probe allowed.
		$this->assertTrue( $this->breaker->is_available() );

		// Probe fails — circuit should re-open.
		$this->breaker->record_failure();
		$this->assertFalse( $this->breaker->is_available() );
	}

	public function test_success_when_already_closed_is_noop(): void {
		$this->breaker->record_success();
		$this->assertTrue( $this->breaker->is_available() );
		$this->assertEmpty( $GLOBALS['_wp_transients'] );
	}
}
