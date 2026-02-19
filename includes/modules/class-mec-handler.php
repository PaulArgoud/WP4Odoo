<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MEC Handler — data access for Modern Events Calendar events
 * and MEC Pro bookings.
 *
 * Loads mec-events CPT data plus custom table metadata, and MEC Pro
 * booking posts. Formats data for Odoo event.event / calendar.event
 * (dual-model) and event.registration. Also provides reverse parsing
 * (Odoo → WP) and save methods for pull sync.
 *
 * Called by MEC_Module via its load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   3.8.0
 */
class MEC_Handler {

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
	 * Load an event from the mec-events CPT plus MEC custom table.
	 *
	 * Combines post data with dates from the {prefix}mec_events table.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Event data, or empty if not found.
	 */
	public function load_event( int $post_id ): array {
		$post = \get_post( $post_id );
		if ( ! $post || 'mec-events' !== $post->post_type ) {
			$this->logger->warning( 'MEC event not found or wrong post type.', [ 'post_id' => $post_id ] );
			return [];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mec_events';
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id )
		);

		$start = '';
		$end   = '';
		if ( $row ) {
			$start = $row->start ?? '';
			$end   = $row->end ?? '';
		}

		// Fallback to post meta if table data is missing.
		if ( empty( $start ) ) {
			$start = (string) \get_post_meta( $post_id, 'mec_start_date', true );
		}
		if ( empty( $end ) ) {
			$end = (string) \get_post_meta( $post_id, 'mec_end_date', true );
		}

		return [
			'name'        => $post->post_title,
			'description' => $post->post_content,
			'start_date'  => $start,
			'end_date'    => $end,
			'timezone'    => \wp_timezone_string(),
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
			'allday'      => false,
			'description' => $data['description'] ?? '',
		];
	}

	// ─── Load booking ────────────────────────────────────

	/**
	 * Load an MEC Pro booking.
	 *
	 * MEC Pro stores bookings as a custom post type with attendee data
	 * in post meta.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return array<string, mixed> Booking data, or empty if not found.
	 */
	public function load_booking( int $booking_id ): array {
		$post = \get_post( $booking_id );
		if ( ! $post ) {
			$this->logger->warning( 'MEC booking not found.', [ 'booking_id' => $booking_id ] );
			return [];
		}

		return [
			'name'     => (string) \get_post_meta( $booking_id, 'mec_attendee_name', true ),
			'email'    => (string) \get_post_meta( $booking_id, 'mec_attendee_email', true ),
			'event_id' => (int) \get_post_meta( $booking_id, 'mec_event_id', true ),
		];
	}

	// ─── Format booking ──────────────────────────────────

	/**
	 * Format booking data for Odoo event.registration.
	 *
	 * @param array<string, mixed> $data          Booking data from load_booking().
	 * @param int                  $partner_id    Resolved Odoo partner ID.
	 * @param int                  $event_odoo_id Resolved Odoo event.event ID.
	 * @return array<string, mixed> Data for event.registration create/write.
	 */
	public function format_booking( array $data, int $partner_id, int $event_odoo_id ): array {
		return [
			'event_id'   => $event_odoo_id,
			'partner_id' => $partner_id,
			'name'       => $data['name'] ?? '',
			'email'      => $data['email'] ?? '',
		];
	}

	// ─── Parse event from Odoo ────────────────────────────

	/**
	 * Parse Odoo event data into WordPress-compatible format.
	 *
	 * Reverse of format_event(). Handles both event.event and
	 * calendar.event field layouts.
	 *
	 * @param array<string, mixed> $odoo_data       Odoo record data.
	 * @param bool                 $use_event_model True for event.event, false for calendar.event.
	 * @return array<string, mixed> WordPress event data.
	 */
	public function parse_event_from_odoo( array $odoo_data, bool $use_event_model ): array {
		if ( $use_event_model ) {
			return [
				'name'        => $odoo_data['name'] ?? '',
				'start_date'  => $odoo_data['date_begin'] ?? '',
				'end_date'    => $odoo_data['date_end'] ?? '',
				'timezone'    => $odoo_data['date_tz'] ?? 'UTC',
				'description' => $odoo_data['description'] ?? '',
			];
		}

		return [
			'name'        => $odoo_data['name'] ?? '',
			'start_date'  => $odoo_data['start'] ?? '',
			'end_date'    => $odoo_data['stop'] ?? '',
			'timezone'    => '',
			'description' => $odoo_data['description'] ?? '',
		];
	}

	// ─── Save event ──────────────────────────────────────

	/**
	 * Save event data to an mec-events CPT post.
	 *
	 * Creates a new post when $wp_id is 0, updates an existing one otherwise.
	 * Also writes dates to post meta (MEC reads them from both sources).
	 *
	 * @param array<string, mixed> $data  Parsed event data from parse_event_from_odoo().
	 * @param int                  $wp_id Existing post ID (0 to create new).
	 * @return int The post ID, or 0 on failure.
	 */
	public function save_event( array $data, int $wp_id = 0 ): int {
		$post_args = [
			'post_title'   => $data['name'] ?? '',
			'post_content' => $data['description'] ?? '',
			'post_type'    => 'mec-events',
			'post_status'  => 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_args['ID'] = $wp_id;
			$result          = \wp_update_post( $post_args, true );
		} else {
			$result = \wp_insert_post( $post_args, true );
		}

		if ( \is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to save MEC event post.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		$post_id = $result;

		\update_post_meta( $post_id, 'mec_start_date', $data['start_date'] ?? '' );
		\update_post_meta( $post_id, 'mec_end_date', $data['end_date'] ?? '' );

		return $post_id;
	}

	// ─── Helpers ──────────────────────────────────────────

	/**
	 * Get the event ID for a booking.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return int Event post ID, or 0 if not found.
	 */
	public function get_event_id_for_booking( int $booking_id ): int {
		return (int) \get_post_meta( $booking_id, 'mec_event_id', true );
	}
}
