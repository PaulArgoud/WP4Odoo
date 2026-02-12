<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Events_Calendar_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Events_Calendar_Handler.
 *
 * Tests event/ticket/attendee loading, formatting, parsing (pull), saving, and helper methods.
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

	// ─── parse_event_from_odoo (event.event) ────────────

	public function test_parse_event_from_odoo_event_model(): void {
		$odoo = [
			'name'        => 'Odoo Conference',
			'date_begin'  => '2026-07-01 10:00:00',
			'date_end'    => '2026-07-01 18:00:00',
			'date_tz'     => 'America/New_York',
			'description' => '<p>From Odoo</p>',
		];

		$result = $this->handler->parse_event_from_odoo( $odoo, true );

		$this->assertSame( 'Odoo Conference', $result['name'] );
		$this->assertSame( '2026-07-01 10:00:00', $result['start_date'] );
		$this->assertSame( '2026-07-01 18:00:00', $result['end_date'] );
		$this->assertSame( 'America/New_York', $result['timezone'] );
		$this->assertFalse( $result['all_day'] );
		$this->assertSame( '<p>From Odoo</p>', $result['description'] );
	}

	public function test_parse_event_from_odoo_event_model_defaults_timezone(): void {
		$odoo = [
			'name'        => 'Event',
			'date_begin'  => '2026-07-01 10:00:00',
			'date_end'    => '2026-07-01 18:00:00',
		];

		$result = $this->handler->parse_event_from_odoo( $odoo, true );
		$this->assertSame( 'UTC', $result['timezone'] );
	}

	// ─── parse_event_from_odoo (calendar.event) ─────────

	public function test_parse_event_from_odoo_calendar_model(): void {
		$odoo = [
			'name'        => 'Calendar Event',
			'start'       => '2026-07-01 10:00:00',
			'stop'        => '2026-07-01 18:00:00',
			'allday'      => true,
			'description' => 'Calendar desc',
		];

		$result = $this->handler->parse_event_from_odoo( $odoo, false );

		$this->assertSame( 'Calendar Event', $result['name'] );
		$this->assertSame( '2026-07-01 10:00:00', $result['start_date'] );
		$this->assertSame( '2026-07-01 18:00:00', $result['end_date'] );
		$this->assertSame( '', $result['timezone'] );
		$this->assertTrue( $result['all_day'] );
		$this->assertSame( 'Calendar desc', $result['description'] );
	}

	public function test_parse_calendar_event_allday_false(): void {
		$odoo = [
			'name'   => 'Partial Day',
			'start'  => '2026-07-01 10:00:00',
			'stop'   => '2026-07-01 12:00:00',
			'allday' => false,
		];

		$result = $this->handler->parse_event_from_odoo( $odoo, false );
		$this->assertFalse( $result['all_day'] );
	}

	// ─── save_event ─────────────────────────────────────

	public function test_save_event_creates_new_post(): void {
		$data = [
			'name'        => 'New Event',
			'description' => 'Created from Odoo',
			'start_date'  => '2026-07-01 10:00:00',
			'end_date'    => '2026-07-01 18:00:00',
			'timezone'    => 'Europe/Paris',
			'all_day'     => false,
		];

		$post_id = $this->handler->save_event( $data, 0 );

		$this->assertGreaterThan( 0, $post_id );
	}

	public function test_save_event_updates_existing_post(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'post_type'    => 'tribe_events',
			'post_title'   => 'Old Title',
			'post_content' => '',
		];

		$data = [
			'name'        => 'Updated Title',
			'description' => 'Updated content',
			'start_date'  => '2026-07-01 10:00:00',
			'end_date'    => '2026-07-01 18:00:00',
			'timezone'    => 'UTC',
			'all_day'     => false,
		];

		$post_id = $this->handler->save_event( $data, 10 );

		$this->assertSame( 10, $post_id );
	}

	public function test_save_event_sets_meta_fields(): void {
		$data = [
			'name'        => 'Meta Test Event',
			'description' => '',
			'start_date'  => '2026-07-01 09:00:00',
			'end_date'    => '2026-07-01 17:00:00',
			'timezone'    => 'America/New_York',
			'all_day'     => true,
		];

		$post_id = $this->handler->save_event( $data, 0 );

		$this->assertSame( '2026-07-01 09:00:00', $GLOBALS['_wp_post_meta'][ $post_id ]['_EventStartDateUTC'] );
		$this->assertSame( '2026-07-01 17:00:00', $GLOBALS['_wp_post_meta'][ $post_id ]['_EventEndDateUTC'] );
		$this->assertSame( 'America/New_York', $GLOBALS['_wp_post_meta'][ $post_id ]['_EventTimezone'] );
		$this->assertSame( 'yes', $GLOBALS['_wp_post_meta'][ $post_id ]['_EventAllDay'] );
	}

	public function test_save_event_allday_empty_when_false(): void {
		$data = [
			'name'        => 'Not All Day',
			'description' => '',
			'start_date'  => '2026-07-01 09:00:00',
			'end_date'    => '2026-07-01 17:00:00',
			'timezone'    => 'UTC',
			'all_day'     => false,
		];

		$post_id = $this->handler->save_event( $data, 0 );

		$this->assertSame( '', $GLOBALS['_wp_post_meta'][ $post_id ]['_EventAllDay'] );
	}

	// ─── save_ticket ────────────────────────────────────

	public function test_save_ticket_creates_new_post(): void {
		$data = [
			'name'       => 'VIP Ticket',
			'list_price' => 99.99,
		];

		$post_id = $this->handler->save_ticket( $data, 0 );

		$this->assertGreaterThan( 0, $post_id );
	}

	public function test_save_ticket_updates_existing(): void {
		$GLOBALS['_wp_posts'][20] = (object) [
			'post_type'  => 'tribe_rsvp_tickets',
			'post_title' => 'Old Ticket',
		];

		$data = [
			'name'       => 'Updated Ticket',
			'list_price' => 50.0,
		];

		$post_id = $this->handler->save_ticket( $data, 20 );

		$this->assertSame( 20, $post_id );
	}

	public function test_save_ticket_sets_price_meta(): void {
		$data = [
			'name'       => 'Priced Ticket',
			'list_price' => 25.50,
		];

		$post_id = $this->handler->save_ticket( $data, 0 );

		$this->assertSame( '25.5', $GLOBALS['_wp_post_meta'][ $post_id ]['_price'] );
	}
}
