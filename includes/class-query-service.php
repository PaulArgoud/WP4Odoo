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

		$table  = $wpdb->prefix . 'wp4odoo_sync_queue';
		$offset = ( $page - 1 ) * $per_page;

		$where = '';
		if ( '' !== $status ) {
			$where = $wpdb->prepare( 'WHERE status = %s', $status );
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		$table  = $wpdb->prefix . 'wp4odoo_logs';
		$offset = ( $page - 1 ) * $per_page;
		$where  = [];
		$params = [];

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

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		$count_query = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$data_query  = "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		$params_with_limit = array_merge( $params, [ $per_page, $offset ] );

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$items = $wpdb->get_results( $wpdb->prepare( $data_query, $params_with_limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$total = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$items = $wpdb->get_results( $wpdb->prepare( $data_query, [ $per_page, $offset ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return [
			'items' => $items ?: [],
			'total' => $total,
			'pages' => (int) ceil( $total / max( 1, $per_page ) ),
			'page'  => $page,
		];
	}
}
