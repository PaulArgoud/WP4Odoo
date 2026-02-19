<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentBooking Handler — data access for FluentBooking custom tables.
 *
 * FluentBooking stores all data in its own tables
 * ({prefix}fluentbooking_calendars, {prefix}fluentbooking_bookings).
 * This handler queries them via $wpdb.
 *
 * Called by Fluent_Booking_Module via Booking_Module_Base dispatch.
 *
 * @package WP4Odoo
 * @since   3.8.0
 */
class Fluent_Booking_Handler extends Booking_Handler_Base {

	/**
	 * Load a FluentBooking calendar (service) by ID.
	 *
	 * @param int $calendar_id Calendar ID.
	 * @return array<string, mixed> Service data, or empty if not found.
	 */
	public function load_service( int $calendar_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentbooking_calendars';
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT id, title, description, duration, status FROM {$table} WHERE id = %d", $calendar_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->logger->warning( 'FluentBooking calendar not found.', [ 'calendar_id' => $calendar_id ] );
			return [];
		}

		return [
			'name'        => $row['title'] ?? '',
			'description' => $row['description'] ?? '',
			'price'       => 0.0,
			'duration'    => (int) ( $row['duration'] ?? 0 ),
		];
	}

	/**
	 * Load a FluentBooking booking by ID.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<string, mixed> Booking data, or empty if not found.
	 */
	public function load_booking( int $booking_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentbooking_bookings';
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $booking_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->logger->warning( 'FluentBooking booking not found.', [ 'booking_id' => $booking_id ] );
			return [];
		}

		return [
			'booking_id'  => (int) $row['id'],
			'calendar_id' => (int) ( $row['calendar_id'] ?? 0 ),
			'email'       => $row['email'] ?? '',
			'first_name'  => $row['first_name'] ?? '',
			'last_name'   => $row['last_name'] ?? '',
			'start_time'  => $row['start_time'] ?? '',
			'end_time'    => $row['end_time'] ?? '',
			'status'      => $row['status'] ?? '',
			'description' => $row['description'] ?? '',
		];
	}

	/**
	 * Get the calendar (service) ID for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return int Calendar ID, or 0 if not found.
	 */
	public function get_service_id_for_booking( int $booking_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentbooking_bookings';
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT calendar_id FROM {$table} WHERE id = %d", $booking_id )
		);
	}

	// ─── Pull: parse from Odoo ─────────────────────────────

	/**
	 * Parse Odoo product data into FluentBooking calendar format.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> Calendar data.
	 */
	public function parse_service_from_odoo( array $odoo_data ): array {
		return [
			'title'       => $odoo_data['name'] ?? '',
			'description' => $odoo_data['description_sale'] ?? '',
		];
	}

	// ─── Pull: save service ────────────────────────────────

	/**
	 * Save a calendar pulled from Odoo to FluentBooking's custom table.
	 *
	 * @param array<string, mixed> $data  Parsed calendar data.
	 * @param int                  $wp_id Existing calendar ID (0 to create new).
	 * @return int The calendar ID, or 0 on failure.
	 */
	public function save_service( array $data, int $wp_id = 0 ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentbooking_calendars';
		$row   = [
			'title'       => $data['title'] ?? '',
			'description' => $data['description'] ?? '',
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
	 * Delete a calendar from FluentBooking's custom table.
	 *
	 * @param int $calendar_id Calendar ID.
	 * @return bool True on success.
	 */
	public function delete_service( int $calendar_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentbooking_calendars';
		return false !== $wpdb->delete( $table, [ 'id' => $calendar_id ] );
	}
}
