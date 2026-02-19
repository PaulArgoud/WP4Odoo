<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Odoo_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for event modules (Events Calendar, MEC, FooEvents).
 *
 * Provides shared sync logic for modules that sync events as Odoo events
 * (event.event) or calendar entries (calendar.event fallback), and
 * attendance records (attendees or bookings) as Odoo event registrations
 * (event.registration).
 *
 * Dual-model: probes Odoo for event.event at runtime. If available,
 * events and attendance use the rich event module. If not, events fall
 * back to calendar.event and attendance is skipped.
 *
 * Bidirectional: events are synced both ways (Odoo ↔ WP).
 * Attendance is push-only (originates in WordPress).
 *
 * Subclasses provide plugin-specific handler delegation via abstract
 * methods. The base class handles dual-model detection, push/pull
 * overrides, event formatting, attendance resolution, and deduplication.
 *
 * @package WP4Odoo
 * @since   3.9.0
 */
abstract class Events_Module_Base extends Module_Base {

	// ─── Subclass configuration ─────────────────────────────

	/**
	 * Get the entity type used for attendance (attendees or bookings).
	 *
	 * @return string 'attendee' or 'booking'.
	 */
	abstract protected function get_attendance_entity_type(): string;

	// ─── Handler delegation ─────────────────────────────────

	/**
	 * Load an event from the plugin's data store.
	 *
	 * Must return an array with at least: name, description, start_date,
	 * end_date, timezone. May include all_day (bool).
	 *
	 * @param int $wp_id WordPress event/post ID.
	 * @return array<string, mixed> Event data, or empty if not found.
	 */
	abstract protected function handler_load_event( int $wp_id ): array;

	/**
	 * Load an attendance record (attendee or booking) from the plugin.
	 *
	 * Must return an array with at least: name, email, event_id (WP ID
	 * of the associated event).
	 *
	 * @param int $wp_id WordPress attendance record ID.
	 * @return array<string, mixed> Attendance data, or empty if not found.
	 */
	abstract protected function handler_load_attendance( int $wp_id ): array;

	/**
	 * Save an event pulled from Odoo to the plugin's data store.
	 *
	 * @param array<string, mixed> $data  Parsed event data.
	 * @param int                  $wp_id Existing event ID (0 to create new).
	 * @return int The event ID, or 0 on failure.
	 */
	abstract protected function handler_save_event( array $data, int $wp_id ): int;

	/**
	 * Get the event ID associated with an attendance record.
	 *
	 * @param int $attendance_id WordPress attendance record ID.
	 * @return int Event WP ID, or 0 if not found.
	 */
	abstract protected function handler_get_event_id_for_attendance( int $attendance_id ): int;

	// ─── Shared sync direction ──────────────────────────────

	/**
	 * Sync direction: bidirectional (events ↔, attendance →).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	// ─── Dual-model detection ───────────────────────────────

	/**
	 * Check whether Odoo has the event.event model (Events module).
	 *
	 * Delegates to Module_Helpers::has_odoo_model().
	 *
	 * @return bool
	 */
	protected function has_event_model(): bool {
		return $this->has_odoo_model( Odoo_Model::EventEvent, 'wp4odoo_has_event_event' );
	}

	/**
	 * Resolve the Odoo model for an entity type at runtime.
	 *
	 * Falls back to calendar.event for events when event.event is unavailable.
	 *
	 * @param string $entity_type Entity type.
	 * @return string Odoo model name.
	 */
	protected function get_odoo_model( string $entity_type ): string {
		if ( 'event' === $entity_type && ! $this->has_event_model() ) {
			return Odoo_Model::CalendarEvent->value;
		}

		return parent::get_odoo_model( $entity_type );
	}

	// ─── Translation ────────────────────────────────────────

	/**
	 * Translatable fields for events (name + description).
	 *
	 * @param string $entity_type Entity type.
	 * @return array<string, string> Odoo field => WP field.
	 */
	protected function get_translatable_fields( string $entity_type ): array {
		if ( 'event' === $entity_type ) {
			return [
				'name'        => 'post_title',
				'description' => 'post_content',
			];
		}

		return [];
	}

	// ─── Deduplication ──────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Events dedup by name. Attendance dedup by email + event_id on
	 * event.registration.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'event' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		if ( $this->get_attendance_entity_type() === $entity_type && ! empty( $odoo_values['email'] ) && ! empty( $odoo_values['event_id'] ) ) {
			return [
				[ 'email', '=', $odoo_values['email'] ],
				[ 'event_id', '=', $odoo_values['event_id'] ],
			];
		}

		return [];
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For attendance: skip if event.event not available, ensure event synced.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		$att = $this->get_attendance_entity_type();

		if ( $att === $entity_type && 'delete' !== $action ) {
			if ( ! $this->has_event_model() ) {
				$this->logger->info( "event.event not available — skipping {$att} push.", [ $att . '_id' => $wp_id ] );
				return \WP4Odoo\Sync_Result::success();
			}
			$this->ensure_event_synced_for_attendance( $wp_id );
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Events and attendance bypass standard mapping — the data is
	 * pre-formatted by the handler.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'event' === $entity_type || $this->get_attendance_entity_type() === $entity_type ) {
			return $wp_data;
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	// ─── Pull override ──────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Attendance is push-only and cannot be pulled. Events delegate to
	 * the parent pull_from_odoo() infrastructure.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		$att = $this->get_attendance_entity_type();

		if ( $att === $entity_type ) {
			$label = ucfirst( $att );
			$this->logger->info( "{$label} pull not supported — {$att}s originate in WordPress.", [ 'odoo_id' => $odoo_id ] );
			return \WP4Odoo\Sync_Result::success();
		}

		$settings = $this->get_settings();

		if ( 'event' === $entity_type && empty( $settings['pull_events'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * Events use the shared parse method (dual-model aware).
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'event' === $entity_type ) {
			return $this->parse_event_from_odoo_data( $odoo_data, $this->has_event_model() );
		}

		return parent::map_from_odoo( $entity_type, $odoo_data );
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'event' === $entity_type ) {
			return $this->handler_save_event( $data, $wp_id );
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress post ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'event' === $entity_type ) {
			$deleted = \wp_delete_post( $wp_id, true );
			return false !== $deleted && null !== $deleted;
		}

		return false;
	}

	// ─── Data access ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'event'                              => $this->load_event_data( $wp_id ),
			$this->get_attendance_entity_type()  => $this->load_attendance_data( $wp_id ),
			default                              => [],
		};
	}

	/**
	 * Load and format an event for the target Odoo model.
	 *
	 * @param int $wp_id Event post/product ID.
	 * @return array<string, mixed>
	 */
	private function load_event_data( int $wp_id ): array {
		$data = $this->handler_load_event( $wp_id );
		if ( empty( $data ) ) {
			return [];
		}

		return $this->format_event_for_odoo( $data, $this->has_event_model() );
	}

	/**
	 * Load and resolve an attendance record with Odoo references.
	 *
	 * Loads attendance data via handler, resolves the attendee/booker to
	 * an Odoo partner, resolves the event to an Odoo event ID, and
	 * formats for event.registration.
	 *
	 * @param int $wp_id WordPress attendance record ID.
	 * @return array<string, mixed>
	 */
	private function load_attendance_data( int $wp_id ): array {
		$data = $this->handler_load_attendance( $wp_id );
		if ( empty( $data ) ) {
			return [];
		}

		$att   = $this->get_attendance_entity_type();
		$email = $data['email'] ?? '';
		$name  = $data['name'] ?? '';

		if ( empty( $email ) ) {
			$this->logger->warning( ucfirst( $att ) . ' has no email.', [ $att . '_id' => $wp_id ] );
			return [];
		}

		$partner_id = $this->resolve_partner_from_email( $email, $name ?: $email );

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for ' . $att . '.', [ $att . '_id' => $wp_id ] );
			return [];
		}

		$event_wp_id   = $data['event_id'] ?? 0;
		$event_odoo_id = 0;
		if ( $event_wp_id > 0 ) {
			$event_odoo_id = $this->get_mapping( 'event', $event_wp_id ) ?? 0;
		}

		if ( ! $event_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo event for ' . $att . '.', [ 'event_id' => $event_wp_id ] );
			return [];
		}

		return [
			'event_id'   => $event_odoo_id,
			'partner_id' => $partner_id,
			'name'       => $name,
			'email'      => $email,
		];
	}

	// ─── Event auto-sync ────────────────────────────────────

	/**
	 * Ensure the event is synced before pushing an attendance record.
	 *
	 * @param int $attendance_id WordPress attendance record ID.
	 * @return void
	 */
	private function ensure_event_synced_for_attendance( int $attendance_id ): void {
		$event_id = $this->handler_get_event_id_for_attendance( $attendance_id );
		$this->ensure_entity_synced( 'event', $event_id );
	}

	// ─── Shared formatting ──────────────────────────────────

	/**
	 * Format event data for Odoo.
	 *
	 * Returns data formatted for event.event or calendar.event depending
	 * on the $use_event_model flag. Common across all event modules.
	 *
	 * @param array<string, mixed> $data            Event data from handler_load_event().
	 * @param bool                 $use_event_model True for event.event, false for calendar.event.
	 * @return array<string, mixed> Odoo-ready data.
	 */
	protected function format_event_for_odoo( array $data, bool $use_event_model ): array {
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

	/**
	 * Parse Odoo event data into WordPress-compatible format.
	 *
	 * Reverse of format_event_for_odoo(). Handles both event.event and
	 * calendar.event field layouts. Common across all event modules.
	 *
	 * @param array<string, mixed> $odoo_data       Odoo record data.
	 * @param bool                 $use_event_model True for event.event, false for calendar.event.
	 * @return array<string, mixed> WordPress event data.
	 */
	protected function parse_event_from_odoo_data( array $odoo_data, bool $use_event_model ): array {
		if ( $use_event_model ) {
			return [
				'name'        => $odoo_data['name'] ?? '',
				'start_date'  => $odoo_data['date_begin'] ?? '',
				'end_date'    => $odoo_data['date_end'] ?? '',
				'timezone'    => $odoo_data['date_tz'] ?? 'UTC',
				'all_day'     => false,
				'description' => $odoo_data['description'] ?? '',
			];
		}

		return [
			'name'        => $odoo_data['name'] ?? '',
			'start_date'  => $odoo_data['start'] ?? '',
			'end_date'    => $odoo_data['stop'] ?? '',
			'timezone'    => '',
			'all_day'     => ! empty( $odoo_data['allday'] ),
			'description' => $odoo_data['description'] ?? '',
		];
	}
}
