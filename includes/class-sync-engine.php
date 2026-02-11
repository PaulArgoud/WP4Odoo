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
	 * MySQL advisory lock name.
	 */
	private const LOCK_NAME = 'wp4odoo_sync';

	/**
	 * Lock acquisition timeout in seconds.
	 *
	 * GET_LOCK waits up to this duration for the lock to become available.
	 * 1 second is sufficient: if another process holds the lock, we skip.
	 */
	private const LOCK_TIMEOUT = 1;

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
	 * Constructor.
	 *
	 * @param \Closure              $module_resolver Returns a Module_Base (or null) for a given module ID.
	 * @param Sync_Queue_Repository $queue_repo      Sync queue repository.
	 */
	public function __construct( \Closure $module_resolver, Sync_Queue_Repository $queue_repo ) {
		$this->module_resolver  = $module_resolver;
		$this->queue_repo       = $queue_repo;
		$this->logger           = new Logger( 'sync' );
		$this->failure_notifier = new Failure_Notifier( $this->logger );
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
		if ( ! $this->acquire_lock() ) {
			$this->logger->info( 'Queue processing skipped: another process is running.' );
			return 0;
		}

		static $settings = null;
		if ( null === $settings ) {
			$settings = get_option( 'wp4odoo_sync_settings', [] );
		}
		$batch = (int) ( $settings['batch_size'] ?? 50 );
		$now   = current_time( 'mysql', true );

		$jobs       = $this->queue_repo->fetch_pending( $batch, $now );
		$processed  = 0;
		$start_time = microtime( true );

		$this->batch_failures  = 0;
		$this->batch_successes = 0;

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

			try {
				$success = $this->process_job( $job );

				if ( $success ) {
					$this->queue_repo->update_status(
						(int) $job->id,
						'completed',
						[
							'processed_at' => current_time( 'mysql', true ),
						]
					);
					++$processed;
					++$this->batch_successes;
				}
			} catch ( \Throwable $e ) {
				$this->handle_failure( $job, $e->getMessage() );
				++$this->batch_failures;
			}
		}

		$this->failure_notifier->check( $this->batch_successes, $this->batch_failures );
		$this->release_lock();

		if ( $processed > 0 ) {
			$this->logger->info(
				'Queue processing completed.',
				[
					'processed' => $processed,
					'total'     => count( $jobs ),
				]
			);
		}

		return $processed;
	}

	/**
	 * Process a single queue job.
	 *
	 * @param object $job The queue row object.
	 * @return bool True if processed successfully.
	 */
	private function process_job( object $job ): bool {
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

		$payload = ! empty( $job->payload ) ? json_decode( $job->payload, true ) : [];
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
			return true;
		}

		if ( 'wp_to_odoo' === $job->direction ) {
			return $module->push_to_odoo( $job->entity_type, $job->action, $wp_id, $odoo_id, $payload );
		}

		return $module->pull_from_odoo( $job->entity_type, $job->action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Handle a failed job: increment attempts, apply backoff or mark as failed.
	 *
	 * @param object $job           The job row.
	 * @param string $error_message The error description.
	 * @return void
	 */
	private function handle_failure( object $job, string $error_message ): void {
		$attempts      = (int) $job->attempts + 1;
		$error_trimmed = sanitize_text_field( mb_substr( $error_message, 0, 65535 ) );

		if ( $attempts >= (int) $job->max_attempts ) {
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
				]
			);
		} else {
			$delay     = $attempts * 60;
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
					'job_id'   => $job->id,
					'attempt'  => $attempts,
					'retry_at' => $scheduled,
					'error'    => $error_message,
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
	 * @return bool True if lock acquired.
	 */
	private function acquire_lock(): bool {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::LOCK_NAME, self::LOCK_TIMEOUT )
		);

		return '1' === (string) $result;
	}

	/**
	 * Release the processing lock.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::LOCK_NAME )
		);
	}
}
