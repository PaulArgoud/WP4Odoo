<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentBooking Handler — data access for FluentBooking custom tables.
 *
 * FluentBooking stores its data in its own tables: calendar events (the
 * bookable "services": title, description, duration) in
 * {prefix}fcal_calendar_events and bookings in {prefix}fcal_bookings.
 * This handler queries them via $wpdb. The "service" entity is a calendar
 * event, not a calendar (a calendar only represents a host).
 *
 * Called by Fluent_Booking_Module via Booking_Module_Base dispatch.
 *
 * @package WP4Odoo
 * @since   3.8.0
 */
class Fluent_Booking_Handler extends Booking_Handler_Base {

	/**
	 * Load a FluentBooking calendar event (service) by ID.
	 *
	 * @param int $event_id Calendar event ID.
	 * @return array<string, mixed> Service data, or empty if not found.
	 */
	public function load_service( int $event_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fcal_calendar_events';
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT id, title, description, duration, status FROM {$table} WHERE id = %d", $event_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->logger->warning( 'FluentBooking calendar event not found.', [ 'event_id' => $event_id ] );
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

		$table = $wpdb->prefix . 'fcal_bookings';
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
			'event_id'    => (int) ( $row['event_id'] ?? 0 ),
			'email'       => $row['email'] ?? '',
			'first_name'  => $row['first_name'] ?? '',
			'last_name'   => $row['last_name'] ?? '',
			'start_time'  => $row['start_time'] ?? '',
			'end_time'    => $row['end_time'] ?? '',
			'status'      => $row['status'] ?? '',
			'description' => $row['message'] ?? '',
		];
	}

	/**
	 * Get the service (calendar event) ID for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return int Calendar event ID, or 0 if not found.
	 */
	public function get_service_id_for_booking( int $booking_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'fcal_bookings';
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT event_id FROM {$table} WHERE id = %d", $booking_id )
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
	 * Update a calendar event pulled from Odoo.
	 *
	 * Only existing events are updated (title and description). A new event
	 * needs a calendar, a host user, a slug and availability settings that
	 * only FluentBooking can provide, so events are not created from Odoo.
	 *
	 * @param array<string, mixed> $data  Parsed event data.
	 * @param int                  $wp_id Existing calendar event ID (0 to create new).
	 * @return int The event ID, or 0 when nothing was saved.
	 */
	public function save_service( array $data, int $wp_id = 0 ): int {
		global $wpdb;

		if ( $wp_id <= 0 ) {
			$this->logger->warning( 'Creating FluentBooking calendar events from Odoo is not supported; create the event in FluentBooking first.' );
			return 0;
		}

		$table  = $wpdb->prefix . 'fcal_calendar_events';
		$result = $wpdb->update(
			$table,
			[
				'title'       => $data['title'] ?? '',
				'description' => $data['description'] ?? '',
			],
			[ 'id' => $wp_id ]
		);

		return false !== $result ? $wp_id : 0;
	}

	// ─── Pull: delete service ──────────────────────────────

	/**
	 * Keep a calendar event whose Odoo product was deleted.
	 *
	 * Deleting an event row directly would orphan its bookings and
	 * availability, so the event is left untouched.
	 *
	 * @param int $event_id Calendar event ID.
	 * @return bool Always true (nothing to delete).
	 */
	public function delete_service( int $event_id ): bool {
		$this->logger->info( 'Odoo product deleted; the FluentBooking calendar event was kept.', [ 'event_id' => $event_id ] );
		return true;
	}
}
