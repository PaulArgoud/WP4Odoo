<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MEC hook callbacks for push operations.
 *
 * Extracted from MEC_Module for single responsibility.
 * Handles event saves (mec-events CPT) and booking completion
 * (MEC Pro).
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   3.8.0
 */
trait MEC_Hooks {

	/**
	 * Register MEC hooks based on module settings.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_events'] ) ) {
			\add_action( 'save_post_mec-events', $this->safe_callback( [ $this, 'on_event_save' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_bookings'] ) ) {
			\add_action( 'mec_booking_completed', $this->safe_callback( [ $this, 'on_booking_completed' ] ), 10, 1 );
		}
	}

	/**
	 * Handle mec-events post save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_event_save( int $post_id ): void {
		$this->handle_cpt_save( $post_id, 'mec-events', 'sync_events', 'event' );
	}

	/**
	 * Handle MEC Pro booking completion.
	 *
	 * @param int $booking_id The booking post ID.
	 * @return void
	 */
	public function on_booking_completed( int $booking_id ): void {
		$this->push_entity( 'booking', 'sync_bookings', $booking_id );
	}
}
