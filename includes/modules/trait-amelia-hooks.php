<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Amelia hook callbacks for push operations.
 *
 * Extracted from Amelia_Module for single responsibility.
 * Handles booking saves, cancellations, reschedules, and service updates
 * via Amelia's WordPress action hooks.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 * - $this->get_mapping(): ?int     (from Module_Base)
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
trait Amelia_Hooks {

	/**
	 * Register Amelia hooks.
	 *
	 * Called by boot().
	 *
	 * @return void
	 */
	protected function register_hooks(): void {
		if ( ! defined( 'AMELIA_VERSION' ) ) {
			$this->logger->warning( __( 'Amelia module enabled but Amelia is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_appointments'] ) ) {
			add_action( 'amelia_after_appointment_booking_saved', $this->safe_callback( [ $this, 'on_booking_saved' ] ), 10, 3 );
			add_action( 'amelia_after_booking_canceled', $this->safe_callback( [ $this, 'on_booking_canceled' ] ), 10, 1 );
			add_action( 'amelia_after_booking_rescheduled', $this->safe_callback( [ $this, 'on_booking_rescheduled' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_services'] ) ) {
			add_action( 'amelia_after_service_added', $this->safe_callback( [ $this, 'on_service_saved' ] ), 10, 1 );
			add_action( 'amelia_after_service_updated', $this->safe_callback( [ $this, 'on_service_saved' ] ), 10, 1 );
		}
	}

	/**
	 * Handle booking saved (after appointment booking is committed).
	 *
	 * Fired by amelia_after_appointment_booking_saved which provides
	 * the booking, service, and appointment arrays from Amelia.
	 *
	 * @param array $booking     Amelia booking data.
	 * @param array $service     Amelia service data.
	 * @param array $appointment Amelia appointment data.
	 * @return void
	 */
	public function on_booking_saved( array $booking, array $service, array $appointment ): void {
		if ( ! $this->should_sync( 'sync_appointments' ) ) {
			return;
		}

		$status = $appointment['status'] ?? '';
		if ( 'approved' !== $status ) {
			return;
		}

		$appointment_id = (int) ( $appointment['id'] ?? 0 );
		if ( $appointment_id <= 0 ) {
			return;
		}

		Queue_Manager::push( 'amelia', 'appointment', 'create', $appointment_id );
	}

	/**
	 * Handle booking cancellation.
	 *
	 * Fired by amelia_after_booking_canceled with the booking data array.
	 *
	 * @param array|null $booking Amelia booking data, or null.
	 * @return void
	 */
	public function on_booking_canceled( ?array $booking ): void {
		if ( ! $this->should_sync( 'sync_appointments' ) || ! $booking ) {
			return;
		}

		$appointment_id = (int) ( $booking['appointmentId'] ?? 0 );
		if ( $appointment_id <= 0 ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'appointment', $appointment_id );
		if ( ! $odoo_id ) {
			return;
		}

		Queue_Manager::push( 'amelia', 'appointment', 'delete', $appointment_id, $odoo_id );
	}

	/**
	 * Handle booking reschedule.
	 *
	 * Fired by amelia_after_booking_rescheduled. The hook has two call
	 * sites in Amelia: one passes 1 param, the other passes 3. We only
	 * need the first (appointment array).
	 *
	 * @param array $appointment Amelia appointment data.
	 * @return void
	 */
	public function on_booking_rescheduled( array $appointment ): void {
		if ( ! $this->should_sync( 'sync_appointments' ) ) {
			return;
		}

		$appointment_id = (int) ( $appointment['id'] ?? 0 );
		if ( $appointment_id <= 0 ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'appointment', $appointment_id );
		if ( ! $odoo_id ) {
			return;
		}

		Queue_Manager::push( 'amelia', 'appointment', 'update', $appointment_id, $odoo_id );
	}

	/**
	 * Handle service added or updated.
	 *
	 * Fired by both amelia_after_service_added and amelia_after_service_updated.
	 *
	 * @param array $service Amelia service data.
	 * @return void
	 */
	public function on_service_saved( array $service ): void {
		$service_id = (int) ( $service['id'] ?? 0 );
		if ( $service_id <= 0 ) {
			return;
		}

		$this->push_entity( 'amelia', 'service', 'sync_services', $service_id );
	}
}
