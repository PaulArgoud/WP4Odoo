<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database query service for queue jobs and log entries.
 *
 * Provides paginated data retrieval, decoupling data access
 * from the admin UI layer. Instantiated and injected where needed.
 *
 * Uses separate COUNT(*) + SELECT queries for MySQL 8.0+ / MariaDB
 * 10.5+ compatibility (SQL_CALC_FOUND_ROWS deprecated in MySQL 8.0.17).
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Query_Service {

	/**
	 * Fetch queue jobs with pagination and optional status filter.
	 *
	 * @param int    $page     Current page.
	 * @param int    $per_page Results per page.
	 * @param string $status   Optional status filter.
	 * @return array{items: array, total: int, pages: int}
	 */
	public function get_queue_jobs( int $page = 1, int $per_page = 30, string $status = '' ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'wp4odoo_sync_queue';
		$blog_id = (int) get_current_blog_id();
		$offset  = ( $page - 1 ) * $per_page;

		$where = $wpdb->prepare( 'WHERE blog_id = %d', $blog_id );
		if ( '' !== $status ) {
			$where .= $wpdb->prepare( ' AND status = %s', $status );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

		// Exclude LONGTEXT `payload` column from list queries for performance.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, correlation_id, module, direction, entity_type, wp_id, odoo_id, action, priority, status, attempts, max_attempts, error_message, scheduled_at, processed_at, created_at FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per_page,
				$offset
			)
		);

		return [
			'items' => $items ?: [],
			'total' => $total,
			'pages' => (int) ceil( $total / max( 1, $per_page ) ),
		];
	}

	/**
	 * Fetch log entries with optional filters and pagination.
	 *
	 * @param array $filters {
	 *     @type string $level     Log level filter.
	 *     @type string $module    Module filter.
	 *     @type string $date_from Start date (Y-m-d).
	 *     @type string $date_to   End date (Y-m-d).
	 * }
	 * @param int   $page     Current page.
	 * @param int   $per_page Results per page.
	 * @return array{items: array, total: int, pages: int, page: int}
	 */
	public function get_log_entries( array $filters = [], int $page = 1, int $per_page = 50 ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'wp4odoo_logs';
		$blog_id = (int) get_current_blog_id();
		$offset  = ( $page - 1 ) * $per_page;
		$where   = [ 'blog_id = %d' ];
		$params  = [ $blog_id ];

		if ( ! empty( $filters['level'] ) ) {
			$where[]  = 'level = %s';
			$params[] = $filters['level'];
		}

		if ( ! empty( $filters['module'] ) ) {
			$where[]  = 'module = %s';
			$params[] = $filters['module'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['date_to'] . ' 23:59:59';
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$count_query = "SELECT COUNT(*) FROM {$table} {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_query, $params ) );

		$data_query        = "SELECT id, correlation_id, level, module, message, context, created_at FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params_with_limit = array_merge( $params, [ $per_page, $offset ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$items = $wpdb->get_results( $wpdb->prepare( $data_query, $params_with_limit ) );

		return [
			'items' => $items ?: [],
			'total' => $total,
			'pages' => (int) ceil( $total / max( 1, $per_page ) ),
			'page'  => $page,
		];
	}
}
