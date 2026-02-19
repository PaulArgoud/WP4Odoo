<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-module circuit breaker for sync queue processing.
 *
 * Complements the global Circuit_Breaker (which protects against transport-level
 * failures like Odoo down) by isolating individual module failures. When a single
 * module accumulates too many failures (e.g. Odoo model uninstalled, access rights
 * changed), only that module is paused — other modules continue processing normally.
 *
 * States per module:
 * - Closed (normal): jobs for this module are processed.
 * - Open (tripped): jobs for this module are skipped.
 * - Half-open (probe): one batch is allowed to test recovery.
 *
 * State is stored in a single wp_options row (`wp4odoo_module_cb_states`) as an
 * associative array keyed by module ID.
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
class Module_Circuit_Breaker {

	/**
	 * Number of consecutive high-failure batches before opening the module circuit.
	 *
	 * Higher than the global breaker (3) to be more patient with module-specific
	 * issues that may be transient (e.g. temporary access rights misconfiguration).
	 */
	private const FAILURE_THRESHOLD = 5;

	/**
	 * Failure ratio threshold for considering a module batch as "failed".
	 *
	 * Aligned with Circuit_Breaker::FAILURE_RATIO (80%).
	 */
	private const FAILURE_RATIO = 0.8;

	/**
	 * Seconds to wait before allowing a probe batch for an open module.
	 *
	 * Longer than the global breaker (300s) since module-specific issues
	 * typically require manual intervention (reinstall model, fix access rights).
	 */
	private const RECOVERY_DELAY = 600;

	/**
	 * TTL for the per-module probe mutex transient (seconds).
	 *
	 * Must exceed RECOVERY_DELAY + typical batch processing time (~60 s)
	 * to prevent overlapping probes for the same module.
	 */
	private const PROBE_TTL = 660;

	/**
	 * Transient key prefix for per-module half-open probe mutexes.
	 */
	private const KEY_MODULE_PROBE_PREFIX = 'wp4odoo_mcb_probe_';

	/**
	 * wp_options key for persisted module circuit breaker states.
	 */
	public const OPT_MODULE_CB_STATES = 'wp4odoo_module_cb_states';

	/**
	 * In-memory cache of module states (loaded once per request).
	 *
	 * @var array<string, array{failures: int, opened_at: int}>|null
	 */
	private ?array $states = null;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Optional failure notifier for module circuit breaker open alerts.
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
	 * Set the failure notifier for module circuit breaker open alerts.
	 *
	 * @param Failure_Notifier $notifier Failure notifier instance.
	 * @return void
	 */
	public function set_failure_notifier( Failure_Notifier $notifier ): void {
		$this->failure_notifier = $notifier;
	}

	/**
	 * Check if a module is available for processing.
	 *
	 * Returns true when the module circuit is closed (normal) or when the
	 * recovery delay has elapsed (half-open, allowing a probe batch).
	 *
	 * @param string $module Module identifier.
	 * @return bool True if the module is available.
	 */
	public function is_module_available( string $module ): bool {
		$states = $this->load_states();

		if ( ! isset( $states[ $module ] ) ) {
			return true;
		}

		$state     = $states[ $module ];
		$opened_at = (int) $state['opened_at'];

		if ( 0 === $opened_at ) {
			return true;
		}

		// Discard stale state (older than 2h) to prevent forever-open modules.
		if ( ( time() - $opened_at ) > 2 * HOUR_IN_SECONDS ) {
			$this->reset_module( $module );
			return true;
		}

		if ( ( time() - $opened_at ) >= self::RECOVERY_DELAY ) {
			return $this->try_acquire_module_probe( $module );
		}

		return false;
	}

	/**
	 * Record batch outcomes for a module.
	 *
	 * Called after processing a batch. Uses the same ratio-based logic as the
	 * global Circuit_Breaker: a batch counts as a failure when 80%+ of jobs failed.
	 *
	 * @param string $module    Module identifier.
	 * @param int    $successes Number of successful jobs for this module in the batch.
	 * @param int    $failures  Number of failed jobs for this module in the batch.
	 * @return void
	 */
	public function record_module_batch( string $module, int $successes, int $failures ): void {
		$total = $successes + $failures;
		if ( 0 === $total ) {
			return;
		}

		$failure_ratio = $failures / $total;

		if ( $failure_ratio < self::FAILURE_RATIO ) {
			$this->record_module_success( $module );
		} else {
			$this->record_module_failure( $module );
		}
	}

	/**
	 * Get the recovery delay in seconds.
	 *
	 * Used by Sync_Engine to defer jobs for open modules so they are not
	 * re-fetched on every cron tick (avoids hot polling loop).
	 *
	 * @since 3.8.0
	 *
	 * @return int Seconds until recovery probe is attempted.
	 */
	public function get_recovery_delay(): int {
		return self::RECOVERY_DELAY;
	}

	/**
	 * Get all currently open modules.
	 *
	 * @return array<string, array{failures: int, opened_at: int}> Module states that are currently open.
	 */
	public function get_open_modules(): array {
		$states = $this->load_states();
		$open   = [];

		foreach ( $states as $module => $state ) {
			$opened_at = (int) $state['opened_at'];
			if ( $opened_at > 0 && ( time() - $opened_at ) < 2 * HOUR_IN_SECONDS ) {
				$open[ $module ] = $state;
			}
		}

		return $open;
	}

	/**
	 * Reset a module's circuit breaker state.
	 *
	 * Used for manual recovery via CLI or admin dashboard.
	 *
	 * @param string $module Module identifier.
	 * @return void
	 */
	public function reset_module( string $module ): void {
		$states = $this->load_states();

		if ( ! isset( $states[ $module ] ) ) {
			return;
		}

		unset( $states[ $module ] );
		$this->save_states( $states );
		delete_transient( self::KEY_MODULE_PROBE_PREFIX . $module );

		$this->logger->info(
			'Module circuit breaker reset.',
			[ 'module' => $module ]
		);
	}

	/**
	 * Atomically acquire the per-module probe mutex.
	 *
	 * Uses a MySQL advisory lock to prevent TOCTOU races where
	 * multiple processes see the probe transient as empty and all
	 * start probe batches for the same module simultaneously.
	 * Mirrors the proven pattern from Circuit_Breaker::try_acquire_probe().
	 *
	 * @param string $module Module identifier.
	 * @return bool True if this process acquired the probe slot.
	 */
	private function try_acquire_module_probe( string $module ): bool {
		$key = self::KEY_MODULE_PROBE_PREFIX . $module;

		// Fast path: if probe transient is already set, skip the lock.
		if ( false !== get_transient( $key ) ) {
			return false;
		}

		global $wpdb;

		$lock_name = 'wp4odoo_mcb_probe_' . $module;

		// Non-blocking advisory lock (timeout = 0).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$locked = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock_name, 0 )
		);

		if ( '1' !== (string) $locked ) {
			return false;
		}

		try {
			// Double-check under lock: another process may have set the
			// transient between our fast-path check and lock acquisition.
			if ( false !== get_transient( $key ) ) { // @phpstan-ignore notIdentical.alwaysFalse
				return false;
			}

			set_transient( $key, 1, self::PROBE_TTL );
			return true;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name )
			);
		}
	}

	/**
	 * Record a successful batch for a module.
	 *
	 * Resets the failure counter and closes the module circuit if open.
	 *
	 * @param string $module Module identifier.
	 * @return void
	 */
	private function record_module_success( string $module ): void {
		$states = $this->load_states();

		if ( ! isset( $states[ $module ] ) ) {
			return;
		}

		$was_open = (int) $states[ $module ]['opened_at'] > 0;

		unset( $states[ $module ] );
		$this->save_states( $states );

		if ( $was_open ) {
			delete_transient( self::KEY_MODULE_PROBE_PREFIX . $module );

			$this->logger->info(
				'Module circuit breaker closed: module recovered.',
				[ 'module' => $module ]
			);
		}
	}

	/**
	 * Record a failed batch for a module.
	 *
	 * Increments the failure counter and opens the module circuit when
	 * the consecutive failure threshold is reached.
	 *
	 * @param string $module Module identifier.
	 * @return void
	 */
	private function record_module_failure( string $module ): void {
		global $wpdb;

		// Advisory lock prevents concurrent workers from losing increments
		// (read-increment-write race on the shared wp_options JSON blob).
		// Mirrors the proven pattern from Circuit_Breaker::record_failure().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$locked = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', 'wp4odoo_mcb_failure', 0 )
		);

		// If lock not acquired, proceed without atomicity — a lost increment
		// is acceptable (worst case: module circuit opens one batch later).
		try {
			// Re-read from DB under lock to prevent lost updates.
			$this->states = null;
			$states       = $this->load_states();

			if ( ! isset( $states[ $module ] ) ) {
				$states[ $module ] = [
					'failures'  => 0,
					'opened_at' => 0,
				];
			}

			++$states[ $module ]['failures'];

			if ( $states[ $module ]['failures'] >= self::FAILURE_THRESHOLD && 0 === (int) $states[ $module ]['opened_at'] ) {
				$states[ $module ]['opened_at'] = time();

				$this->logger->warning(
					'Module circuit breaker opened: module has too many failures.',
					[
						'module'               => $module,
						'consecutive_failures' => $states[ $module ]['failures'],
						'recovery_delay'       => self::RECOVERY_DELAY,
					]
				);

				if ( null !== $this->failure_notifier ) {
					$this->failure_notifier->notify_module_circuit_breaker_open( $module, $states[ $module ]['failures'] );
				}
			}

			$this->save_states( $states );
		} finally {
			if ( '1' === (string) $locked ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->get_var(
					$wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', 'wp4odoo_mcb_failure' )
				);
			}
		}
	}

	/**
	 * Load module states from wp_options (cached per request).
	 *
	 * @return array<string, array{failures: int, opened_at: int}>
	 */
	private function load_states(): array {
		if ( null !== $this->states ) {
			return $this->states;
		}

		$raw          = get_option( self::OPT_MODULE_CB_STATES, [] );
		$this->states = is_array( $raw ) ? $raw : [];

		return $this->states;
	}

	/**
	 * Persist module states to wp_options and update in-memory cache.
	 *
	 * @param array<string, array{failures: int, opened_at: int}> $states Module states.
	 * @return void
	 */
	private function save_states( array $states ): void {
		$this->states = $states;

		if ( empty( $states ) ) {
			delete_option( self::OPT_MODULE_CB_STATES );
		} else {
			update_option( self::OPT_MODULE_CB_STATES, $states, false );
		}
	}
}
