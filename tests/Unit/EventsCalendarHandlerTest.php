<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Events_Calendar_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Events_Calendar_Handler.
 *
 * Tests event/ticket/attendee loading, formatting, and helper methods.
 */
class EventsCalendarHandlerTest extends TestCase {

	private Events_Calendar_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_wp_posts']         = [];
		$GLOBALS['_wp_post_meta']     = [];
		$GLOBALS['_tribe_events']     = [];
		$GLOBALS['_tribe_tickets']    = [];
		$GLOBALS['_tribe_attendees']  = [];

		$this->handler = new Events_Calendar_Handler( new Logger( 'test' ) );
	}

	// ─── load_event ──────────────────────────────────────

	public function test_load_event_returns_data(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'post_type'    => 'tribe_events',
			'post_title'   => 'Annual Conference',
			'post_content' => 'Join us for the annual event.',
		];
		$GLOBALS['_wp_post_meta'][10] = [
			'_EventStartDateUTC' => '2026-06-15 09:00:00',
			'_EventEndDateUTC'   => '2026-06-15 17:00:00',
			'_EventTimezone'     => 'Europe/Paris',
			'_EventAllDay'       => '',
			'_EventCost'         => '50',
		];

		$data = $this->handler->load_event( 10 );

		$this->assertSame( 'Annual Conference', $data['name'] );
		$this->assertSame( 'Join us for the annual event.', $data['description'] );
		$this->assertSame( '2026-06-15 09:00:00', $data['start_date'] );
		$this->assertSame( '2026-06-15 17:00:00', $data['end_date'] );
		$this->assertSame( 'Europe/Paris', $data['timezone'] );
		$this->assertFalse( $data['all_day'] );
		$this->assertSame( '50', $data['cost'] );
	}

	public function test_load_event_empty_for_nonexistent(): void {
		$data = $this->handler->load_event( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_event_empty_for_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'post_type'    => 'post',
			'post_title'   => 'Not an event',
			'post_content' => '',
		];

		$data = $this->handler->load_event( 10 );
		$this->assertEmpty( $data );
	}

	public function test_load_event_all_day(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'post_type'    => 'tribe_events',
			'post_title'   => 'All Day Event',
			'post_content' => '',
		];
		$GLOBALS['_wp_post_meta'][10] = [
			'_EventStartDateUTC' => '2026-06-15 00:00:00',
			'_EventEndDateUTC'   => '2026-06-15 23:59:59',
			'_EventTimezone'     => 'UTC',
			'_EventAllDay'       => 'yes',
			'_EventCost'         => '',
		];

		$data = $this->handler->load_event( 10 );
		$this->assertTrue( $data['all_day'] );
	}

	// ─── format_event (event.event) ──────────────────────

	public function test_format_event_for_event_model(): void {
		$data = [
			'name'        => 'Conference',
			'start_date'  => '2026-06-15 09:00:00',
			'end_date'    => '2026-06-15 17:00:00',
			'timezone'    => 'Europe/Paris',
			'all_day'     => false,
			'description' => '<p>Details</p>',
		];

		$result = $this->handler->format_event( $data, true );

		$this->assertSame( 'Conference', $result['name'] );
		$this->assertSame( '2026-06-15 09:00:00', $result['date_begin'] );
		$this->assertSame( '2026-06-15 17:00:00', $result['date_end'] );
		$this->assertSame( 'Europe/Paris', $result['date_tz'] );
		$this->assertSame( '<p>Details</p>', $result['description'] );
		$this->assertArrayNotHasKey( 'allday', $result );
	}

	public function test_format_event_model_defaults_timezone_to_utc(): void {
		$data = [
			'name'        => 'Event',
			'start_date'  => '2026-06-15 09:00:00',
			'end_date'    => '2026-06-15 17:00:00',
			'timezone'    => '',
			'all_day'     => false,
			'description' => '',
		];

		$result = $this->handler->format_event( $data, true );
		$this->assertSame( 'UTC', $result['date_tz'] );
	}

	public function test_format_event_model_includes_description(): void {
		$data = [
			'name'        => 'Event',
			'start_date'  => '2026-06-15 09:00:00',
			'end_date'    => '2026-06-15 17:00:00',
			'timezone'    => 'UTC',
			'all_day'     => false,
			'description' => 'Full description here.',
		];

		$result = $this->handler->format_event( $data, true );
		$this->assertSame( 'Full description here.', $result['description'] );
	}

	// ─── format_event (calendar.event) ───────────────────

	public function test_format_event_for_calendar(): void {
		$data = [
			'name'        => 'Conference',
			'start_date'  => '2026-06-15 09:00:00',
			'end_date'    => '2026-06-15 17:00:00',
			'timezone'    => 'Europe/Paris',
			'all_day'     => false,
			'description' => 'Details',
		];

		$result = $this->handler->format_event( $data, false );

		$this->assertSame( 'Conference', $result['name'] );
		$this->assertSame( '2026-06-15 09:00:00', $result['start'] );
		$this->assertSame( '2026-06-15 17:00:00', $result['stop'] );
		$this->assertFalse( $result['allday'] );
		$this->assertSame( 'Details', $result['description'] );
		$this->assertArrayNotHasKey( 'date_begin', $result );
	}

	public function test_format_calendar_all_day(): void {
		$data = [
			'name'        => 'All Day',
			'start_date'  => '2026-06-15 00:00:00',
			'end_date'    => '2026-06-15 23:59:59',
			'timezone'    => 'UTC',
			'all_day'     => true,
			'description' => '',
		];

		$result = $this->handler->format_event( $data, false );
		$this->assertTrue( $result['allday'] );
	}

	public function test_format_calendar_includes_description(): void {
		$data = [
			'name'        => 'Event',
			'start_date'  => '2026-06-15 09:00:00',
			'end_date'    => '2026-06-15 17:00:00',
			'timezone'    => 'UTC',
			'all_day'     => false,
			'description' => 'Calendar description.',
		];

		$result = $this->handler->format_event( $data, false );
		$this->assertSame( 'Calendar description.', $result['description'] );
	}

	// ─── load_ticket ─────────────────────────────────────

	public function test_load_ticket_returns_data(): void {
		$GLOBALS['_wp_posts'][20] = (object) [
			'post_type'  => 'tribe_rsvp_tickets',
			'post_title' => 'General Admission',
		];
		$GLOBALS['_wp_post_meta'][20] = [
			'_price'                  => '25.00',
			'_capacity'               => '100',
			'_tribe_rsvp_for_event'   => '10',
		];

		$data = $this->handler->load_ticket( 20 );

		$this->assertSame( 'General Admission', $data['name'] );
		$this->assertSame( 25.0, $data['price'] );
		$this->assertSame( 100, $data['capacity'] );
		$this->assertSame( 10, $data['event_id'] );
	}

	public function test_load_ticket_empty_for_nonexistent(): void {
		$data = $this->handler->load_ticket( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_ticket_zero_price(): void {
		$GLOBALS['_wp_posts'][20] = (object) [
			'post_type'  => 'tribe_rsvp_tickets',
			'post_title' => 'Free RSVP',
		];
		$GLOBALS['_wp_post_meta'][20] = [
			'_price'                => '0',
			'_capacity'             => '50',
			'_tribe_rsvp_for_event' => '10',
		];

		$data = $this->handler->load_ticket( 20 );
		$this->assertSame( 0.0, $data['price'] );
	}

	// ─── load_attendee ───────────────────────────────────

	public function test_load_attendee_returns_data(): void {
		$GLOBALS['_wp_posts'][30] = (object) [
			'post_type'  => 'tribe_rsvp_attendees',
			'post_title' => 'RSVP Attendee',
		];
		$GLOBALS['_wp_post_meta'][30] = [
			'_tribe_rsvp_full_name' => 'John Doe',
			'_tribe_rsvp_email'     => 'john@example.com',
			'_tribe_rsvp_event'     => '10',
			'_tribe_rsvp_product'   => '20',
		];

		$data = $this->handler->load_attendee( 30 );

		$this->assertSame( 'John Doe', $data['name'] );
		$this->assertSame( 'john@example.com', $data['email'] );
		$this->assertSame( 10, $data['event_id'] );
		$this->assertSame( 20, $data['ticket_id'] );
	}

	public function test_load_attendee_empty_for_nonexistent(): void {
		$data = $this->handler->load_attendee( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_attendee_with_empty_email(): void {
		$GLOBALS['_wp_posts'][30] = (object) [
			'post_type'  => 'tribe_rsvp_attendees',
			'post_title' => 'Attendee',
		];
		$GLOBALS['_wp_post_meta'][30] = [
			'_tribe_rsvp_full_name' => 'Jane',
			'_tribe_rsvp_email'     => '',
			'_tribe_rsvp_event'     => '10',
			'_tribe_rsvp_product'   => '20',
		];

		$data = $this->handler->load_attendee( 30 );
		$this->assertSame( '', $data['email'] );
	}

	// ─── format_attendee ─────────────────────────────────

	public function test_format_attendee_includes_event_id(): void {
		$data = [
			'name'  => 'John Doe',
			'email' => 'john@example.com',
		];

		$result = $this->handler->format_attendee( $data, 200, 100 );

		$this->assertSame( 100, $result['event_id'] );
		$this->assertSame( 200, $result['partner_id'] );
		$this->assertSame( 'John Doe', $result['name'] );
		$this->assertSame( 'john@example.com', $result['email'] );
	}

	public function test_format_attendee_with_different_ids(): void {
		$data = [
			'name'  => 'Jane',
			'email' => 'jane@example.com',
		];

		$result = $this->handler->format_attendee( $data, 500, 300 );

		$this->assertSame( 300, $result['event_id'] );
		$this->assertSame( 500, $result['partner_id'] );
	}

	public function test_format_attendee_empty_name(): void {
		$data = [
			'name'  => '',
			'email' => 'guest@example.com',
		];

		$result = $this->handler->format_attendee( $data, 200, 100 );
		$this->assertSame( '', $result['name'] );
		$this->assertSame( 'guest@example.com', $result['email'] );
	}

	// ─── get_event_id_for_ticket ─────────────────────────

	public function test_get_event_id_for_ticket_returns_id(): void {
		$GLOBALS['_wp_post_meta'][20] = [
			'_tribe_rsvp_for_event' => '10',
		];

		$event_id = $this->handler->get_event_id_for_ticket( 20 );
		$this->assertSame( 10, $event_id );
	}

	public function test_get_event_id_for_ticket_returns_zero_for_missing(): void {
		$event_id = $this->handler->get_event_id_for_ticket( 999 );
		$this->assertSame( 0, $event_id );
	}

	// ─── get_event_id_for_attendee ───────────────────────

	public function test_get_event_id_for_attendee_returns_id(): void {
		$GLOBALS['_wp_post_meta'][30] = [
			'_tribe_rsvp_event' => '10',
		];

		$event_id = $this->handler->get_event_id_for_attendee( 30 );
		$this->assertSame( 10, $event_id );
	}

	public function test_get_event_id_for_attendee_returns_zero_for_missing(): void {
		$event_id = $this->handler->get_event_id_for_attendee( 999 );
		$this->assertSame( 0, $event_id );
	}

	// ─── Edge cases ──────────────────────────────────────

	public function test_load_event_empty_description(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'post_type'    => 'tribe_events',
			'post_title'   => 'Simple Event',
			'post_content' => '',
		];
		$GLOBALS['_wp_post_meta'][10] = [
			'_EventStartDateUTC' => '2026-06-15 09:00:00',
			'_EventEndDateUTC'   => '2026-06-15 17:00:00',
			'_EventTimezone'     => 'UTC',
			'_EventAllDay'       => '',
			'_EventCost'         => '',
		];

		$data = $this->handler->load_event( 10 );
		$this->assertSame( '', $data['description'] );
		$this->assertSame( '', $data['cost'] );
	}

	public function test_format_event_missing_timezone_key_defaults_utc(): void {
		$data = [
			'name'        => 'Event',
			'start_date'  => '2026-06-15 09:00:00',
			'end_date'    => '2026-06-15 17:00:00',
			'description' => '',
		];

		$result = $this->handler->format_event( $data, true );
		$this->assertSame( 'UTC', $result['date_tz'] );
	}
}
