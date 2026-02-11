<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository for the wp4odoo_sync_queue table.
 *
 * Centralizes all database operations on the sync queue table.
 * Designed as an injectable instance for explicit dependency management.
 *
 * @package WP4Odoo
 * @since   1.2.0
 */
class Sync_Queue_Repository {

	/**
	 * Get the table name.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wp4odoo_sync_queue';
	}

	/**
	 * Enqueue a sync job with deduplication.
	 *
	 * If a pending job already exists for the same module/entity_type/direction
	 * and wp_id or odoo_id, update it instead of creating a duplicate.
	 *
	 * @param array $args {
	 *     @type string   $module      Module identifier.
	 *     @type string   $direction   'wp_to_odoo' or 'odoo_to_wp'.
	 *     @type string   $entity_type Entity type (e.g., 'product', 'order').
	 *     @type int|null $wp_id       WordPress entity ID.
	 *     @type int|null $odoo_id     Odoo entity ID.
	 *     @type string   $action      'create', 'update', or 'delete'.
	 *     @type array    $payload     Data payload.
	 *     @type int      $priority    Priority (1-10, lower = higher priority).
	 * }
	 * @return int|false The job ID, or false on failure.
	 */
	public function enqueue( array $args ): int|false {
		global $wpdb;

		$table = $this->table();

		$module      = sanitize_text_field( $args['module'] ?? '' );
		$direction   = in_array( $args['direction'] ?? '', [ 'wp_to_odoo', 'odoo_to_wp' ], true )
			? $args['direction']
			: 'wp_to_odoo';
		$entity_type = sanitize_text_field( $args['entity_type'] ?? '' );
		$wp_id       = isset( $args['wp_id'] ) ? absint( $args['wp_id'] ) : null;
		$odoo_id     = isset( $args['odoo_id'] ) ? absint( $args['odoo_id'] ) : null;
		$action      = in_array( $args['action'] ?? '', [ 'create', 'update', 'delete' ], true )
			? $args['action']
			: 'update';
		$payload     = isset( $args['payload'] ) ? wp_json_encode( $args['payload'] ) : null;
		$priority    = isset( $args['priority'] ) ? absint( $args['priority'] ) : 5;

		if ( empty( $module ) || empty( $entity_type ) ) {
			return false;
		}

		// Deduplication: look for an existing pending job with same key fields.
		$where_parts = [
			$wpdb->prepare( 'module = %s', $module ),
			$wpdb->prepare( 'entity_type = %s', $entity_type ),
			$wpdb->prepare( 'direction = %s', $direction ),
			"status = 'pending'",
		];

		if ( null !== $wp_id && $wp_id > 0 ) {
			$where_parts[] = $wpdb->prepare( 'wp_id = %d', $wp_id );
		} elseif ( null !== $odoo_id && $odoo_id > 0 ) {
			$where_parts[] = $wpdb->prepare( 'odoo_id = %d', $odoo_id );
		}

		$where    = implode( ' AND ', $where_parts );
		$existing = $wpdb->get_var( "SELECT id FROM {$table} WHERE {$where} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			$wpdb->update(
				$table,
				[
					'action'   => $action,
					'payload'  => $payload,
					'priority' => $priority,
				],
				[ 'id' => (int) $existing ]
			);
			return (int) $existing;
		}

		$insert_data = [
			'module'      => $module,
			'direction'   => $direction,
			'entity_type' => $entity_type,
			'action'      => $action,
			'payload'     => $payload,
			'priority'    => $priority,
			'status'      => 'pending',
		];

		if ( null !== $wp_id ) {
			$insert_data['wp_id'] = $wp_id;
		}
		if ( null !== $odoo_id ) {
			$insert_data['odoo_id'] = $odoo_id;
		}

		$wpdb->insert( $table, $insert_data );

		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array{pending: int, processing: int, completed: int, failed: int, total: int, last_completed_at: string}
	 */
	public function get_stats(): array {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.

		$stats = [
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
			'total'      => 0,
		];

		foreach ( $rows as $row ) {
			if ( isset( $stats[ $row->status ] ) ) {
				$stats[ $row->status ] = (int) $row->count;
			}
			$stats['total'] += (int) $row->count;
		}

		// Last completed sync timestamp.
		$stats['last_completed_at'] = $wpdb->get_var(
			"SELECT MAX(processed_at) FROM {$table} WHERE status = 'completed'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
		) ?? '';

		return $stats;
	}

	/**
	 * Clean up completed and old failed jobs.
	 *
	 * @param int $days_old Delete completed/failed jobs older than this many days.
	 * @return int Number of deleted rows.
	 */
	public function cleanup( int $days_old = 7 ): int {
		global $wpdb;

		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days_old * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ('completed', 'failed') AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
				$cutoff
			)
		);
	}

	/**
	 * Retry all failed jobs by resetting their status to pending.
	 *
	 * @return int Number of jobs reset.
	 */
	public function retry_failed(): int {
		global $wpdb;

		$table = $this->table();

		return (int) $wpdb->query(
			"UPDATE {$table} SET status = 'pending', attempts = 0, error_message = NULL, scheduled_at = NULL WHERE status = 'failed'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
		);
	}

	/**
	 * Cancel a pending job.
	 *
	 * Only deletes jobs with status 'pending'.
	 *
	 * @param int $job_id The queue job ID.
	 * @return bool True if deleted.
	 */
	public function cancel( int $job_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete(
			$this->table(),
			[
				'id'     => $job_id,
				'status' => 'pending',
			],
			[ '%d', '%s' ]
		);

		return $deleted > 0;
	}

	/**
	 * Get all pending jobs for a module.
	 *
	 * @param string      $module      Module identifier.
	 * @param string|null $entity_type Optional entity type filter.
	 * @return array Array of job objects.
	 */
	public function get_pending( string $module, ?string $entity_type = null ): array {
		global $wpdb;

		$table = $this->table();

		if ( null !== $entity_type ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE module = %s AND entity_type = %s AND status = 'pending' ORDER BY priority ASC, created_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
					$module,
					$entity_type
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE module = %s AND status = 'pending' ORDER BY priority ASC, created_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
				$module
			)
		);
	}

	/**
	 * Fetch pending jobs ready for processing.
	 *
	 * @param int    $batch_size Maximum number of jobs to fetch.
	 * @param string $now        Current datetime string (GMT).
	 * @return array Array of job objects.
	 */
	public function fetch_pending( int $batch_size, string $now ): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
		$sql = "SELECT * FROM {$table}
				 WHERE status = 'pending'
				   AND ( scheduled_at IS NULL OR scheduled_at <= %s )
				 ORDER BY priority ASC, created_at ASC
				 LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built above from safe $wpdb->prefix.
		return $wpdb->get_results( $wpdb->prepare( $sql, $now, $batch_size ) );
	}

	/**
	 * Update a job's status and optional extra fields.
	 *
	 * @param int    $job_id The queue job ID.
	 * @param string $status New status value.
	 * @param array  $extra  Additional columns to update (e.g., attempts, error_message, scheduled_at, processed_at).
	 * @return void
	 */
	public function update_status( int $job_id, string $status, array $extra = [] ): void {
		global $wpdb;

		$data           = $extra;
		$data['status'] = $status;

		$wpdb->update( $this->table(), $data, [ 'id' => $job_id ] );
	}
}
