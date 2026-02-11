<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Failure_Notifier;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Failure_Notifier.
 *
 * Tests threshold logic, counter management, cooldown enforcement, and email dispatch.
 */
class FailureNotifierTest extends TestCase {

	private Failure_Notifier $notifier;

	protected function setUp(): void {
		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_mail_calls'] = [];

		// Seed admin email so wp_mail has a recipient.
		$GLOBALS['_wp_options']['admin_email'] = 'admin@example.com';

		$this->notifier = new Failure_Notifier( new Logger( 'test' ), wp4odoo_test_settings() );
	}

	// ─── Counter reset ───────────────────────────────────────

	public function test_successes_reset_counter(): void {
		$GLOBALS['_wp_options']['wp4odoo_consecutive_failures'] = 3;

		$this->notifier->check( 1, 0 );

		$this->assertSame( 0, $GLOBALS['_wp_options']['wp4odoo_consecutive_failures'] );
	}

	public function test_successes_with_failures_still_reset(): void {
		$GLOBALS['_wp_options']['wp4odoo_consecutive_failures'] = 3;

		$this->notifier->check( 2, 1 );

		$this->assertSame( 0, $GLOBALS['_wp_options']['wp4odoo_consecutive_failures'] );
	}

	// ─── Counter increment ───────────────────────────────────

	public function test_failures_increment_counter(): void {
		$this->notifier->check( 0, 2 );

		$this->assertSame( 2, $GLOBALS['_wp_options']['wp4odoo_consecutive_failures'] );
	}

	public function test_failures_accumulate_across_calls(): void {
		$this->notifier->check( 0, 3 );
		$this->notifier->check( 0, 2 );

		$this->assertSame( 5, $GLOBALS['_wp_options']['wp4odoo_consecutive_failures'] );
	}

	// ─── No-op cases ──────────────────────────────────────────

	public function test_no_op_when_zero_failures_and_zero_successes(): void {
		$this->notifier->check( 0, 0 );

		$this->assertArrayNotHasKey( 'wp4odoo_consecutive_failures', $GLOBALS['_wp_options'] );
	}

	public function test_no_email_below_threshold(): void {
		$this->notifier->check( 0, 4 );

		$this->assertEmpty( $GLOBALS['_wp_mail_calls'] );
	}

	// ─── Email dispatch ──────────────────────────────────────

	public function test_email_sent_at_threshold(): void {
		$this->notifier->check( 0, 5 );

		$this->assertCount( 1, $GLOBALS['_wp_mail_calls'] );
		$this->assertSame( 'admin@example.com', $GLOBALS['_wp_mail_calls'][0]['to'] );
		$this->assertStringContainsString( '5', $GLOBALS['_wp_mail_calls'][0]['subject'] );
	}

	public function test_email_sent_above_threshold(): void {
		$this->notifier->check( 0, 7 );

		$this->assertCount( 1, $GLOBALS['_wp_mail_calls'] );
	}

	// ─── Cooldown ────────────────────────────────────────────

	public function test_cooldown_prevents_second_email(): void {
		// First batch: 5 failures → email sent.
		$this->notifier->check( 0, 5 );
		$this->assertCount( 1, $GLOBALS['_wp_mail_calls'] );

		// Second batch: 3 more failures (total 8), but cooldown active.
		$this->notifier->check( 0, 3 );
		$this->assertCount( 1, $GLOBALS['_wp_mail_calls'] );
	}

	public function test_email_sent_after_cooldown_expires(): void {
		// First email.
		$this->notifier->check( 0, 5 );
		$this->assertCount( 1, $GLOBALS['_wp_mail_calls'] );

		// Simulate cooldown expiry (set last email to > 1 hour ago).
		$GLOBALS['_wp_options']['wp4odoo_last_failure_email'] = time() - 3601;

		// More failures → new email.
		$this->notifier->check( 0, 3 );
		$this->assertCount( 2, $GLOBALS['_wp_mail_calls'] );
	}

	// ─── Edge cases ──────────────────────────────────────────

	public function test_no_email_without_admin_email(): void {
		$GLOBALS['_wp_options']['admin_email'] = '';

		$this->notifier->check( 0, 10 );

		$this->assertEmpty( $GLOBALS['_wp_mail_calls'] );
	}

	public function test_last_failure_email_timestamp_set(): void {
		$this->notifier->check( 0, 5 );

		$this->assertArrayHasKey( 'wp4odoo_last_failure_email', $GLOBALS['_wp_options'] );
		$this->assertGreaterThan( 0, $GLOBALS['_wp_options']['wp4odoo_last_failure_email'] );
	}
}
