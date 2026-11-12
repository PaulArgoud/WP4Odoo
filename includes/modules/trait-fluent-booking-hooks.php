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
 * FluentBooking passes its Eloquent-style models (Booking, CalendarSlot) to
 * its action hooks, so callbacks accept an object or an array and only read
 * the record ID; the data itself is loaded from the custom tables.
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
			// Fired with ( $booking, $calendar_event, $booking_data ) when the booking reaches that status.
			\add_action( 'fluent_booking/after_booking_scheduled', $this->safe_callback( [ $this, 'on_booking_scheduled' ] ), 10, 1 );
			\add_action( 'fluent_booking/after_booking_completed', $this->safe_callback( [ $this, 'on_booking_scheduled' ] ), 10, 1 );
			\add_action( 'fluent_booking/after_booking_rescheduled', $this->safe_callback( [ $this, 'on_booking_scheduled' ] ), 10, 1 );
			\add_action( 'fluent_booking/booking_schedule_cancelled', $this->safe_callback( [ $this, 'on_booking_cancelled' ] ), 10, 1 );
			\add_action( 'fluent_booking/booking_schedule_rejected', $this->safe_callback( [ $this, 'on_booking_cancelled' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_services'] ) ) {
			\add_action( 'fluent_booking/after_create_calendar_slot', $this->safe_callback( [ $this, 'on_event_saved' ] ), 10, 1 );
			\add_action( 'fluent_booking/after_update_event_details', $this->safe_callback( [ $this, 'on_event_saved' ] ), 10, 1 );
		}
	}

	/**
	 * Handle a booking that was scheduled, completed or rescheduled.
	 *
	 * @param mixed $booking FluentBooking Booking model (or array).
	 * @return void
	 */
	public function on_booking_scheduled( $booking ): void {
		$booking_id = $this->fluent_booking_record_id( $booking );
		if ( $booking_id <= 0 ) {
			return;
		}

		$this->push_entity( 'booking', 'sync_bookings', $booking_id );
	}

	/**
	 * Handle a booking that was cancelled or rejected.
	 *
	 * @param mixed $booking FluentBooking Booking model (or array).
	 * @return void
	 */
	public function on_booking_cancelled( $booking ): void {
		if ( ! $this->should_sync( 'sync_bookings' ) ) {
			return;
		}

		$booking_id = $this->fluent_booking_record_id( $booking );
		if ( $booking_id <= 0 ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'booking', $booking_id );
		if ( ! $odoo_id ) {
			return;
		}

		Queue_Manager::push( 'fluent_booking', 'booking', 'delete', $booking_id, $odoo_id );
	}

	/**
	 * Handle a calendar event (service) created or updated.
	 *
	 * @param mixed $event FluentBooking CalendarSlot model (or array).
	 * @return void
	 */
	public function on_event_saved( $event ): void {
		$event_id = $this->fluent_booking_record_id( $event );
		if ( $event_id <= 0 ) {
			return;
		}

		$this->push_entity( 'service', 'sync_services', $event_id );
	}

	/**
	 * Read the ID of a FluentBooking record passed to a hook.
	 *
	 * @param mixed $record Model object, array or scalar ID.
	 * @return int Record ID, or 0 when unavailable.
	 */
	private function fluent_booking_record_id( $record ): int {
		if ( is_array( $record ) ) {
			return (int) ( $record['id'] ?? 0 );
		}

		if ( is_object( $record ) ) {
			return (int) ( $record->id ?? 0 );
		}

		return is_numeric( $record ) ? (int) $record : 0;
	}
}
