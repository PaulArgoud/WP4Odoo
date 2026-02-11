<?php
declare( strict_types=1 );

namespace WP4Odoo\Admin;

use WP4Odoo\Logger;
use WP4Odoo\Queue_Manager;
use WP4Odoo\Sync_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handlers for queue monitoring and log management.
 *
 * Used by Admin_Ajax via trait composition.
 *
 * @package WP4Odoo
 * @since   1.9.6
 */
trait Ajax_Monitor_Handlers {

	/**
	 * Retry all failed queue jobs.
	 *
	 * @return void
	 */
	public function retry_failed(): void {
		$this->verify_request();

		$count = \WP4Odoo\Queue_Manager::retry_failed();

		wp_send_json_success(
			[
				'count'   => $count,
				'message' => sprintf(
					/* translators: %d: number of jobs */
					__( '%d job(s) retried.', 'wp4odoo' ),
					$count
				),
			]
		);
	}

	/**
	 * Clean up old completed/failed queue jobs.
	 *
	 * @return void
	 */
	public function cleanup_queue(): void {
		$this->verify_request();

		$days    = $this->get_post_field( 'days', 'int' ) ?: 7;
		$deleted = \WP4Odoo\Queue_Manager::cleanup( $days );

		wp_send_json_success(
			[
				'deleted' => $deleted,
				'message' => sprintf(
					/* translators: %d: number of deleted jobs */
					__( '%d job(s) deleted.', 'wp4odoo' ),
					$deleted
				),
			]
		);
	}

	/**
	 * Cancel a single pending queue job.
	 *
	 * @return void
	 */
	public function cancel_job(): void {
		$this->verify_request();

		$job_id  = $this->get_post_field( 'job_id', 'int' );
		$success = Queue_Manager::cancel( $job_id );

		if ( $success ) {
			wp_send_json_success(
				[
					'message' => __( 'Job cancelled.', 'wp4odoo' ),
				]
			);
		} else {
			wp_send_json_error(
				[
					'message' => __( 'Unable to cancel this job.', 'wp4odoo' ),
				]
			);
		}
	}

	/**
	 * Purge old log entries.
	 *
	 * @return void
	 */
	public function purge_logs(): void {
		$this->verify_request();

		$logger  = new Logger();
		$deleted = $logger->cleanup();

		wp_send_json_success(
			[
				'deleted' => $deleted,
				'message' => sprintf(
					/* translators: %d: number of deleted log entries */
					__( '%d log entry(ies) deleted.', 'wp4odoo' ),
					$deleted
				),
			]
		);
	}

	/**
	 * Fetch log entries (for AJAX filtering/pagination).
	 *
	 * @return void
	 */
	public function fetch_logs(): void {
		$this->verify_request();

		$filters = [
			'level'     => $this->get_post_field( 'level' ),
			'module'    => $this->get_post_field( 'module' ),
			'date_from' => $this->get_post_field( 'date_from' ),
			'date_to'   => $this->get_post_field( 'date_to' ),
		];

		$page     = max( 1, $this->get_post_field( 'page', 'int' ) ) ?: 1;
		$per_page = $this->get_post_field( 'per_page', 'int' );
		$per_page = ( $per_page > 0 ) ? min( 100, $per_page ) : 50;

		$data = $this->query_service->get_log_entries( $filters, $page, $per_page );

		// Serialize items for JSON transport.
		$items = [];
		foreach ( $data['items'] as $row ) {
			$items[] = [
				'id'         => (int) $row->id,
				'level'      => $row->level,
				'module'     => $row->module,
				'message'    => $row->message,
				'context'    => $row->context ?? '',
				'created_at' => $row->created_at,
			];
		}

		wp_send_json_success(
			[
				'items' => $items,
				'total' => $data['total'],
				'page'  => $data['page'],
				'pages' => $data['pages'],
			]
		);
	}

	/**
	 * Fetch queue jobs (for AJAX pagination).
	 *
	 * @return void
	 */
	public function fetch_queue(): void {
		$this->verify_request();

		$page     = max( 1, $this->get_post_field( 'page', 'int' ) ) ?: 1;
		$per_page = $this->get_post_field( 'per_page', 'int' );
		$per_page = ( $per_page > 0 ) ? min( 100, $per_page ) : 30;

		$data = $this->query_service->get_queue_jobs( $page, $per_page );

		$items = [];
		foreach ( $data['items'] as $job ) {
			$items[] = [
				'id'            => (int) $job->id,
				'module'        => $job->module,
				'entity_type'   => $job->entity_type,
				'direction'     => $job->direction,
				'action'        => $job->action,
				'status'        => $job->status,
				'attempts'      => $job->attempts,
				'max_attempts'  => $job->max_attempts,
				'error_message' => $job->error_message ?? '',
				'created_at'    => $job->created_at,
			];
		}

		wp_send_json_success(
			[
				'items' => $items,
				'total' => $data['total'],
				'pages' => $data['pages'],
				'page'  => $page,
			]
		);
	}

	/**
	 * Fetch queue statistics.
	 *
	 * @return void
	 */
	public function queue_stats(): void {
		$this->verify_request();

		wp_send_json_success( \WP4Odoo\Queue_Manager::get_stats() );
	}
}
