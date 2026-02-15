<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Amelia Handler — data access for Amelia booking tables.
 *
 * Amelia stores all data in its own tables ({prefix}amelia_services,
 * {prefix}amelia_appointments, {prefix}amelia_customer_bookings,
 * {prefix}amelia_users). This handler queries them via $wpdb since
 * Amelia does not use WordPress CPTs.
 *
 * Called by Amelia_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class Amelia_Handler extends Booking_Handler_Base {

	/**
	 * Load an Amelia service by ID.
	 *
	 * @param int $service_id Amelia service ID.
	 * @return array<string, mixed> Service data, or empty if not found.
	 */
	public function load_service( int $service_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'amelia_services';
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT id, name, description, price, duration FROM {$table} WHERE id = %d", $service_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->logger->warning( 'Amelia service not found.', [ 'service_id' => $service_id ] );
			return [];
		}

		return [
			'name'        => $row['name'] ?? '',
			'description' => $row['description'] ?? '',
			'price'       => (float) ( $row['price'] ?? 0 ),
			'duration'    => (int) ( $row['duration'] ?? 0 ),
		];
	}

	/**
	 * Load an Amelia appointment by ID.
	 *
	 * Returns appointment data including the first customer's ID from
	 * the customer_bookings table, ready for partner resolution.
	 *
	 * @param int $appointment_id Amelia appointment ID.
	 * @return array<string, mixed> Appointment data, or empty if not found.
	 */
	public function load_appointment( int $appointment_id ): array {
		global $wpdb;

		$apt_table = $wpdb->prefix . 'amelia_appointments';
		$cb_table  = $wpdb->prefix . 'amelia_customer_bookings';

		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT * FROM {$apt_table} WHERE id = %d", $appointment_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->logger->warning( 'Amelia appointment not found.', [ 'appointment_id' => $appointment_id ] );
			return [];
		}

		// Get first customer booking for this appointment.
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"SELECT customerId FROM {$cb_table} WHERE appointmentId = %d LIMIT 1",
				$appointment_id
			),
			ARRAY_A
		);

		return [
			'appointment_id' => (int) $row['id'],
			'service_id'     => (int) ( $row['serviceId'] ?? 0 ),
			'status'         => $row['status'] ?? '',
			'bookingStart'   => $row['bookingStart'] ?? '',
			'bookingEnd'     => $row['bookingEnd'] ?? '',
			'internalNotes'  => $row['internalNotes'] ?? '',
			'customer_id'    => (int) ( $booking['customerId'] ?? 0 ),
		];
	}

	/**
	 * Get customer data from the Amelia users table.
	 *
	 * @param int $customer_id Amelia customer ID.
	 * @return array{email: string, firstName: string, lastName: string}|array{} Customer data, or empty if not found.
	 */
	public function get_customer_data( int $customer_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'amelia_users';
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT email, firstName, lastName FROM {$table} WHERE id = %d AND type = 'customer'", $customer_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return [];
		}

		return [
			'email'     => $row['email'] ?? '',
			'firstName' => $row['firstName'] ?? '',
			'lastName'  => $row['lastName'] ?? '',
		];
	}

	/**
	 * Extract service_id from an appointment ID.
	 *
	 * Used by ensure_service_synced() to determine which service needs
	 * to be pushed before the appointment.
	 *
	 * @param int $appointment_id Amelia appointment ID.
	 * @return int Service ID, or 0 if not found.
	 */
	public function get_service_id_for_appointment( int $appointment_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'amelia_appointments';
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT serviceId FROM {$table} WHERE id = %d", $appointment_id )
		);
	}

	// ─── Pull: parse from Odoo ─────────────────────────────

	/**
	 * Parse Odoo product data into Amelia service format.
	 *
	 * Reverse of load_service() + map_to_odoo(). Extracts name,
	 * description, and price from Odoo product.product data.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> Amelia service data.
	 */
	public function parse_service_from_odoo( array $odoo_data ): array {
		return [
			'name'        => $odoo_data['name'] ?? '',
			'description' => $odoo_data['description_sale'] ?? '',
			'price'       => (float) ( $odoo_data['list_price'] ?? 0 ),
		];
	}

	// ─── Pull: save service ────────────────────────────────

	/**
	 * Save a service pulled from Odoo to Amelia's custom table.
	 *
	 * Creates a new row when $wp_id is 0, updates an existing one otherwise.
	 *
	 * @param array<string, mixed> $data  Parsed service data.
	 * @param int                  $wp_id Existing Amelia service ID (0 to create new).
	 * @return int The service ID, or 0 on failure.
	 */
	public function save_service( array $data, int $wp_id = 0 ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'amelia_services';
		$row   = [
			'name'        => $data['name'] ?? '',
			'description' => $data['description'] ?? '',
			'price'       => $data['price'] ?? 0,
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
	 * Delete a service from Amelia's custom table.
	 *
	 * @param int $service_id Amelia service ID.
	 * @return bool True on success.
	 */
	public function delete_service( int $service_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'amelia_services';
		return false !== $wpdb->delete( $table, [ 'id' => $service_id ] );
	}
}
