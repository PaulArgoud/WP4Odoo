<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch create processor for sync queue jobs.
 *
 * Groups wp_to_odoo create jobs by module+entity_type, claims them
 * atomically, pushes via push_batch_creates(), and falls back to
 * individual failure handling when needed.
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
class Batch_Create_Processor {

	/**
	 * @var \Closure(string): ?Module_Base
	 */
	private \Closure $module_resolver;

	/**
	 * @var Sync_Queue_Repository
	 */
	private Sync_Queue_Repository $queue_repo;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * @var \Closure(Queue_Job, string, ?Error_Type, ?int): void
	 */
	private \Closure $failure_handler;

	/**
	 * @param \Closure              $module_resolver  Returns a Module_Base (or null) for a given module ID.
	 * @param Sync_Queue_Repository $queue_repo       Sync queue repository.
	 * @param Logger                $logger           Logger instance.
	 * @param \Closure              $failure_handler  Callback to handle job failures: fn(Queue_Job, string, ?Error_Type, ?int): void.
	 */
	public function __construct(
		\Closure $module_resolver,
		Sync_Queue_Repository $queue_repo,
		Logger $logger,
		\Closure $failure_handler
	) {
		$this->module_resolver = $module_resolver;
		$this->queue_repo      = $queue_repo;
		$this->logger          = $logger;
		$this->failure_handler = $failure_handler;
	}

	/**
	 * Process batch creates for groups of 2+ create jobs with the same module+entity.
	 *
	 * @param Queue_Job[]      $jobs             All fetched Queue_Job value objects.
	 * @param array<int, bool> &$batched_job_ids Output: job IDs processed (key = job ID).
	 * @return array{processed: int, successes: int, failures: int}
	 */
	public function process( array $jobs, array &$batched_job_ids ): array {
		$groups = $this->group_eligible_jobs( $jobs, $batched_job_ids );

		$processed = 0;
		$successes = 0;
		$failures  = 0;

		foreach ( $groups as $group_jobs ) {
			if ( count( $group_jobs ) < 2 ) {
				continue;
			}

			$result     = $this->process_group( $group_jobs, $batched_job_ids );
			$processed += $result['processed'];
			$successes += $result['successes'];
			$failures  += $result['failures'];
		}

		if ( $processed > 0 ) {
			$this->logger->info( 'Batch-created records.', [ 'count' => $processed ] );
		}

		return [
			'processed' => $processed,
			'successes' => $successes,
			'failures'  => $failures,
		];
	}

	/**
	 * @param Queue_Job[]      $jobs              All fetched jobs.
	 * @param array<int, bool> &$evicted_job_ids  Output: IDs of jobs evicted by dedup (to prevent reprocessing).
	 * @return array<string, Queue_Job[]> Groups keyed by module:entity_type.
	 */
	private function group_eligible_jobs( array $jobs, array &$evicted_job_ids ): array {
		$groups = [];
		// Track wp_id → index per group to deduplicate within each group.
		$wp_id_index = [];

		foreach ( $jobs as $job ) {
			if ( 'wp_to_odoo' === $job->direction && 'create' === $job->action ) {
				$key   = $job->module . ':' . $job->entity_type;
				$wp_id = (int) $job->wp_id;

				// Deduplicate by wp_id within each group: keep the latest job
				// to prevent creating duplicate records in Odoo. Mark the evicted
				// job so the individual processing loop does not reprocess it.
				if ( $wp_id > 0 && isset( $wp_id_index[ $key ][ $wp_id ] ) ) {
					$old_job                               = $groups[ $key ][ $wp_id_index[ $key ][ $wp_id ] ];
					$evicted_job_ids[ (int) $old_job->id ] = true;
					$groups[ $key ][ $wp_id_index[ $key ][ $wp_id ] ] = $job;
					continue;
				}

				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = [];
				}
				$idx                    = count( $groups[ $key ] );
				$groups[ $key ][ $idx ] = $job;

				if ( $wp_id > 0 ) {
					$wp_id_index[ $key ][ $wp_id ] = $idx;
				}
			}
		}
		return $groups;
	}

	/**
	 * @param Queue_Job[]      $group_jobs      Jobs in this group (same module+entity_type).
	 * @param array<int, bool> &$batched_job_ids Output: job IDs processed.
	 * @return array{processed: int, successes: int, failures: int}
	 */
	private function process_group( array $group_jobs, array &$batched_job_ids ): array {
		$processed = 0;
		$successes = 0;
		$failures  = 0;

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
				( $this->failure_handler )( $job, 'Module not found: ' . $first_job->module, Error_Type::Permanent, null );
				++$failures;
				$batched_job_ids[ (int) $job->id ] = true;
			}
			return compact( 'processed', 'successes', 'failures' );
		}

		$claim_result = $this->claim_and_prepare( $group_jobs, $batched_job_ids );
		$items        = $claim_result['items'];
		$claimed_jobs = $claim_result['claimed_jobs'];
		$failures    += $claim_result['failures'];

		if ( empty( $claimed_jobs ) ) {
			return compact( 'processed', 'successes', 'failures' );
		}

		$results = $module->push_batch_creates( $first_job->entity_type, $items );

		foreach ( $claimed_jobs as $job ) {
			$wp_id  = (int) $job->wp_id;
			$result = $results[ $wp_id ] ?? Sync_Result::failure( 'No result from batch.', Error_Type::Transient );

			if ( $result->succeeded() ) {
				$this->queue_repo->update_status( (int) $job->id, 'completed', [ 'processed_at' => current_time( 'mysql', true ) ] );
				++$processed;
				++$successes;
			} else {
				( $this->failure_handler )( $job, $result->get_message(), $result->get_error_type(), $result->get_entity_id() );
				++$failures;
			}

			$batched_job_ids[ (int) $job->id ] = true;
		}

		return compact( 'processed', 'successes', 'failures' );
	}

	/**
	 * @param Queue_Job[]      $group_jobs      Jobs to claim.
	 * @param array<int, bool> &$batched_job_ids Output: job IDs marked as handled.
	 * @return array{items: array, claimed_jobs: Queue_Job[], failures: int}
	 */
	private function claim_and_prepare( array $group_jobs, array &$batched_job_ids ): array {
		$items        = [];
		$claimed_jobs = [];
		$failures     = 0;

		foreach ( $group_jobs as $job ) {
			if ( ! $this->queue_repo->claim_job( (int) $job->id ) ) {
				$batched_job_ids[ (int) $job->id ] = true;
				continue;
			}

			$payload = [];
			if ( ! empty( $job->payload ) ) {
				$decoded = json_decode( $job->payload, true );
				if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
					( $this->failure_handler )( $job, sprintf( 'Invalid JSON payload in batch job #%d.', (int) $job->id ), Error_Type::Permanent, null );
					++$failures;
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

		return compact( 'items', 'claimed_jobs', 'failures' );
	}
}
