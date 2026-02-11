<?php
/**
 * The Events Calendar + Event Tickets class and function stubs for PHPUnit tests.
 *
 * @package WP4Odoo\Tests
 */

// ─── Global stores ──────────────────────────────────────

$GLOBALS['_tribe_events']    = [];
$GLOBALS['_tribe_tickets']   = [];
$GLOBALS['_tribe_attendees'] = [];

// ─── The Events Calendar ────────────────────────────────

if ( ! class_exists( 'Tribe__Events__Main' ) ) {
	class Tribe__Events__Main {
		const POSTTYPE           = 'tribe_events';
		const VENUE_POST_TYPE    = 'tribe_venue';
		const ORGANIZER_POST_TYPE = 'tribe_organizer';
		public static string $version = '6.8.0';
	}
}

// ─── Event Tickets ──────────────────────────────────────

if ( ! class_exists( 'Tribe__Tickets__Main' ) ) {
	class Tribe__Tickets__Main {
		public static string $version = '5.14.0';
	}
}
