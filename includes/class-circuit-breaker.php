<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Circuit breaker for Odoo connectivity.
 *
 * Tracks consecutive high-failure batches and pauses queue processing
 * when Odoo appears unreachable, avoiding wasted RPC calls and
 * log flooding during outages. Uses ratio-based threshold (80%+
 * failures) instead of binary all-or-nothing detection.
 *
 * States:
 * - Closed (normal): processing proceeds normally.
 * - Open (tripped): processing is skipped entirely.
 * - Half-open (probe): one batch is allowed to test recovery.
 *
 * Uses WordPress transients as the fast path, with DB-backed
 * fallback (wp_options) to survive object cache flushes.
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
	 * Failure ratio threshold for considering a batch as "failed".
	 *
	 * When 80%+ of jobs in a batch fail, the batch counts as a failure
	 * even if a few jobs succeeded. This detects partial degradation.
	 */
	private const FAILURE_RATIO = 0.8;

	/**
	 * Transient key for consecutive batch failure count.
	 */
	private const KEY_FAILURES = 'wp4odoo_cb_failures';

	/**
	 * Transient key for the timestamp when the circuit was opened.
	 */
	private const KEY_OPENED_AT = 'wp4odoo_cb_opened_at';

	/**
	 * Transient key for the half-open probe mutex.
	 *
	 * Prevents multiple concurrent processes from all sending probe batches.
	 */
	private const KEY_PROBE = 'wp4odoo_cb_probe';

	/**
	 * TTL for the probe mutex transient (seconds).
	 *
	 * Must exceed RECOVERY_DELAY + BATCH_TIME_LIMIT (355 s) to prevent
	 * overlapping probes: after the first probe transient expires, a
	 * second probe could start before the recovery window closes.
	 */
	private const PROBE_TTL = 360;

	/**
	 * wp_options key for DB-backed circuit breaker state.
	 *
	 * Survives object cache flushes, ensuring the circuit stays open
	 * during Odoo outages even when Redis/Memcached is restarted.
	 * Transients remain the fast path; DB is the fallback.
	 */
	public const OPT_CB_STATE = 'wp4odoo_cb_state';

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Optional failure notifier for circuit breaker open alerts.
	 *
	 * @var Failure_Notifier|null
	 */
	private ?Failure_Notifier $failure_notifier = null;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Set the failure notifier for circuit breaker open alerts.
	 *
	 * @param Failure_Notifier $notifier Failure notifier instance.
	 * @return void
	 */
	public function set_failure_notifier( Failure_Notifier $notifier ): void {
		$this->failure_notifier = $notifier;
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

		// Fallback: if transient was lost (cache flush), check DB.
		// Concurrent restorations are harmless — all processes read the
		// same DB state and set_transient is idempotent with same values.
		if ( 0 === $opened_at ) {
			$db_state  = get_option( self::OPT_CB_STATE, [] );
			$opened_at = (int) ( is_array( $db_state ) ? ( $db_state['opened_at'] ?? 0 ) : 0 );
			if ( $opened_at > 0 ) {
				// Discard stale DB state (older than 1h) — prevents a
				// forever-open circuit if record_success() was never called.
				if ( ( time() - $opened_at ) > HOUR_IN_SECONDS ) {
					delete_option( self::OPT_CB_STATE );
					$opened_at = 0;
				} else {
					// Restore transients from DB state.
					set_transient( self::KEY_OPENED_AT, $opened_at, HOUR_IN_SECONDS );
					set_transient( self::KEY_FAILURES, (int) ( $db_state['failures'] ?? self::FAILURE_THRESHOLD ), HOUR_IN_SECONDS );
				}
			}
		}

		if ( 0 === $opened_at ) {
			return true;
		}

		if ( ( time() - $opened_at ) >= self::RECOVERY_DELAY ) {
			if ( ! $this->try_acquire_probe() ) {
				return false;
			}

			$this->logger->info( 'Circuit breaker half-open: allowing probe batch.' );
			return true;
		}

		return false;
	}

	/**
	 * Atomically acquire the probe mutex.
	 *
	 * Uses a MySQL advisory lock to prevent TOCTOU races where
	 * multiple processes see KEY_PROBE as empty and all start
	 * probe batches simultaneously. Same proven pattern as
	 * Sync_Engine's queue processing lock.
	 *
	 * @return bool True if this process acquired the probe slot.
	 */
	private function try_acquire_probe(): bool {
		// Fast path: if probe transient is already set, skip the lock.
		if ( false !== get_transient( self::KEY_PROBE ) ) {
			return false;
		}

		global $wpdb;

		// Non-blocking advisory lock (timeout = 0).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$locked = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', 'wp4odoo_cb_probe', 0 )
		);

		if ( '1' !== (string) $locked ) {
			return false;
		}

		try {
			// Double-check under lock: another process may set the transient
			// between our fast-path check and lock acquisition.
			if ( false !== get_transient( self::KEY_PROBE ) ) { // @phpstan-ignore notIdentical.alwaysFalse
				return false;
			}

			set_transient( self::KEY_PROBE, 1, self::PROBE_TTL );
			return true;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', 'wp4odoo_cb_probe' )
			);
		}
	}

	/**
	 * Record a batch outcome using failure ratio.
	 *
	 * A batch is considered "failed" when the failure ratio exceeds the
	 * threshold (default 80%). This catches partial degradation where a
	 * single lucky success would otherwise reset the counter.
	 *
	 * Design decision: the circuit breaker operates at the BATCH level,
	 * not the individual job level. This is intentional:
	 * - A few individual failures within a healthy batch are normal
	 *   (bad data, missing fields, etc.) and should not trip the circuit.
	 * - The 80% failure ratio detects systemic issues (Odoo down,
	 *   network failure, auth expired) where most/all jobs fail.
	 * - Individual job failures are handled by Sync_Engine retry logic
	 *   (Error_Type::Transient → exponential backoff).
	 *
	 * @param int $successes Number of successful jobs in the batch.
	 * @param int $failures  Number of failed jobs in the batch.
	 * @return void
	 */
	public function record_batch( int $successes, int $failures ): void {
		$total = $successes + $failures;
		if ( 0 === $total ) {
			return;
		}

		$failure_ratio = $failures / $total;

		if ( $failure_ratio >= self::FAILURE_RATIO ) {
			$this->record_failure( $successes, $failures );
		} else {
			$this->record_success();
		}
	}

	/**
	 * Record a successful batch (failure ratio below threshold).
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
		delete_transient( self::KEY_PROBE );
		delete_option( self::OPT_CB_STATE );
	}

	/**
	 * Record a failed batch (failure ratio at or above threshold).
	 *
	 * Increments the failure counter and opens the circuit when the
	 * consecutive failure threshold is reached. Uses an advisory lock
	 * to prevent lost increments when concurrent workers report failures
	 * after an object cache flush.
	 *
	 * @param int $successes Batch successes (for logging).
	 * @param int $failures  Batch failures (for logging).
	 * @return void
	 */
	public function record_failure( int $successes = 0, int $failures = 0 ): void {
		global $wpdb;

		// Advisory lock prevents concurrent workers from losing increments
		// (read-increment-write race after object cache flush).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$locked = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', 'wp4odoo_cb_failure', 0 )
		);

		// If lock not acquired, proceed without atomicity — a lost increment
		// is acceptable (worst case: circuit opens one batch later).
		try {
			$count = (int) get_transient( self::KEY_FAILURES ) + 1;
			set_transient( self::KEY_FAILURES, $count, HOUR_IN_SECONDS );

			if ( $count >= self::FAILURE_THRESHOLD ) {
				$now = time();
				set_transient( self::KEY_OPENED_AT, $now, HOUR_IN_SECONDS );

				// Persist to DB so state survives object cache flushes.
				update_option(
					self::OPT_CB_STATE,
					[
						'opened_at' => $now,
						'failures'  => $count,
					],
					false
				);

				$this->logger->warning(
					'Circuit breaker opened: Odoo appears unreachable.',
					[
						'consecutive_batch_failures' => $count,
						'last_batch_successes'       => $successes,
						'last_batch_failures'        => $failures,
						'recovery_delay_seconds'     => self::RECOVERY_DELAY,
					]
				);

				if ( null !== $this->failure_notifier ) {
					$this->failure_notifier->notify_circuit_breaker_open( $count );
				}
			}
		} finally {
			if ( '1' === (string) $locked ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->get_var(
					$wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', 'wp4odoo_cb_failure' )
				);
			}
		}
	}
}
