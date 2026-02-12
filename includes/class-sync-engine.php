<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue processor for synchronization jobs.
 *
 * Reads pending jobs from {prefix}wp4odoo_sync_queue, processes them
 * in batches with MySQL advisory locking and exponential backoff.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Sync_Engine {

	/**
	 * MySQL advisory lock name prefix.
	 *
	 * When processing a specific module, the lock name becomes
	 * `wp4odoo_sync_{module}` to allow parallel processing of
	 * different modules from separate WP-Cron workers.
	 */
	private const LOCK_PREFIX = 'wp4odoo_sync';

	/**
	 * Lock acquisition timeout in seconds.
	 *
	 * GET_LOCK waits up to this duration for the lock to become available.
	 * 5 seconds allows concurrent WP-Cron runs to queue behind each other
	 * rather than immediately skipping on high-traffic sites.
	 */
	private const LOCK_TIMEOUT = 5;

	/**
	 * Maximum wall-clock seconds for a single batch run.
	 *
	 * Prevents WP-Cron timeouts (default 60 s). We stop fetching
	 * new jobs once this limit is reached; in-flight jobs finish.
	 */
	private const BATCH_TIME_LIMIT = 55;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Failure notification delegate.
	 *
	 * @var Failure_Notifier
	 */
	private Failure_Notifier $failure_notifier;

	/**
	 * Circuit breaker for Odoo connectivity.
	 *
	 * @var Circuit_Breaker
	 */
	private Circuit_Breaker $circuit_breaker;

	/**
	 * Closure that resolves a module by ID (injected, replaces singleton access).
	 *
	 * @var \Closure(string): ?Module_Base
	 */
	private \Closure $module_resolver;

	/**
	 * When true, jobs are logged but not executed.
	 *
	 * @var bool
	 */
	private bool $dry_run = false;

	/**
	 * Failure counter for the current batch run.
	 *
	 * @var int
	 */
	private int $batch_failures = 0;

	/**
	 * Success counter for the current batch run.
	 *
	 * @var int
	 */
	private int $batch_successes = 0;

	/**
	 * Sync queue repository (injected).
	 *
	 * @var Sync_Queue_Repository
	 */
	private Sync_Queue_Repository $queue_repo;

	/**
	 * Settings repository (injected).
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings;

	/**
	 * Constructor.
	 *
	 * @param \Closure              $module_resolver Returns a Module_Base (or null) for a given module ID.
	 * @param Sync_Queue_Repository $queue_repo      Sync queue repository.
	 * @param Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $module_resolver, Sync_Queue_Repository $queue_repo, Settings_Repository $settings ) {
		$this->module_resolver  = $module_resolver;
		$this->queue_repo       = $queue_repo;
		$this->settings         = $settings;
		$this->logger           = new Logger( 'sync', $settings );
		$this->failure_notifier = new Failure_Notifier( $this->logger, $settings );
		$this->circuit_breaker  = new Circuit_Breaker( $this->logger );
	}

	/**
	 * Enable or disable dry-run mode.
	 *
	 * In dry-run mode, jobs are loaded and logged but neither
	 * push_to_odoo() nor pull_from_odoo() is called. Jobs are
	 * marked as completed with a [dry-run] note.
	 *
	 * @param bool $enabled True to enable dry-run mode.
	 * @return void
	 */
	public function set_dry_run( bool $enabled ): void {
		$this->dry_run = $enabled;
	}

	/**
	 * Process the sync queue.
	 *
	 * Acquires a MySQL advisory lock, fetches pending jobs ordered by priority
	 * and scheduled_at, processes them in batches, releases the lock.
	 *
	 * @return int Number of jobs processed successfully.
	 */
	public function process_queue(): int {
		return $this->run_with_lock( self::LOCK_PREFIX, null );
	}

	/**
	 * Process the sync queue for a specific module only.
	 *
	 * Uses a module-specific advisory lock (`wp4odoo_sync_{module}`)
	 * so multiple modules can be processed in parallel from separate
	 * WP-Cron workers or CLI calls.
	 *
	 * @param string $module Module identifier.
	 * @return int Number of jobs processed successfully.
	 */
	public function process_module_queue( string $module ): int {
		return $this->run_with_lock( self::LOCK_PREFIX . '_' . $module, $module );
	}

	/**
	 * Core queue processing loop under an advisory lock.
	 *
	 * @param string      $lock_name Advisory lock name.
	 * @param string|null $module    Module filter (null = all modules).
	 * @return int Number of jobs processed successfully.
	 */
	private function run_with_lock( string $lock_name, ?string $module ): int {
		if ( ! $this->circuit_breaker->is_available() ) {
			$this->logger->info( 'Queue processing skipped: circuit breaker open (Odoo unreachable).' );
			return 0;
		}

		if ( ! $this->acquire_lock( $lock_name ) ) {
			$this->logger->info( 'Queue processing skipped: another process is running.' );
			return 0;
		}

		$processed = 0;

		try {
			$sync_settings = $this->settings->get_sync_settings();
			$batch         = (int) $sync_settings['batch_size'];
			$now           = current_time( 'mysql', true );

			$jobs       = null !== $module
				? $this->queue_repo->fetch_pending_for_module( $module, $batch, $now )
				: $this->queue_repo->fetch_pending( $batch, $now );
			$start_time = microtime( true );

			$this->batch_failures  = 0;
			$this->batch_successes = 0;

			// Recover any jobs left in 'processing' from a previous crash.
			$this->queue_repo->recover_stale_processing();

			foreach ( $jobs as $job ) {
				if ( ( microtime( true ) - $start_time ) >= self::BATCH_TIME_LIMIT ) {
					$this->logger->info(
						'Batch time limit reached, deferring remaining jobs.',
						[
							'elapsed'   => round( microtime( true ) - $start_time, 2 ),
							'processed' => $processed,
							'remaining' => count( $jobs ) - $processed,
						]
					);
					break;
				}

				$this->queue_repo->update_status( (int) $job->id, 'processing' );
				$this->logger->set_correlation_id( $job->correlation_id ?? null );

				try {
					$result = $this->process_job( $job );

					if ( $result->succeeded() ) {
						$this->queue_repo->update_status(
							(int) $job->id,
							'completed',
							[
								'processed_at' => current_time( 'mysql', true ),
							]
						);
						++$processed;
						++$this->batch_successes;
					} else {
						$this->handle_failure( $job, $result->get_message(), $result->get_error_type() );
						++$this->batch_failures;
					}
				} catch ( \Throwable $e ) {
					$this->handle_failure( $job, $e->getMessage() );
					++$this->batch_failures;
				} finally {
					$this->logger->set_correlation_id( null );
				}
			}

			$this->failure_notifier->check( $this->batch_successes, $this->batch_failures );

			// Update circuit breaker based on batch outcome.
			if ( $this->batch_successes > 0 ) {
				$this->circuit_breaker->record_success();
			} elseif ( $this->batch_failures > 0 ) {
				$this->circuit_breaker->record_failure();
			}
		} finally {
			$this->release_lock( $lock_name );
		}

		if ( $processed > 0 ) {
			$this->logger->info(
				'Queue processing completed.',
				[
					'processed' => $processed,
					'total'     => count( $jobs ),
					'module'    => $module,
				]
			);
		}

		return $processed;
	}

	/**
	 * Process a single queue job.
	 *
	 * @param object $job The queue row object.
	 * @return Sync_Result
	 */
	private function process_job( object $job ): Sync_Result {
		$module = ( $this->module_resolver )( $job->module );

		if ( null === $module ) {

			throw new \RuntimeException(
				sprintf(
					/* translators: %s: module identifier */
					__( 'Module "%s" not found or not registered.', 'wp4odoo' ),
					$job->module
				)
			);
		}

		$payload = [];
		if ( ! empty( $job->payload ) ) {
			$decoded = json_decode( $job->payload, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: %d: job ID */
						__( 'Invalid JSON payload in job #%d.', 'wp4odoo' ),
						(int) $job->id
					)
				);
			}
			$payload = is_array( $decoded ) ? $decoded : [];
		}

		$wp_id   = (int) ( $job->wp_id ?? 0 );
		$odoo_id = (int) ( $job->odoo_id ?? 0 );

		if ( $this->dry_run ) {
			$this->logger->info(
				'[dry-run] Would process job.',
				[
					'job_id'      => $job->id,
					'module'      => $job->module,
					'direction'   => $job->direction,
					'entity_type' => $job->entity_type,
					'action'      => $job->action,
					'wp_id'       => $wp_id,
					'odoo_id'     => $odoo_id,
				]
			);
			return Sync_Result::success();
		}

		if ( 'wp_to_odoo' === $job->direction ) {
			return $module->push_to_odoo( $job->entity_type, $job->action, $wp_id, $odoo_id, $payload );
		}

		if ( 'odoo_to_wp' === $job->direction ) {
			return $module->pull_from_odoo( $job->entity_type, $job->action, $odoo_id, $wp_id, $payload );
		}

		throw new \RuntimeException(
			sprintf(
				/* translators: 1: direction value, 2: job ID */
				__( 'Invalid sync direction "%1$s" in job #%2$d.', 'wp4odoo' ),
				$job->direction,
				(int) $job->id
			)
		);
	}

	/**
	 * Handle a failed job: increment attempts, apply backoff or mark as failed.
	 *
	 * Error classification determines retry strategy:
	 * - Transient (default): retry with exponential backoff.
	 * - Permanent: fail immediately, no retry.
	 *
	 * @param object          $job           The job row.
	 * @param string          $error_message The error description.
	 * @param Error_Type|null $error_type    Error classification (null = Transient for backward compat).
	 * @return void
	 */
	private function handle_failure( object $job, string $error_message, ?Error_Type $error_type = null ): void {
		$attempts      = (int) $job->attempts + 1;
		$error_trimmed = sanitize_text_field( mb_substr( $error_message, 0, 65535 ) );
		$error_type    = $error_type ?? Error_Type::Transient;

		// Permanent errors fail immediately — no point retrying.
		$should_retry = Error_Type::Transient === $error_type && $attempts < (int) $job->max_attempts;

		if ( $should_retry ) {
			$delay     = (int) ( pow( 2, $attempts ) * 60 );
			$scheduled = gmdate( 'Y-m-d H:i:s', time() + $delay );

			$this->queue_repo->update_status(
				(int) $job->id,
				'pending',
				[
					'attempts'      => $attempts,
					'error_message' => $error_trimmed,
					'scheduled_at'  => $scheduled,
				]
			);

			$this->logger->warning(
				'Sync job failed, will retry.',
				[
					'job_id'     => $job->id,
					'attempt'    => $attempts,
					'retry_at'   => $scheduled,
					'error'      => $error_message,
					'error_type' => $error_type->value,
				]
			);
		} else {
			$this->queue_repo->update_status(
				(int) $job->id,
				'failed',
				[
					'attempts'      => $attempts,
					'error_message' => $error_trimmed,
					'processed_at'  => current_time( 'mysql', true ),
				]
			);

			$this->logger->error(
				'Sync job permanently failed.',
				[
					'job_id'      => $job->id,
					'module'      => $job->module,
					'entity_type' => $job->entity_type,
					'error'       => $error_message,
					'error_type'  => $error_type->value,
				]
			);
		}
	}

	/**
	 * Acquire the processing lock via MySQL advisory lock.
	 *
	 * Uses GET_LOCK() which is atomic and server-level.
	 * Returns true if the lock was acquired, false if another process holds it.
	 *
	 * @param string $lock_name Lock name (global or per-module).
	 * @return bool True if lock acquired.
	 */
	private function acquire_lock( string $lock_name ): bool {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock_name, self::LOCK_TIMEOUT )
		);

		return '1' === (string) $result;
	}

	/**
	 * Release the processing lock.
	 *
	 * Logs a warning if the lock was not held or could not be released
	 * (e.g. database connection dropped after processing).
	 *
	 * @param string $lock_name Lock name (global or per-module).
	 * @return void
	 */
	private function release_lock( string $lock_name ): void {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name )
		);

		if ( '1' !== (string) $result ) {
			$this->logger->warning(
				'Advisory lock release returned unexpected value.',
				[ 'result' => $result ]
			);
		}
	}
}
