<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events Calendar hook callbacks for push operations.
 *
 * Extracted from Events_Calendar_Module for single responsibility.
 * Handles event saves (The Events Calendar), RSVP ticket saves,
 * and RSVP attendee creation (Event Tickets).
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   2.7.0
 */
trait Events_Calendar_Hooks {

	/**
	 * Handle tribe_events post save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_event_save( int $post_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( \wp_is_post_revision( $post_id ) || \wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'tribe_events' !== \get_post_type( $post_id ) ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_events'] ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'event', $post_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'events_calendar', 'event', $action, $post_id, $odoo_id );
	}

	/**
	 * Handle RSVP ticket post save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_ticket_save( int $post_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( \wp_is_post_revision( $post_id ) || \wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! class_exists( 'Tribe__Tickets__Main' ) ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_tickets'] ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'ticket', $post_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'events_calendar', 'ticket', $action, $post_id, $odoo_id );
	}

	/**
	 * Handle RSVP attendee creation.
	 *
	 * @param int $attendee_id       The attendee post ID.
	 * @param int $order_id          The RSVP order ID.
	 * @param int $product_id        The ticket product ID.
	 * @param int $order_attendee_id The order attendee ID.
	 * @return void
	 */
	public function on_rsvp_attendee_created( int $attendee_id, int $order_id, int $product_id, int $order_attendee_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_attendees'] ) ) {
			return;
		}

		Queue_Manager::push( 'events_calendar', 'attendee', 'create', $attendee_id, 0 );
	}
}
