<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

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
		$this->handle_cpt_save( $post_id, 'tribe_events', 'sync_events', 'event' );
	}

	/**
	 * Handle RSVP ticket post save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_ticket_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! class_exists( 'Tribe__Tickets__Main' ) ) {
			return;
		}

		$this->push_entity( 'events_calendar', 'ticket', 'sync_tickets', $post_id );
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
		$this->push_entity( 'events_calendar', 'attendee', 'sync_attendees', $attendee_id );
	}
}
