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
	 * Enqueue a sync job with atomic deduplication.
	 *
	 * If a pending job already exists for the same module/entity_type/direction
	 * and wp_id or odoo_id, update it instead of creating a duplicate.
	 *
	 * Uses a MySQL transaction with SELECT … FOR UPDATE to prevent
	 * concurrent hook fires from inserting duplicate pending jobs.
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
	 * @param bool $in_transaction Whether the caller is already inside a DB transaction.
	 * @return int|false The job ID, or false on failure.
	 */
	public function enqueue( array $args, bool $in_transaction = false ): int|false {
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
		if ( null !== $payload && strlen( $payload ) > 1048576 ) {
			return false;
		}
		$priority     = isset( $args['priority'] ) ? absint( $args['priority'] ) : 5;
		$scheduled_at = isset( $args['scheduled_at'] ) ? sanitize_text_field( $args['scheduled_at'] ) : null;

		if ( empty( $module ) || empty( $entity_type ) ) {
			return false;
		}

		// Atomic deduplication: wrap in transaction so concurrent hook fires
		// cannot both see "no existing record" and insert duplicates.
		// InnoDB's SELECT … FOR UPDATE takes a gap lock when no rows match,
		// blocking other transactions until this one commits.
		//
		// If already in a transaction (e.g. WordPress test framework, or another
		// plugin's transaction), use a SAVEPOINT to avoid implicit commit.
		$wpdb->suppress_errors( true );
		$in_tx = $wpdb->get_var( 'SELECT @@in_transaction' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->suppress_errors( false );
		$use_savepoint = $in_transaction || '1' === (string) $in_tx;
		if ( $use_savepoint ) {
			$wpdb->query( 'SAVEPOINT wp4odoo_dedup' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} else {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}

		// Deduplication: look for an existing pending or processing job with same key fields.
		// Including 'processing' prevents re-enqueuing an entity that is currently being synced.
		$where_parts = [
			$wpdb->prepare( 'module = %s', $module ),
			$wpdb->prepare( 'entity_type = %s', $entity_type ),
			$wpdb->prepare( 'direction = %s', $direction ),
			"status IN ('pending', 'processing')",
		];

		if ( null !== $wp_id && $wp_id > 0 ) {
			$where_parts[] = $wpdb->prepare( 'wp_id = %d', $wp_id );
		}
		if ( null !== $odoo_id && $odoo_id > 0 ) {
			$where_parts[] = $wpdb->prepare( 'odoo_id = %d', $odoo_id );
		}

		$where    = implode( ' AND ', $where_parts );
		$existing = $wpdb->get_var( "SELECT id FROM {$table} WHERE {$where} LIMIT 1 FOR UPDATE" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			$update_data = [
				'action'   => $action,
				'payload'  => $payload,
				'priority' => $priority,
			];
			if ( null !== $scheduled_at ) {
				$update_data['scheduled_at'] = $scheduled_at;
			}
			$wpdb->update(
				$table,
				$update_data,
				[ 'id' => (int) $existing ]
			);
			if ( $use_savepoint ) {
				$wpdb->query( 'RELEASE SAVEPOINT wp4odoo_dedup' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			} else {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}
			return (int) $existing;
		}

		$insert_data = [
			'correlation_id' => wp_generate_uuid4(),
			'module'         => $module,
			'direction'      => $direction,
			'entity_type'    => $entity_type,
			'action'         => $action,
			'payload'        => $payload,
			'priority'       => $priority,
			'status'         => 'pending',
		];

		if ( null !== $wp_id ) {
			$insert_data['wp_id'] = $wp_id;
		}
		if ( null !== $odoo_id ) {
			$insert_data['odoo_id'] = $odoo_id;
		}
		if ( null !== $scheduled_at ) {
			$insert_data['scheduled_at'] = $scheduled_at;
		}

		$wpdb->insert( $table, $insert_data );
		$new_id = $wpdb->insert_id ? (int) $wpdb->insert_id : false;

		if ( false === $new_id ) {
			if ( $use_savepoint ) {
				$wpdb->query( 'ROLLBACK TO SAVEPOINT wp4odoo_dedup' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			} else {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}
			return false;
		}

		if ( $use_savepoint ) {
			$wpdb->query( 'RELEASE SAVEPOINT wp4odoo_dedup' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} else {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
		return $new_id;
	}

	/**
	 * Get queue statistics (cached for 5 minutes via transient).
	 *
	 * @return array{pending: int, processing: int, completed: int, failed: int, total: int, last_completed_at: string}
	 */
	public function get_stats(): array {
		$cached = get_transient( 'wp4odoo_queue_stats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = $this->table();

		// Single query: count per status + last completed timestamp.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) as count, MAX( CASE WHEN status = 'completed' THEN processed_at END ) as last_completed FROM {$table} GROUP BY status" );

		$stats = [
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
			'total'      => 0,
		];

		$last_completed = '';
		foreach ( $rows as $row ) {
			if ( isset( $stats[ $row->status ] ) ) {
				$stats[ $row->status ] = (int) $row->count;
			}
			$stats['total'] += (int) $row->count;
			if ( ! empty( $row->last_completed ) ) {
				$last_completed = $row->last_completed;
			}
		}

		$stats['last_completed_at'] = $last_completed;

		set_transient( 'wp4odoo_queue_stats', $stats, 300 );

		return $stats;
	}

	/**
	 * Get queue health metrics (cached for 5 minutes via transient).
	 *
	 * Returns extended metrics beyond basic counts: average processing
	 * latency, success rate, and per-module depth.
	 *
	 * @return array{avg_latency_seconds: float, success_rate: float, depth_by_module: array<string, int>}
	 */
	public function get_health_metrics(): array {
		$cached = get_transient( 'wp4odoo_queue_health' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = $this->table();

		// Average latency: time between created_at and processed_at for recent completed jobs (last 24h).
		$avg_latency = (float) $wpdb->get_var(
			"SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) FROM {$table} WHERE status = 'completed' AND processed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
		);

		// Success rate: completed / (completed + failed) over last 24h.
		$totals = $wpdb->get_row(
			"SELECT COALESCE(SUM(status = 'completed'), 0) as completed, COALESCE(SUM(status = 'failed'), 0) as failed FROM {$table} WHERE processed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
		);

		$completed_count = (int) ( $totals->completed ?? 0 );
		$failed_count    = (int) ( $totals->failed ?? 0 );
		$denominator     = $completed_count + $failed_count;
		$success_rate    = $denominator > 0 ? round( $completed_count / $denominator * 100, 1 ) : 100.0;

		// Pending depth by module.
		$module_rows     = $wpdb->get_results(
			"SELECT module, COUNT(*) as depth FROM {$table} WHERE status = 'pending' GROUP BY module ORDER BY depth DESC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
		);
		$depth_by_module = [];
		foreach ( $module_rows as $row ) {
			$depth_by_module[ $row->module ] = (int) $row->depth;
		}

		$metrics = [
			'avg_latency_seconds' => round( $avg_latency, 1 ),
			'success_rate'        => $success_rate,
			'depth_by_module'     => $depth_by_module,
		];

		set_transient( 'wp4odoo_queue_health', $metrics, 300 );

		return $metrics;
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
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = %d, error_message = NULL, scheduled_at = NULL WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
				'pending',
				0,
				'failed'
			)
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
	 * Get pending jobs for a module.
	 *
	 * @param string      $module      Module identifier.
	 * @param string|null $entity_type Optional entity type filter.
	 * @param int         $limit       Maximum number of jobs to return (0 = no limit).
	 * @return array Array of job objects.
	 */
	public function get_pending( string $module, ?string $entity_type = null, int $limit = 1000 ): array {
		global $wpdb;

		$table     = $this->table();
		$limit_sql = $limit > 0 ? sprintf( ' LIMIT %d', $limit ) : ''; // $limit is typed int — no injection risk.

		if ( null !== $entity_type ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE module = %s AND entity_type = %s AND status = 'pending' ORDER BY priority ASC, created_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
					$module,
					$entity_type
				) . $limit_sql
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE module = %s AND status = 'pending' ORDER BY priority ASC, created_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
				$module
			) . $limit_sql
		);
	}

	/**
	 * Fetch pending jobs for a specific module, ready for processing.
	 *
	 * @param string $module     Module identifier.
	 * @param int    $batch_size Maximum number of jobs to fetch.
	 * @param string $now        Current datetime string (GMT).
	 * @return array Array of job objects.
	 */
	public function fetch_pending_for_module( string $module, int $batch_size, string $now ): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
		$sql = "SELECT * FROM {$table}
				 WHERE status = 'pending'
				   AND module = %s
				   AND ( scheduled_at IS NULL OR scheduled_at <= %s )
				 ORDER BY priority ASC, created_at ASC
				 LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built above from safe $wpdb->prefix.
		return $wpdb->get_results( $wpdb->prepare( $sql, $module, $now, $batch_size ) );
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
	 * Recover jobs stuck in 'processing' state from a previous crash.
	 *
	 * Increments attempts for each recovered job. Jobs that have
	 * exceeded max_attempts are marked 'failed' instead of 'pending'.
	 *
	 * @param int $timeout_seconds Seconds before a processing job is considered stale (default 600).
	 * @return int Number of recovered jobs.
	 */
	public function recover_stale_processing( int $timeout_seconds = 600 ): int {
		global $wpdb;

		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 60, $timeout_seconds ) );

		// Wrap both UPDATEs in a transaction so jobs cannot end up in
		// an inconsistent state if the process crashes between them.
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		// Increment attempts. Jobs under max_attempts → pending (retry).
		$retried = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', attempts = attempts + 1, error_message = 'Recovered from stale processing state.' WHERE status = 'processing' AND processed_at IS NOT NULL AND processed_at < %s AND attempts + 1 < max_attempts", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
				$cutoff
			)
		);

		// Jobs at or beyond max_attempts → failed (no more retries).
		$failed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', attempts = attempts + 1, error_message = 'Max attempts reached after stale processing recovery.' WHERE status = 'processing' AND processed_at IS NOT NULL AND processed_at < %s AND attempts + 1 >= max_attempts", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
				$cutoff
			)
		);

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return $retried + $failed;
	}

	/**
	 * Atomically claim a pending job for processing.
	 *
	 * Uses UPDATE … WHERE status = 'pending' to prevent race conditions
	 * between process_queue() (global lock) and process_module_queue()
	 * (per-module lock) from both processing the same job.
	 *
	 * @param int $job_id The queue job ID.
	 * @return bool True if this process successfully claimed the job.
	 */
	public function claim_job( int $job_id ): bool {
		global $wpdb;

		$table = $this->table();

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'processing', processed_at = %s WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
				current_time( 'mysql', true ),
				$job_id
			)
		);

		return $affected > 0;
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

	/**
	 * Invalidate cached queue stats.
	 *
	 * Called once after a full batch completes rather than per-job,
	 * avoiding transient thrashing during high-throughput processing.
	 *
	 * @return void
	 */
	public function invalidate_stats_cache(): void {
		delete_transient( 'wp4odoo_queue_stats' );
		delete_transient( 'wp4odoo_queue_health' );
	}
}
