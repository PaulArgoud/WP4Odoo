<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Circuit breaker for Odoo connectivity.
 *
 * Tracks consecutive all-fail batches and pauses queue processing
 * when Odoo appears unreachable, avoiding wasted RPC calls and
 * log flooding during outages.
 *
 * States:
 * - Closed (normal): processing proceeds normally.
 * - Open (tripped): processing is skipped entirely.
 * - Half-open (probe): one batch is allowed to test recovery.
 *
 * Uses WordPress transients for lightweight, auto-expiring state.
 *
 * @package WP4Odoo
 * @since   2.7.0
 */
class Circuit_Breaker {

	/**
	 * Number of consecutive all-fail batches before opening the circuit.
	 */
	private const FAILURE_THRESHOLD = 3;

	/**
	 * Seconds to wait before allowing a probe batch (half-open state).
	 */
	private const RECOVERY_DELAY = 300;

	/**
	 * Transient key for consecutive batch failure count.
	 */
	private const KEY_FAILURES = 'wp4odoo_cb_failures';

	/**
	 * Transient key for the timestamp when the circuit was opened.
	 */
	private const KEY_OPENED_AT = 'wp4odoo_cb_opened_at';

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Check if queue processing is allowed.
	 *
	 * Returns true when the circuit is closed (normal) or when the
	 * recovery delay has elapsed (half-open, allowing a probe batch).
	 * Returns false when the circuit is open (Odoo unreachable).
	 *
	 * @return bool True if processing is allowed.
	 */
	public function is_available(): bool {
		$opened_at = (int) get_transient( self::KEY_OPENED_AT );

		if ( 0 === $opened_at ) {
			return true;
		}

		if ( ( time() - $opened_at ) >= self::RECOVERY_DELAY ) {
			$this->logger->info( 'Circuit breaker half-open: allowing probe batch.' );
			return true;
		}

		return false;
	}

	/**
	 * Record a successful batch (at least one job succeeded).
	 *
	 * Resets the failure counter and closes the circuit if it was open.
	 *
	 * @return void
	 */
	public function record_success(): void {
		if ( get_transient( self::KEY_OPENED_AT ) ) {
			$this->logger->info( 'Circuit breaker closed: Odoo connection recovered.' );
		}

		delete_transient( self::KEY_FAILURES );
		delete_transient( self::KEY_OPENED_AT );
	}

	/**
	 * Record a failed batch (zero successes, one or more failures).
	 *
	 * Increments the failure counter and opens the circuit when the
	 * threshold is reached.
	 *
	 * @return void
	 */
	public function record_failure(): void {
		$failures = (int) get_transient( self::KEY_FAILURES ) + 1;
		set_transient( self::KEY_FAILURES, $failures, HOUR_IN_SECONDS );

		if ( $failures >= self::FAILURE_THRESHOLD ) {
			set_transient( self::KEY_OPENED_AT, time(), HOUR_IN_SECONDS );

			$this->logger->warning(
				'Circuit breaker opened: Odoo appears unreachable.',
				[
					'consecutive_batch_failures' => $failures,
					'recovery_delay_seconds'     => self::RECOVERY_DELAY,
				]
			);
		}
	}
}
