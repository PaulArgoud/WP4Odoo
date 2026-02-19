<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FooEvents Handler — data access for FooEvents event products and tickets.
 *
 * FooEvents marks WooCommerce products as events via the
 * `WooCommerceEventsEvent` post meta field. Event dates, location, and
 * other metadata are stored as WC product post meta. Tickets are stored
 * as the `event_magic_tickets` CPT with attendee data in post meta.
 *
 * Loads event data from WC product meta and ticket/attendee data from
 * the event_magic_tickets CPT. Formats data for Odoo event.event /
 * calendar.event (dual-model) and event.registration.
 *
 * Called by FooEvents_Module via its load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   3.8.0
 */
class FooEvents_Handler {

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
	 * Load an event from a WooCommerce product with FooEvents meta.
	 *
	 * Only returns data if the product has the FooEvents event marker.
	 *
	 * @param int $product_id WC product post ID.
	 * @return array<string, mixed> Event data, or empty if not an event.
	 */
	public function load_event( int $product_id ): array {
		$post = \get_post( $product_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			$this->logger->warning( 'FooEvents: product not found or wrong post type.', [ 'product_id' => $product_id ] );
			return [];
		}

		$is_event = \get_post_meta( $product_id, 'WooCommerceEventsEvent', true );
		if ( 'Event' !== $is_event ) {
			return [];
		}

		return [
			'name'        => $post->post_title,
			'description' => $post->post_content,
			'start_date'  => (string) \get_post_meta( $product_id, 'WooCommerceEventsDate', true ),
			'end_date'    => (string) \get_post_meta( $product_id, 'WooCommerceEventsEndDate', true ),
			'timezone'    => \wp_timezone_string(),
			'location'    => (string) \get_post_meta( $product_id, 'WooCommerceEventsLocation', true ),
		];
	}

	/**
	 * Check whether a WC product is a FooEvents event.
	 *
	 * @param int $product_id WC product post ID.
	 * @return bool
	 */
	public function is_fooevents_product( int $product_id ): bool {
		return 'Event' === \get_post_meta( $product_id, 'WooCommerceEventsEvent', true );
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

	// ─── Load attendee ────────────────────────────────────

	/**
	 * Load a FooEvents ticket/attendee.
	 *
	 * FooEvents stores attendee data in the event_magic_tickets CPT
	 * with meta fields for name, email, and event product ID.
	 *
	 * @param int $ticket_id Ticket post ID.
	 * @return array<string, mixed> Attendee data, or empty if not found.
	 */
	public function load_attendee( int $ticket_id ): array {
		$post = \get_post( $ticket_id );
		if ( ! $post ) {
			$this->logger->warning( 'FooEvents ticket not found.', [ 'ticket_id' => $ticket_id ] );
			return [];
		}

		return [
			'name'     => (string) \get_post_meta( $ticket_id, 'WooCommerceEventsAttendeeName', true ),
			'email'    => (string) \get_post_meta( $ticket_id, 'WooCommerceEventsAttendeeEmail', true ),
			'event_id' => (int) \get_post_meta( $ticket_id, 'WooCommerceEventsProductID', true ),
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
	 * Save event data to a WC product with FooEvents meta.
	 *
	 * Updates post title/content and FooEvents date meta. Does not
	 * create new products — WC module owns product creation.
	 *
	 * @param array<string, mixed> $data  Parsed event data from parse_event_from_odoo().
	 * @param int                  $wp_id Existing product ID (0 to create new).
	 * @return int The product ID, or 0 on failure.
	 */
	public function save_event( array $data, int $wp_id = 0 ): int {
		$post_args = [
			'post_title'   => $data['name'] ?? '',
			'post_content' => $data['description'] ?? '',
			'post_type'    => 'product',
			'post_status'  => 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_args['ID'] = $wp_id;
			$result          = \wp_update_post( $post_args, true );
		} else {
			$result = \wp_insert_post( $post_args, true );
		}

		if ( \is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to save FooEvents event product.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		$post_id = $result;

		\update_post_meta( $post_id, 'WooCommerceEventsEvent', 'Event' );
		\update_post_meta( $post_id, 'WooCommerceEventsDate', $data['start_date'] ?? '' );
		\update_post_meta( $post_id, 'WooCommerceEventsEndDate', $data['end_date'] ?? '' );

		return $post_id;
	}

	// ─── Helpers ──────────────────────────────────────────

	/**
	 * Get the event product ID for a ticket/attendee.
	 *
	 * @param int $ticket_id Ticket post ID.
	 * @return int Product post ID, or 0 if not found.
	 */
	public function get_event_id_for_attendee( int $ticket_id ): int {
		return (int) \get_post_meta( $ticket_id, 'WooCommerceEventsProductID', true );
	}
}
