<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events Calendar Handler — data access for The Events Calendar events,
 * Event Tickets RSVP tickets, and RSVP attendees.
 *
 * Loads tribe_events CPT data and post meta, RSVP ticket posts, and
 * RSVP attendee posts. Formats data for Odoo event.event / calendar.event
 * (dual-model) and event.registration.
 *
 * Called by Events_Calendar_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.7.0
 */
class Events_Calendar_Handler {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	// ─── Load event ───────────────────────────────────────

	/**
	 * Load an event from the tribe_events CPT.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Event data, or empty if not found.
	 */
	public function load_event( int $post_id ): array {
		$post = \get_post( $post_id );
		if ( ! $post || 'tribe_events' !== $post->post_type ) {
			$this->logger->warning( 'Event not found or wrong post type.', [ 'post_id' => $post_id ] );
			return [];
		}

		return [
			'name'        => $post->post_title,
			'description' => $post->post_content,
			'start_date'  => (string) \get_post_meta( $post_id, '_EventStartDateUTC', true ),
			'end_date'    => (string) \get_post_meta( $post_id, '_EventEndDateUTC', true ),
			'timezone'    => (string) \get_post_meta( $post_id, '_EventTimezone', true ),
			'all_day'     => 'yes' === \get_post_meta( $post_id, '_EventAllDay', true ),
			'cost'        => (string) \get_post_meta( $post_id, '_EventCost', true ),
		];
	}

	// ─── Format event ─────────────────────────────────────

	/**
	 * Format event data for Odoo.
	 *
	 * Returns data formatted for event.event or calendar.event depending
	 * on the $use_event_model flag.
	 *
	 * @param array<string, mixed> $data            Event data from load_event().
	 * @param bool                 $use_event_model True for event.event, false for calendar.event.
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function format_event( array $data, bool $use_event_model ): array {
		if ( $use_event_model ) {
			return [
				'name'        => $data['name'] ?? '',
				'date_begin'  => $data['start_date'] ?? '',
				'date_end'    => $data['end_date'] ?? '',
				'date_tz'     => ( $data['timezone'] ?? '' ) ?: 'UTC',
				'description' => $data['description'] ?? '',
			];
		}

		return [
			'name'        => $data['name'] ?? '',
			'start'       => $data['start_date'] ?? '',
			'stop'        => $data['end_date'] ?? '',
			'allday'      => $data['all_day'] ?? false,
			'description' => $data['description'] ?? '',
		];
	}

	// ─── Load ticket ──────────────────────────────────────

	/**
	 * Load an RSVP ticket type.
	 *
	 * @param int $ticket_id Ticket post ID.
	 * @return array<string, mixed> Ticket data, or empty if not found.
	 */
	public function load_ticket( int $ticket_id ): array {
		$post = \get_post( $ticket_id );
		if ( ! $post ) {
			$this->logger->warning( 'Ticket not found.', [ 'ticket_id' => $ticket_id ] );
			return [];
		}

		return [
			'name'     => $post->post_title,
			'price'    => (float) \get_post_meta( $ticket_id, '_price', true ),
			'capacity' => (int) \get_post_meta( $ticket_id, '_capacity', true ),
			'event_id' => (int) \get_post_meta( $ticket_id, '_tribe_rsvp_for_event', true ),
		];
	}

	// ─── Load attendee ────────────────────────────────────

	/**
	 * Load an RSVP attendee.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return array<string, mixed> Attendee data, or empty if not found.
	 */
	public function load_attendee( int $attendee_id ): array {
		$post = \get_post( $attendee_id );
		if ( ! $post ) {
			$this->logger->warning( 'Attendee not found.', [ 'attendee_id' => $attendee_id ] );
			return [];
		}

		return [
			'name'      => (string) \get_post_meta( $attendee_id, '_tribe_rsvp_full_name', true ),
			'email'     => (string) \get_post_meta( $attendee_id, '_tribe_rsvp_email', true ),
			'event_id'  => (int) \get_post_meta( $attendee_id, '_tribe_rsvp_event', true ),
			'ticket_id' => (int) \get_post_meta( $attendee_id, '_tribe_rsvp_product', true ),
		];
	}

	// ─── Format attendee ──────────────────────────────────

	/**
	 * Format attendee data for Odoo event.registration.
	 *
	 * @param array<string, mixed> $data          Attendee data from load_attendee().
	 * @param int                  $partner_id    Resolved Odoo partner ID.
	 * @param int                  $event_odoo_id Resolved Odoo event.event ID.
	 * @return array<string, mixed> Data for event.registration create/write.
	 */
	public function format_attendee( array $data, int $partner_id, int $event_odoo_id ): array {
		return [
			'event_id'   => $event_odoo_id,
			'partner_id' => $partner_id,
			'name'       => $data['name'] ?? '',
			'email'      => $data['email'] ?? '',
		];
	}

	// ─── Helpers ──────────────────────────────────────────

	/**
	 * Get the event ID for a ticket.
	 *
	 * @param int $ticket_id Ticket post ID.
	 * @return int Event post ID, or 0 if not found.
	 */
	public function get_event_id_for_ticket( int $ticket_id ): int {
		return (int) \get_post_meta( $ticket_id, '_tribe_rsvp_for_event', true );
	}

	/**
	 * Get the event ID for an attendee.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return int Event post ID, or 0 if not found.
	 */
	public function get_event_id_for_attendee( int $attendee_id ): int {
		return (int) \get_post_meta( $attendee_id, '_tribe_rsvp_event', true );
	}
}
