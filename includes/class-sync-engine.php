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
	 * Memory usage threshold (fraction of PHP memory_limit).
	 *
	 * When usage exceeds this ratio, batch processing stops to prevent OOM.
	 */
	private const MEMORY_THRESHOLD = 0.8;

	/**
	 * Maximum batch iterations per cron invocation.
	 *
	 * Safety cap to prevent runaway loops even if time/memory checks
	 * malfunction. 20 iterations × default 50 batch = 1 000 jobs max.
	 */
	private const MAX_BATCH_ITERATIONS = 20;

	/**
	 * Constructor.
	 *
	 * @param \Closure              $module_resolver Returns a Module_Base (or null) for a given module ID.
	 * @param Sync_Queue_Repository $queue_repo      Sync queue repository.
	 * @param Settings_Repository   $settings        Settings repository.
	 * @param Logger|null           $logger          Optional logger (injected for testing, auto-created otherwise).
	 */
	public function __construct( \Closure $module_resolver, Sync_Queue_Repository $queue_repo, Settings_Repository $settings, ?Logger $logger = null ) {
		$this->module_resolver  = $module_resolver;
		$this->queue_repo       = $queue_repo;
		$this->settings         = $settings;
		$this->logger           = $logger ?? new Logger( 'sync', $settings );
		$this->failure_notifier = new Failure_Notifier( $this->logger, $settings );
		$this->circuit_breaker  = new Circuit_Breaker( $this->logger );
		$this->circuit_breaker->set_failure_notifier( $this->failure_notifier );
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
		$iteration = 0;

		try {
			// Early memory check: avoid loading the full batch into memory when
			// the process is already near the limit (e.g. after a large pull).
			// The finally block will release the lock on early return.
			if ( $this->is_memory_exhausted() ) {
				$this->logger->warning(
					'Memory threshold reached before fetching jobs, skipping batch.',
					[ 'memory_usage_mb' => round( memory_get_usage( true ) / 1048576, 1 ) ]
				);
				return 0;
			}

			$sync_settings = $this->settings->get_sync_settings();
			$batch         = (int) $sync_settings['batch_size'];

			// Recover any jobs left in 'processing' from a previous crash
			// BEFORE fetching, so recovered jobs are eligible for this batch.
			// Rate-limited to once per minute to avoid redundant table scans.
			$last_recovery = (int) get_transient( 'wp4odoo_last_stale_recovery' );
			if ( time() - $last_recovery >= 60 ) {
				$this->queue_repo->recover_stale_processing( $this->settings->get_stale_timeout() );
				set_transient( 'wp4odoo_last_stale_recovery', time(), 120 );
			}

			$start_time = microtime( true );

			$this->batch_failures  = 0;
			$this->batch_successes = 0;

			$iteration       = 0;
			$touched_modules = [];

			// Multi-batch loop: keep fetching batches until the queue is
			// drained or a termination condition (time, memory, circuit
			// breaker, iteration cap) is reached.
			while ( $iteration < self::MAX_BATCH_ITERATIONS ) {
				++$iteration;

				if ( ! $this->should_continue_batching( $start_time, $processed, $iteration ) ) {
					break;
				}

				$now  = current_time( 'mysql', true );
				$jobs = null !== $module
					? $this->queue_repo->fetch_pending_for_module( $module, $batch, $now )
					: $this->queue_repo->fetch_pending( $batch, $now );

				if ( empty( $jobs ) ) {
					break; // Queue drained.
				}

				// Track modules for translation flush.
				foreach ( $jobs as $job ) {
					$touched_modules[ $job->module ] = true;
				}

				$result     = $this->process_fetched_batch( $jobs, $start_time );
				$processed += $result['processed'];

				if ( $result['should_stop'] ) {
					break;
				}
			}

			/**
			 * Fires after all jobs in a batch have been processed.
			 *
			 * Allows deferred batch operations (e.g. translation flush)
			 * that need to accumulate data during the batch first.
			 *
			 * @since 3.0.0
			 *
			 * @param int    $processed Number of successfully processed jobs.
			 * @param string $module    Module identifier.
			 */
			do_action( 'wp4odoo_batch_processed', $processed, $module );

			// Flush accumulated pull translations for all modules touched.
			$this->flush_module_translations_by_ids( array_keys( $touched_modules ) );

			$this->failure_notifier->check( $this->batch_successes, $this->batch_failures );

			// Update circuit breaker based on batch outcome (ratio-based).
			if ( $this->batch_successes > 0 || $this->batch_failures > 0 ) {
				$this->circuit_breaker->record_batch( $this->batch_successes, $this->batch_failures );
			}
		} finally {
			$this->release_lock( $lock_name );
			$this->queue_repo->invalidate_stats_cache();
		}

		if ( $processed > 0 ) {
			$this->logger->info(
				'Queue processing completed.',
				[
					'processed'  => $processed,
					'iterations' => $iteration,
					'module'     => $module,
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
	 * Check whether the multi-batch loop should continue.
	 *
	 * Returns false when any termination condition is met: time limit,
	 * memory threshold, or circuit breaker opening mid-run.
	 *
	 * @param float $start_time Wall-clock start time (microtime).
	 * @param int   $processed  Jobs processed so far.
	 * @param int   $iteration  Current iteration number.
	 * @return bool True if the next batch should be fetched.
	 */
	private function should_continue_batching( float $start_time, int $processed, int $iteration ): bool {
		if ( ( microtime( true ) - $start_time ) >= self::BATCH_TIME_LIMIT ) {
			$this->logger->info(
				'Multi-batch: time limit reached between batches.',
				[
					'elapsed'    => round( microtime( true ) - $start_time, 2 ),
					'processed'  => $processed,
					'iterations' => $iteration - 1,
				]
			);
			return false;
		}

		if ( $this->is_memory_exhausted() ) {
			$this->logger->warning(
				'Multi-batch: memory threshold reached between batches.',
				[ 'memory_usage_mb' => round( memory_get_usage( true ) / 1048576, 1 ) ]
			);
			return false;
		}

		if ( ! $this->circuit_breaker->is_available() ) {
			$this->logger->info( 'Multi-batch: circuit breaker opened mid-run.' );
			return false;
		}

		return true;
	}

	/**
	 * Process a single fetched batch of jobs.
	 *
	 * Runs the batch-create optimization first, then processes remaining
	 * jobs one-by-one. Returns the number of successfully processed jobs
	 * and whether the outer loop should stop.
	 *
	 * @param array<int, object> $jobs       Fetched job rows.
	 * @param float              $start_time Wall-clock start time (microtime).
	 * @return array{processed: int, should_stop: bool}
	 */
	private function process_fetched_batch( array $jobs, float $start_time ): array {
		$processed = 0;

		// Batch-create optimization: group eligible creates by module+entity.
		$batched_job_ids = [];
		if ( ! $this->dry_run ) {
			$processed += $this->process_batch_creates( $jobs, $batched_job_ids );
		}

		foreach ( $jobs as $job ) {
			// Skip jobs already processed by batch creates.
			if ( isset( $batched_job_ids[ (int) $job->id ] ) ) {
				continue;
			}

			if ( ( microtime( true ) - $start_time ) >= self::BATCH_TIME_LIMIT ) {
				$this->logger->info(
					'Batch time limit reached, deferring remaining jobs.',
					[
						'elapsed'   => round( microtime( true ) - $start_time, 2 ),
						'processed' => $processed,
					]
				);
				return [
					'processed'   => $processed,
					'should_stop' => true,
				];
			}

			if ( $this->is_memory_exhausted() ) {
				$this->logger->warning(
					'Memory threshold reached, deferring remaining jobs.',
					[
						'memory_usage_mb' => round( memory_get_usage( true ) / 1048576, 1 ),
						'processed'       => $processed,
					]
				);
				return [
					'processed'   => $processed,
					'should_stop' => true,
				];
			}

			// Atomically claim the job. If another process (e.g. a concurrent
			// process_module_queue) already claimed it, skip silently.
			if ( ! $this->queue_repo->claim_job( (int) $job->id ) ) {
				continue;
			}

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
					$this->handle_failure( $job, $result->get_message(), $result->get_error_type(), $result->get_entity_id() );
					++$this->batch_failures;
				}
			} catch ( \Throwable $e ) {
				$this->handle_failure( $job, $e->getMessage() );
				++$this->batch_failures;

				// If memory is exhausted after the error, stop the batch
				// to prevent cascading OOM failures on subsequent jobs.
				if ( $this->is_memory_exhausted() ) {
					$this->logger->warning(
						'Memory threshold reached after job failure, stopping batch.',
						[ 'job_id' => $job->id ]
					);
					return [
						'processed'   => $processed,
						'should_stop' => true,
					];
				}
			} finally {
				$this->logger->set_correlation_id( null );
			}
		}

		return [
			'processed'   => $processed,
			'should_stop' => false,
		];
	}

	/**
	 * Flush pull translations for all modules touched during a batch.
	 *
	 * Accepts an array of module IDs (accumulated across iterations of the
	 * multi-batch loop) and calls flush_pull_translations() on each.
	 *
	 * @param array<int, string> $module_ids Module identifiers.
	 * @return void
	 */
	private function flush_module_translations_by_ids( array $module_ids ): void {
		foreach ( $module_ids as $module_id ) {
			$module = ( $this->module_resolver )( $module_id );
			if ( null !== $module ) {
				$module->flush_pull_translations();
			}
		}
	}

	/**
	 * Process batch creates for groups of ≥2 create jobs with the same module+entity.
	 *
	 * Groups wp_to_odoo create jobs by (module, entity_type), then calls
	 * push_batch_creates() on the module. Individual creates (groups of 1)
	 * and non-create jobs are left for the main per-job loop.
	 *
	 * @param array<int, object>  $jobs             All fetched jobs.
	 * @param array<int, bool>    &$batched_job_ids  Output: job IDs processed (key = job ID).
	 * @return int Number of successfully processed jobs.
	 */
	private function process_batch_creates( array $jobs, array &$batched_job_ids ): int {
		// Group eligible create jobs by module:entity_type.
		$groups = [];
		foreach ( $jobs as $job ) {
			if ( 'wp_to_odoo' === $job->direction && 'create' === $job->action ) {
				$key              = $job->module . ':' . $job->entity_type;
				$groups[ $key ][] = $job;
			}
		}

		$processed = 0;

		foreach ( $groups as $group_jobs ) {
			// Only batch groups of 2+; single creates go through the normal loop.
			if ( count( $group_jobs ) < 2 ) {
				continue;
			}

			$first_job = $group_jobs[0];
			$module    = ( $this->module_resolver )( $first_job->module );

			if ( null === $module ) {
				$this->logger->warning(
					'Batch creates skipped: module not found.',
					[
						'module' => $first_job->module,
						'jobs'   => count( $group_jobs ),
					]
				);
				foreach ( $group_jobs as $job ) {
					$this->handle_failure( $job, 'Module not found: ' . $first_job->module, Error_Type::Permanent );
					++$this->batch_failures;
					$batched_job_ids[ (int) $job->id ] = true;
				}
				continue;
			}

			// Atomically claim all jobs for processing.
			$items        = [];
			$claimed_jobs = [];
			foreach ( $group_jobs as $job ) {
				if ( ! $this->queue_repo->claim_job( (int) $job->id ) ) {
					$batched_job_ids[ (int) $job->id ] = true;
					continue;
				}

				$payload = [];
				if ( ! empty( $job->payload ) ) {
					$decoded = json_decode( $job->payload, true );
					if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
						$this->handle_failure( $job, sprintf( 'Invalid JSON payload in batch job #%d.', (int) $job->id ), Error_Type::Permanent );
						++$this->batch_failures;
						$batched_job_ids[ (int) $job->id ] = true;
						continue;
					}
					$payload = is_array( $decoded ) ? $decoded : [];
				}

				$claimed_jobs[] = $job;

				$items[] = [
					'wp_id'   => (int) $job->wp_id,
					'payload' => $payload,
				];
			}

			if ( empty( $claimed_jobs ) ) {
				continue;
			}

			$results = $module->push_batch_creates( $first_job->entity_type, $items );

			// Map results back to individual jobs.
			foreach ( $claimed_jobs as $job ) {
				$wp_id  = (int) $job->wp_id;
				$result = $results[ $wp_id ] ?? Sync_Result::failure( 'No result from batch.', Error_Type::Transient );

				if ( $result->succeeded() ) {
					$this->queue_repo->update_status( (int) $job->id, 'completed', [ 'processed_at' => current_time( 'mysql', true ) ] );
					++$processed;
					++$this->batch_successes;
				} else {
					$this->handle_failure( $job, $result->get_message(), $result->get_error_type(), $result->get_entity_id() );
					++$this->batch_failures;
				}

				$batched_job_ids[ (int) $job->id ] = true;
			}
		}

		if ( $processed > 0 ) {
			$this->logger->info( 'Batch-created records.', [ 'count' => $processed ] );
		}

		return $processed;
	}

	/**
	 * Handle a failed job: increment attempts, apply backoff or mark as failed.
	 *
	 * Error classification determines retry strategy:
	 * - Transient (default): retry with exponential backoff.
	 * - Permanent: fail immediately, no retry.
	 *
	 * @param object          $job               The job row.
	 * @param string          $error_message     The error description.
	 * @param Error_Type|null $error_type        Error classification (null = Transient for backward compat).
	 * @param int|null        $created_entity_id Entity ID created before the failure (prevents duplicate creation on retry).
	 * @return void
	 */
	private function handle_failure( object $job, string $error_message, ?Error_Type $error_type = null, ?int $created_entity_id = null ): void {
		$attempts      = (int) $job->attempts + 1;
		$error_trimmed = sanitize_text_field( mb_substr( $error_message, 0, 65535 ) );
		$error_type    = $error_type ?? Error_Type::Transient;

		// Permanent errors fail immediately — no point retrying.
		$should_retry = Error_Type::Transient === $error_type && $attempts < (int) $job->max_attempts;

		if ( $should_retry ) {
			$delay     = (int) ( pow( 2, $attempts ) * 60 ) + random_int( 0, 60 );
			$scheduled = gmdate( 'Y-m-d H:i:s', time() + $delay );

			$extra = [
				'attempts'      => $attempts,
				'error_message' => $error_trimmed,
				'scheduled_at'  => $scheduled,
			];

			// Persist the created Odoo ID so retries switch to update instead of duplicate create.
			if ( null !== $created_entity_id && $created_entity_id > 0 && 0 === (int) ( $job->odoo_id ?? 0 ) ) {
				$extra['odoo_id'] = $created_entity_id;
			}

			$this->queue_repo->update_status( (int) $job->id, 'pending', $extra );

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
			$extra = [
				'attempts'      => $attempts,
				'error_message' => $error_trimmed,
				'processed_at'  => current_time( 'mysql', true ),
			];

			// Persist the created Odoo ID even on permanent failure for manual reconciliation.
			if ( null !== $created_entity_id && $created_entity_id > 0 && 0 === (int) ( $job->odoo_id ?? 0 ) ) {
				$extra['odoo_id'] = $created_entity_id;
			}

			$this->queue_repo->update_status( (int) $job->id, 'failed', $extra );

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
	 * Check whether memory usage exceeds the safety threshold.
	 *
	 * Compares current real usage against MEMORY_THRESHOLD of the
	 * PHP memory_limit. Returns false if the limit is unbounded (-1).
	 *
	 * @return bool True if memory is exhausted.
	 */
	private function is_memory_exhausted(): bool {
		$limit = (string) ini_get( 'memory_limit' );
		if ( '' === $limit || '-1' === $limit ) {
			return false;
		}

		$limit_bytes = wp_convert_hr_to_bytes( $limit );
		return memory_get_usage( true ) >= (int) ( $limit_bytes * self::MEMORY_THRESHOLD );
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
