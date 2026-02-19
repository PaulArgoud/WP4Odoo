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

	use Sync_Job_Tracking;

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
	 * Current advisory lock instance for queue processing.
	 *
	 * @var Advisory_Lock|null
	 */
	private ?Advisory_Lock $lock = null;

	/**
	 * Default maximum wall-clock seconds for a single batch run.
	 *
	 * Prevents WP-Cron timeouts (default 60 s). We stop fetching
	 * new jobs once this limit is reached; in-flight jobs finish.
	 * Filterable via `wp4odoo_engine_batch_time_limit`.
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
	 * Batch create processor (lazy-loaded).
	 *
	 * @var Batch_Create_Processor|null
	 */
	private ?Batch_Create_Processor $batch_processor = null;

	/**
	 * Per-module circuit breaker for isolating module-specific failures.
	 *
	 * @var Module_Circuit_Breaker
	 */
	private Module_Circuit_Breaker $module_breaker;

	/**
	 * Resolved batch time limit for the current run (seconds).
	 *
	 * @var int
	 */
	private int $time_limit = self::BATCH_TIME_LIMIT;

	/**
	 * Resolved memory threshold for the current run (0.0–1.0).
	 *
	 * @var float
	 */
	private float $memory_threshold = self::MEMORY_THRESHOLD;

	/**
	 * Default memory usage threshold (fraction of PHP memory_limit).
	 *
	 * When usage exceeds this ratio, batch processing stops to prevent OOM.
	 * Filterable via `wp4odoo_engine_memory_threshold`.
	 */
	private const MEMORY_THRESHOLD = 0.8;

	/**
	 * Default maximum batch iterations per cron invocation.
	 *
	 * Safety cap to prevent runaway loops even if time/memory checks
	 * malfunction. 20 iterations × default 50 batch = 1 000 jobs max.
	 * Filterable via `wp4odoo_engine_max_iterations`.
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
		$this->logger           = $logger ?? Logger::for_channel( 'sync', $settings );
		$this->failure_notifier = new Failure_Notifier( $this->logger, $settings );
		$this->circuit_breaker  = new Circuit_Breaker( $this->logger );
		$this->circuit_breaker->set_failure_notifier( $this->failure_notifier );
		$this->module_breaker = new Module_Circuit_Breaker( $this->logger );
		$this->module_breaker->set_failure_notifier( $this->failure_notifier );
	}

	/**
	 * Get the effective batch time limit (filterable).
	 *
	 * @return int Seconds.
	 */
	private function get_batch_time_limit(): int {
		/**
		 * Filter the maximum wall-clock seconds for a single batch run.
		 *
		 * Lower this on shared hosting with 30 s cron timeouts; raise it
		 * on dedicated servers that can afford longer runs.
		 *
		 * @since 3.8.0
		 *
		 * @param int $limit Default time limit in seconds.
		 */
		return max( 10, (int) apply_filters( 'wp4odoo_engine_batch_time_limit', self::BATCH_TIME_LIMIT ) );
	}

	/**
	 * Get the effective memory threshold (filterable).
	 *
	 * @return float Fraction of memory_limit (0.0–1.0).
	 */
	private function get_memory_threshold(): float {
		/**
		 * Filter the memory usage threshold for batch processing.
		 *
		 * @since 3.8.0
		 *
		 * @param float $threshold Fraction of PHP memory_limit (0.0–1.0).
		 */
		return max( 0.5, min( 0.99, (float) apply_filters( 'wp4odoo_engine_memory_threshold', self::MEMORY_THRESHOLD ) ) );
	}

	/**
	 * Get the effective maximum batch iterations (filterable).
	 *
	 * @return int
	 */
	private function get_max_iterations(): int {
		/**
		 * Filter the maximum number of batch iterations per cron invocation.
		 *
		 * @since 3.8.0
		 *
		 * @param int $max Default maximum iterations.
		 */
		return max( 1, (int) apply_filters( 'wp4odoo_engine_max_iterations', self::MAX_BATCH_ITERATIONS ) );
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
		$blog_id = (int) get_current_blog_id();
		return $this->run_with_lock( self::LOCK_PREFIX . '_' . $blog_id, null );
	}

	/**
	 * Process the sync queue for a specific module only.
	 *
	 * Uses a module-specific advisory lock (`wp4odoo_sync_{blog_id}_{module}`)
	 * so multiple modules can be processed in parallel from separate
	 * WP-Cron workers or CLI calls. Blog-scoped locks prevent cross-site
	 * contention in multisite environments.
	 *
	 * @param string $module Module identifier.
	 * @return int Number of jobs processed successfully.
	 */
	public function process_module_queue( string $module ): int {
		$blog_id = (int) get_current_blog_id();
		return $this->run_with_lock( self::LOCK_PREFIX . '_' . $blog_id . '_' . $module, $module );
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

			$sync_settings          = $this->settings->get_sync_settings();
			$batch                  = (int) $sync_settings['batch_size'];
			$this->time_limit       = $this->get_batch_time_limit();
			$this->memory_threshold = $this->get_memory_threshold();

			// Recover any jobs left in 'processing' from a previous crash
			// BEFORE fetching, so recovered jobs are eligible for this batch.
			// Rate-limited to once per minute via atomic wp_cache_add() to
			// prevent concurrent workers from running recovery in parallel.
			$recovery_key = 'wp4odoo_stale_recovery_' . get_current_blog_id();
			if ( wp_cache_add( $recovery_key, time(), '', 60 ) ) {
				$this->queue_repo->recover_stale_processing( $this->settings->get_stale_timeout() );
			}

			$start_time = microtime( true );

			$this->batch_failures  = 0;
			$this->batch_successes = 0;
			$this->module_outcomes = [];

			$iteration       = 0;
			$touched_modules = [];

			// Multi-batch loop: keep fetching batches until the queue is
			// drained or a termination condition (time, memory, circuit
			// breaker, iteration cap) is reached.
			while ( $iteration < $this->get_max_iterations() ) {
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

				// If the batch returned fewer jobs than requested, the queue
				// is drained — skip the redundant query on the next iteration.
				if ( count( $jobs ) < $batch ) {
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

			// Update per-module circuit breakers.
			foreach ( $this->module_outcomes as $mod_id => $outcome ) {
				$this->module_breaker->record_module_batch( $mod_id, $outcome['successes'], $outcome['failures'] );
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
	 * @param Queue_Job $job The queue job value object.
	 * @return Sync_Result
	 */
	private function process_job( Queue_Job $job ): Sync_Result {
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
		if ( ( microtime( true ) - $start_time ) >= $this->time_limit ) {
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
	 * @param Queue_Job[] $jobs       Fetched Queue_Job value objects.
	 * @param float       $start_time Wall-clock start time (microtime).
	 * @return array{processed: int, should_stop: bool}
	 */
	private function process_fetched_batch( array $jobs, float $start_time ): array {
		$processed = 0;

		// Batch-create optimization: group eligible creates by module+entity.
		$batched_job_ids = [];
		if ( ! $this->dry_run ) {
			$batch_result           = $this->get_batch_processor()->process( $jobs, $batched_job_ids );
			$processed             += $batch_result['processed'];
			$this->batch_successes += $batch_result['successes'];
			$this->batch_failures  += $batch_result['failures'];
		}

		foreach ( $jobs as $job ) {
			// Skip jobs already processed by batch creates.
			if ( isset( $batched_job_ids[ (int) $job->id ] ) ) {
				continue;
			}

			// Skip jobs whose module circuit breaker is open.
			if ( ! $this->module_breaker->is_module_available( $job->module ) ) {
				continue;
			}

			if ( ( microtime( true ) - $start_time ) >= $this->time_limit ) {
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
				$job_start = microtime( true );
				$result    = $this->process_job( $job );
				$elapsed   = (int) round( ( microtime( true ) - $job_start ) * 1000 );

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
					$this->record_module_outcome( $job->module, true );
				} else {
					$this->handle_failure( $job, $result->get_message(), $result->get_error_type(), $result->get_entity_id() );
					++$this->batch_failures;
					$this->record_module_outcome( $job->module, false );
				}

				/**
				 * Fires after a single sync job is processed.
				 *
				 * Useful for external monitoring (New Relic, Query Monitor, custom dashboards).
				 *
				 * @since 3.3.0
				 *
				 * @param string      $module     Module identifier (e.g. 'woocommerce').
				 * @param int         $elapsed_ms Processing time in milliseconds.
				 * @param Sync_Result $result     Job result (success/failure).
				 * @param Queue_Job   $job        Queue job value object.
				 */
				do_action( 'wp4odoo_job_processed', $job->module, $elapsed, $result, $job );
			} catch ( \Throwable $e ) {
				$this->handle_failure( $job, $e->getMessage() );
				++$this->batch_failures;
				$this->record_module_outcome( $job->module, false );

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
	 * Get or create the batch create processor.
	 *
	 * @return Batch_Create_Processor
	 */
	private function get_batch_processor(): Batch_Create_Processor {
		if ( null === $this->batch_processor ) {
			$this->batch_processor = new Batch_Create_Processor(
				$this->module_resolver,
				$this->queue_repo,
				$this->logger,
				fn( Queue_Job $job, string $message, ?Error_Type $error_type = null, ?int $entity_id = null ) => $this->handle_failure( $job, $message, $error_type, $entity_id )
			);
		}
		return $this->batch_processor;
	}

	/**
	 * Acquire the processing lock via MySQL advisory lock.
	 *
	 * Uses Advisory_Lock which wraps GET_LOCK() — atomic and server-level.
	 * Returns true if the lock was acquired, false if another process holds it.
	 *
	 * @param string $lock_name Lock name (global or per-module).
	 * @return bool True if lock acquired.
	 */
	private function acquire_lock( string $lock_name ): bool {
		$this->lock = new Advisory_Lock( $lock_name, self::LOCK_TIMEOUT );
		return $this->lock->acquire();
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
		return memory_get_usage( true ) >= (int) ( $limit_bytes * $this->memory_threshold );
	}

	/**
	 * Release the processing lock.
	 *
	 * Delegates to Advisory_Lock::release(). Logs a warning if the lock
	 * was not held (e.g. database connection dropped after processing).
	 *
	 * @param string $lock_name Lock name (global or per-module, kept for call-site clarity).
	 * @return void
	 */
	private function release_lock( string $lock_name ): void {
		if ( null === $this->lock ) {
			$this->logger->warning(
				'Advisory lock release called but no lock instance exists.',
				[ 'lock_name' => $lock_name ]
			);
			return;
		}

		if ( ! $this->lock->is_held() ) {
			$this->logger->warning(
				'Advisory lock release called but lock was not held.',
				[ 'lock_name' => $lock_name ]
			);
		}

		$this->lock->release();
		$this->lock = null;
	}
}
