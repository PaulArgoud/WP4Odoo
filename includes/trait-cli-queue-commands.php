<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI queue management subcommands.
 *
 * Provides stats, list, retry, cleanup, and cancel operations
 * for the sync queue. Used by the CLI class via trait composition.
 *
 * @package WP4Odoo
 * @since   3.3.0
 */
trait CLI_Queue_Commands {

	/**
	 * Display queue statistics.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	private function queue_stats( array $assoc_args ): void {
		$stats  = Queue_Manager::get_stats();
		$format = $assoc_args['format'] ?? 'table';

		$allowed_formats = [ 'table', 'csv', 'json', 'yaml', 'count' ];
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			\WP_CLI::error( sprintf( 'Invalid format "%s". Allowed: %s', $format, implode( ', ', $allowed_formats ) ) );
		}

		\WP_CLI\Utils\format_items(
			$format,
			[
				[
					'pending'           => $stats['pending'],
					'processing'        => $stats['processing'],
					'completed'         => $stats['completed'],
					'failed'            => $stats['failed'],
					'last_completed_at' => $stats['last_completed_at'] ?: '—',
				],
			],
			[ 'pending', 'processing', 'completed', 'failed', 'last_completed_at' ]
		);
	}

	/**
	 * List queue jobs.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	private function queue_list( array $assoc_args ): void {
		$page     = max( 1, (int) ( $assoc_args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $assoc_args['per-page'] ?? 30 ) ) );
		$format   = $assoc_args['format'] ?? 'table';

		$allowed_formats = [ 'table', 'csv', 'json', 'yaml', 'count' ];
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			\WP_CLI::error( sprintf( 'Invalid format "%s". Allowed: %s', $format, implode( ', ', $allowed_formats ) ) );
		}

		$data = $this->query_service->get_queue_jobs( $page, $per_page );

		if ( empty( $data['items'] ) ) {
			\WP_CLI::line( 'No jobs found.' );
			return;
		}

		$rows = [];
		foreach ( $data['items'] as $job ) {
			$rows[] = [
				'id'          => $job->id,
				'module'      => $job->module,
				'entity_type' => $job->entity_type,
				'direction'   => $job->direction,
				'action'      => $job->action,
				'status'      => $job->status,
				'attempts'    => $job->attempts . '/' . $job->max_attempts,
				'created_at'  => $job->created_at,
			];
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			[
				'id',
				'module',
				'entity_type',
				'direction',
				'action',
				'status',
				'attempts',
				'created_at',
			]
		);

		\WP_CLI::line( sprintf( 'Page %d/%d (%d total)', $page, $data['pages'], $data['total'] ) );
	}

	/**
	 * Retry all failed jobs.
	 *
	 * @param array<string, string> $assoc_args Associative arguments (supports --yes).
	 * @return void
	 */
	private function queue_retry( array $assoc_args = [] ): void {
		\WP_CLI::confirm(
			__( 'Retry all failed jobs?', 'wp4odoo' ),
			$assoc_args
		);

		$count = Queue_Manager::retry_failed();
		\WP_CLI::success( sprintf( '%d failed job(s) retried.', $count ) );
	}

	/**
	 * Clean up old completed/failed jobs.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	private function queue_cleanup( array $assoc_args ): void {
		$days    = max( 1, (int) ( $assoc_args['days'] ?? 7 ) );
		$deleted = Queue_Manager::cleanup( $days );
		\WP_CLI::success( sprintf( '%d job(s) deleted (older than %d days).', $deleted, $days ) );
	}

	/**
	 * Cancel a pending job by ID.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	private function queue_cancel( int $job_id ): void {
		if ( $job_id <= 0 ) {
			\WP_CLI::error( 'Please provide a valid job ID. Usage: wp wp4odoo queue cancel <id>' );
		}

		if ( Queue_Manager::cancel( $job_id ) ) {
			\WP_CLI::success( sprintf( 'Job %d cancelled.', $job_id ) );
		} else {
			\WP_CLI::error( sprintf( 'Unable to cancel job %d (not found or not pending).', $job_id ) );
		}
	}
}
