<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentBooking hook callbacks for push operations.
 *
 * Handles booking scheduled/status changes and calendar create/update
 * via FluentBooking's WordPress action hooks.
 *
 * @package WP4Odoo
 * @since   3.8.0
 */
trait Fluent_Booking_Hooks {

	/**
	 * Register FluentBooking hooks.
	 *
	 * Called by boot().
	 *
	 * @return void
	 */
	protected function register_hooks(): void {
		if ( ! defined( 'FLUENT_BOOKING_VERSION' ) ) {
			$this->logger->warning( __( 'FluentBooking module enabled but FluentBooking is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_bookings'] ) ) {
			\add_action( 'fluent_booking/after_booking_scheduled', $this->safe_callback( [ $this, 'on_booking_scheduled' ] ), 10, 2 );
			\add_action( 'fluent_booking/booking_status_changed', $this->safe_callback( [ $this, 'on_booking_status_changed' ] ), 10, 2 );
		}

		if ( ! empty( $settings['sync_services'] ) ) {
			\add_action( 'fluent_booking/after_calendar_created', $this->safe_callback( [ $this, 'on_calendar_saved' ] ), 10, 1 );
			\add_action( 'fluent_booking/after_calendar_updated', $this->safe_callback( [ $this, 'on_calendar_saved' ] ), 10, 1 );
		}
	}

	/**
	 * Handle new booking scheduled.
	 *
	 * @param array $booking  FluentBooking booking data.
	 * @param array $calendar FluentBooking calendar data.
	 * @return void
	 */
	public function on_booking_scheduled( array $booking, array $calendar ): void {
		$status = $booking['status'] ?? '';
		if ( 'scheduled' !== $status && 'completed' !== $status ) {
			return;
		}

		$booking_id = (int) ( $booking['id'] ?? 0 );
		if ( $booking_id <= 0 ) {
			return;
		}

		$this->push_entity( 'booking', 'sync_bookings', $booking_id );
	}

	/**
	 * Handle booking status change.
	 *
	 * @param array  $booking    FluentBooking booking data.
	 * @param string $new_status New booking status.
	 * @return void
	 */
	public function on_booking_status_changed( array $booking, string $new_status ): void {
		if ( ! $this->should_sync( 'sync_bookings' ) ) {
			return;
		}

		$booking_id = (int) ( $booking['id'] ?? 0 );
		if ( $booking_id <= 0 ) {
			return;
		}

		if ( 'cancelled' === $new_status ) {
			$odoo_id = $this->get_mapping( 'booking', $booking_id );
			if ( ! $odoo_id ) {
				return;
			}
			Queue_Manager::push( 'fluent_booking', 'booking', 'delete', $booking_id, $odoo_id );
			return;
		}

		$odoo_id = $this->get_mapping( 'booking', $booking_id );
		if ( $odoo_id ) {
			Queue_Manager::push( 'fluent_booking', 'booking', 'update', $booking_id, $odoo_id );
		}
	}

	/**
	 * Handle calendar (service) created or updated.
	 *
	 * @param array $calendar FluentBooking calendar data.
	 * @return void
	 */
	public function on_calendar_saved( array $calendar ): void {
		$calendar_id = (int) ( $calendar['id'] ?? 0 );
		if ( $calendar_id <= 0 ) {
			return;
		}

		$this->push_entity( 'service', 'sync_services', $calendar_id );
	}
}
