<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bookly Handler — data access for Bookly booking tables.
 *
 * Bookly stores all data in its own tables ({prefix}bookly_services,
 * {prefix}bookly_appointments, {prefix}bookly_customer_appointments,
 * {prefix}bookly_customers). This handler queries them via $wpdb since
 * Bookly does not use WordPress CPTs.
 *
 * Provides both batch queries (for polling) and individual queries
 * (for push_to_odoo / load_wp_data).
 *
 * Called by Bookly_Module via its load_wp_data dispatch and poll().
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class Bookly_Handler extends Booking_Handler_Base {

	/**
	 * Safety limit for batch polling queries.
	 */
	private const POLL_LIMIT = 50000;

	// ─── Batch queries (for polling) ────────────────────────

	/**
	 * Get all Bookly services.
	 *
	 * @return array<int, array{id: int, title: string, info: string, price: float, duration: int}>
	 */
	public function get_all_services(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'bookly_services';

		$limit = self::POLL_LIMIT;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table from $wpdb->prefix, $limit is a class constant.
		$rows = $wpdb->get_results( "SELECT id, title, info, price, duration FROM {$table} LIMIT {$limit}", ARRAY_A );

		if ( ! $rows ) {
			return [];
		}

		if ( count( $rows ) >= $limit ) {
			$this->logger->warning( sprintf( 'Bookly services query hit the %d-row safety limit. Some services may be excluded from sync.', $limit ) );
		}

		$services = [];
		foreach ( $rows as $row ) {
			$services[] = [
				'id'       => (int) $row['id'],
				'title'    => $row['title'] ?? '',
				'info'     => $row['info'] ?? '',
				'price'    => (float) ( $row['price'] ?? 0 ),
				'duration' => (int) ( $row['duration'] ?? 0 ),
			];
		}

		return $services;
	}

	/**
	 * Get all active bookings (customer_appointments with approved/done status).
	 *
	 * Joins bookly_customer_appointments with bookly_appointments to get
	 * appointment details. Returns only bookings with syncable statuses.
	 *
	 * @return array<int, array{id: int, appointment_id: int, customer_id: int, status: string, service_id: int, start_date: string, end_date: string, internal_note: string}>
	 */
	public function get_active_bookings(): array {
		global $wpdb;

		$ca_table  = $wpdb->prefix . 'bookly_customer_appointments';
		$apt_table = $wpdb->prefix . 'bookly_appointments';

		$limit = self::POLL_LIMIT;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
		$rows = $wpdb->get_results(
			'SELECT ca.id, ca.appointment_id, ca.customer_id, ca.status, ' .
			'a.service_id, a.start_date, a.end_date, a.internal_note ' .
			"FROM {$ca_table} ca " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INNER JOIN {$apt_table} a ON ca.appointment_id = a.id " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"WHERE ca.status IN ('approved', 'done') LIMIT {$limit}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $limit is a class constant.
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		if ( count( $rows ) >= $limit ) {
			$this->logger->warning( sprintf( 'Bookly bookings query hit the %d-row safety limit. Some bookings may be excluded from sync.', $limit ) );
		}

		$bookings = [];
		foreach ( $rows as $row ) {
			$bookings[] = [
				'id'             => (int) $row['id'],
				'appointment_id' => (int) ( $row['appointment_id'] ?? 0 ),
				'customer_id'    => (int) ( $row['customer_id'] ?? 0 ),
				'status'         => $row['status'] ?? '',
				'service_id'     => (int) ( $row['service_id'] ?? 0 ),
				'start_date'     => $row['start_date'] ?? '',
				'end_date'       => $row['end_date'] ?? '',
				'internal_note'  => $row['internal_note'] ?? '',
			];
		}

		return $bookings;
	}

	// ─── Individual queries ─────────────────────────────────

	/**
	 * Load a Bookly service by ID.
	 *
	 * @param int $service_id Bookly service ID.
	 * @return array<string, mixed> Service data, or empty if not found.
	 */
	public function load_service( int $service_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'bookly_services';
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT id, title, info, price, duration FROM {$table} WHERE id = %d", $service_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->logger->warning( 'Bookly service not found.', [ 'service_id' => $service_id ] );
			return [];
		}

		return [
			'title'    => $row['title'] ?? '',
			'info'     => $row['info'] ?? '',
			'price'    => (float) ( $row['price'] ?? 0 ),
			'duration' => (int) ( $row['duration'] ?? 0 ),
		];
	}

	/**
	 * Load a Bookly booking (customer_appointment + appointment) by ID.
	 *
	 * @param int $ca_id Bookly customer_appointment ID.
	 * @return array<string, mixed> Booking data, or empty if not found.
	 */
	public function load_booking( int $ca_id ): array {
		global $wpdb;

		$ca_table  = $wpdb->prefix . 'bookly_customer_appointments';
		$apt_table = $wpdb->prefix . 'bookly_appointments';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ca.id, ca.appointment_id, ca.customer_id, ca.status, ' .
				'a.service_id, a.start_date, a.end_date, a.internal_note ' .
				"FROM {$ca_table} ca " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"INNER JOIN {$apt_table} a ON ca.appointment_id = a.id " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'WHERE ca.id = %d',
				$ca_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->logger->warning( 'Bookly booking not found.', [ 'ca_id' => $ca_id ] );
			return [];
		}

		return [
			'id'             => (int) $row['id'],
			'appointment_id' => (int) ( $row['appointment_id'] ?? 0 ),
			'customer_id'    => (int) ( $row['customer_id'] ?? 0 ),
			'status'         => $row['status'] ?? '',
			'service_id'     => (int) ( $row['service_id'] ?? 0 ),
			'start_date'     => $row['start_date'] ?? '',
			'end_date'       => $row['end_date'] ?? '',
			'internal_note'  => $row['internal_note'] ?? '',
		];
	}

	/**
	 * Get customer data from the Bookly customers table.
	 *
	 * @param int $customer_id Bookly customer ID.
	 * @return array{email: string, first_name: string, last_name: string, full_name: string}|array{} Customer data, or empty if not found.
	 */
	public function get_customer_data( int $customer_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'bookly_customers';
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT email, first_name, last_name, full_name FROM {$table} WHERE id = %d", $customer_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return [];
		}

		return [
			'email'      => $row['email'] ?? '',
			'first_name' => $row['first_name'] ?? '',
			'last_name'  => $row['last_name'] ?? '',
			'full_name'  => $row['full_name'] ?? '',
		];
	}

	/**
	 * Extract service_id from a customer_appointment ID.
	 *
	 * Used by ensure_service_synced() to determine which service needs
	 * to be pushed before the booking.
	 *
	 * @param int $ca_id Bookly customer_appointment ID.
	 * @return int Service ID, or 0 if not found.
	 */
	public function get_service_id_for_booking( int $ca_id ): int {
		global $wpdb;

		$ca_table  = $wpdb->prefix . 'bookly_customer_appointments';
		$apt_table = $wpdb->prefix . 'bookly_appointments';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT a.service_id FROM {$ca_table} ca " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"INNER JOIN {$apt_table} a ON ca.appointment_id = a.id " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'WHERE ca.id = %d',
				$ca_id
			)
		);
	}

	// ─── Pull: parse from Odoo ─────────────────────────────

	/**
	 * Parse Odoo product data into Bookly service format.
	 *
	 * Reverse of load_service() + map_to_odoo(). Extracts title,
	 * info, and price from Odoo product.product data.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> Bookly service data.
	 */
	public function parse_service_from_odoo( array $odoo_data ): array {
		return [
			'title' => $odoo_data['name'] ?? '',
			'info'  => $odoo_data['description_sale'] ?? '',
			'price' => (float) ( $odoo_data['list_price'] ?? 0 ),
		];
	}

	// ─── Pull: save service ────────────────────────────────

	/**
	 * Save a service pulled from Odoo to Bookly's custom table.
	 *
	 * Creates a new row when $wp_id is 0, updates an existing one otherwise.
	 *
	 * @param array<string, mixed> $data  Parsed service data.
	 * @param int                  $wp_id Existing Bookly service ID (0 to create new).
	 * @return int The service ID, or 0 on failure.
	 */
	public function save_service( array $data, int $wp_id = 0 ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'bookly_services';
		$row   = [
			'title' => $data['title'] ?? '',
			'info'  => $data['info'] ?? '',
			'price' => $data['price'] ?? 0,
		];

		if ( $wp_id > 0 ) {
			$result = $wpdb->update( $table, $row, [ 'id' => $wp_id ] );
			return false !== $result ? $wp_id : 0;
		}

		$result = $wpdb->insert( $table, $row );
		return false !== $result ? (int) $wpdb->insert_id : 0;
	}

	// ─── Pull: delete service ──────────────────────────────

	/**
	 * Delete a service from Bookly's custom table.
	 *
	 * @param int $service_id Bookly service ID.
	 * @return bool True on success.
	 */
	public function delete_service( int $service_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'bookly_services';
		return false !== $wpdb->delete( $table, [ 'id' => $service_id ] );
	}
}
