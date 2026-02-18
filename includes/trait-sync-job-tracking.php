<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job outcome tracking and failure handling for the sync engine.
 *
 * Manages batch-level counters (successes, failures), per-module
 * outcome tracking for the module circuit breaker, and the retry/
 * permanent-failure logic for individual jobs.
 *
 * Expects the using class to provide:
 * - Sync_Queue_Repository $queue_repo  (property)
 * - Logger               $logger       (property)
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
trait Sync_Job_Tracking {

	/**
	 * Maximum retry delay in seconds.
	 *
	 * Caps the exponential backoff to prevent unreasonable delays
	 * when max_attempts is set to a high value.
	 */
	private const MAX_RETRY_DELAY = 3600;

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
	 * Per-module outcome tracking for the current batch run.
	 *
	 * Keyed by module ID, each entry tracks successes and failures
	 * to feed the module circuit breaker after the batch completes.
	 *
	 * @var array<string, array{successes: int, failures: int}>
	 */
	private array $module_outcomes = [];

	/**
	 * Handle a failed job: increment attempts, apply backoff or mark as failed.
	 *
	 * Error classification determines retry strategy:
	 * - Transient (default): retry with exponential backoff.
	 * - Permanent: fail immediately, no retry.
	 *
	 * @param Queue_Job       $job               The queue job value object.
	 * @param string          $error_message     The error description.
	 * @param Error_Type|null $error_type        Error classification (null = Transient for backward compat).
	 * @param int|null        $created_entity_id Entity ID created before the failure (prevents duplicate creation on retry).
	 * @return void
	 */
	private function handle_failure( Queue_Job $job, string $error_message, ?Error_Type $error_type = null, ?int $created_entity_id = null ): void {
		$attempts      = (int) $job->attempts + 1;
		$error_trimmed = sanitize_text_field( mb_substr( $error_message, 0, 65535 ) );
		$error_type    = $error_type ?? Error_Type::Transient;

		// Permanent errors fail immediately — no point retrying.
		$should_retry = Error_Type::Transient === $error_type && $attempts < (int) $job->max_attempts;

		if ( $should_retry ) {
			$delay     = min( (int) ( pow( 2, $attempts ) * 60 ) + random_int( 0, 60 ), self::MAX_RETRY_DELAY );
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
	 * Record a job outcome for per-module circuit breaker tracking.
	 *
	 * @param string $module  Module identifier.
	 * @param bool   $success True if the job succeeded.
	 * @return void
	 */
	private function record_module_outcome( string $module, bool $success ): void {
		if ( ! isset( $this->module_outcomes[ $module ] ) ) {
			$this->module_outcomes[ $module ] = [
				'successes' => 0,
				'failures'  => 0,
			];
		}

		if ( $success ) {
			++$this->module_outcomes[ $module ]['successes'];
		} else {
			++$this->module_outcomes[ $module ]['failures'];
		}
	}
}
