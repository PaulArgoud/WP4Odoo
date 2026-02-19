<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Odoo_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modern Events Calendar Module — bidirectional sync for events,
 * push-only for bookings.
 *
 * Syncs MEC events as Odoo events (event.event) or calendar entries
 * (calendar.event fallback), and MEC Pro bookings as event registrations
 * (event.registration).
 *
 * Events are bidirectional (push + pull). Bookings are push-only
 * (they originate in WordPress forms).
 *
 * Dual-model: probes Odoo for event.event model at runtime.
 * If available, events and bookings use the rich event module.
 * If not, events fall back to calendar.event and bookings are skipped.
 *
 * Requires Modern Events Calendar to be active.
 *
 * Exclusive group: events — mutually exclusive with The Events Calendar.
 *
 * @package WP4Odoo
 * @since   3.8.0
 */
class MEC_Module extends Module_Base {

	use MEC_Hooks;

	protected const PLUGIN_MIN_VERSION  = '6.0';
	protected const PLUGIN_TESTED_UP_TO = '7.15';

	/**
	 * Exclusive group — only one events module can boot.
	 *
	 * @var string
	 */
	protected string $exclusive_group = 'events';

	/**
	 * Sync direction: bidirectional for events, push-only for bookings.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Odoo models by entity type (preferred models).
	 *
	 * event.event may fall back to calendar.event at runtime via
	 * get_odoo_model() override. Bookings require event.event.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'event'   => 'event.event',
		'booking' => 'event.registration',
	];

	/**
	 * Default field mappings.
	 *
	 * Event and booking mappings are identity (pre-formatted by handler).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'event'   => [
			'name'        => 'name',
			'date_begin'  => 'date_begin',
			'date_end'    => 'date_end',
			'date_tz'     => 'date_tz',
			'description' => 'description',
		],
		'booking' => [
			'event_id'   => 'event_id',
			'partner_id' => 'partner_id',
			'name'       => 'name',
			'email'      => 'email',
		],
	];

	/**
	 * MEC data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var MEC_Handler
	 */
	private MEC_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'mec', 'Modern Events Calendar', $client_provider, $entity_map, $settings );
		$this->handler = new MEC_Handler( $this->logger );
	}

	/**
	 * Boot the module: register MEC hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'MEC_VERSION' ) && ! class_exists( 'MEC' ) ) {
			$this->logger->warning( \__( 'MEC module enabled but Modern Events Calendar is not active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_events'   => true,
			'sync_bookings' => true,
			'pull_events'   => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_events'   => [
				'label'       => \__( 'Sync events', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => \__( 'Push MEC events to Odoo (event.event or calendar.event).', 'wp4odoo' ),
			],
			'sync_bookings' => [
				'label'       => \__( 'Sync bookings', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => \__( 'Push MEC Pro bookings to Odoo as event registrations. Requires MEC Pro and Odoo Events module.', 'wp4odoo' ),
			],
			'pull_events'   => [
				'label'       => \__( 'Pull events from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => \__( 'Pull event changes from Odoo back to WordPress.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for Modern Events Calendar.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		$available = defined( 'MEC_VERSION' ) || class_exists( 'MEC' );
		return $this->check_dependency( $available, 'Modern Events Calendar' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'MEC_VERSION' ) ? (string) MEC_VERSION : '';
	}

	// ─── Translation ──────────────────────────────────────

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

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Events dedup by name. Bookings dedup by email on event.registration.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'event' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		if ( 'booking' === $entity_type && ! empty( $odoo_values['email'] ) && ! empty( $odoo_values['event_id'] ) ) {
			return [
				[ 'email', '=', $odoo_values['email'] ],
				[ 'event_id', '=', $odoo_values['event_id'] ],
			];
		}

		return [];
	}

	// ─── Dual-model detection ──────────────────────────────

	/**
	 * Check whether Odoo has the event.event model (Events module).
	 *
	 * Delegates to Module_Helpers::has_odoo_model().
	 *
	 * @return bool
	 */
	private function has_event_model(): bool {
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

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Bookings are push-only and cannot be pulled. Events delegate to
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
		if ( 'booking' === $entity_type ) {
			$this->logger->info( 'Booking pull not supported — bookings originate in WordPress.', [ 'odoo_id' => $odoo_id ] );
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
	 * Events use the handler's parse method (dual-model aware).
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'event' === $entity_type ) {
			return $this->handler->parse_event_from_odoo( $odoo_data, $this->has_event_model() );
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
			return $this->handler->save_event( $data, $wp_id );
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

	// ─── Push override ─────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For bookings: skip if event.event not available, ensure event synced.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'booking' === $entity_type && 'delete' !== $action ) {
			if ( ! $this->has_event_model() ) {
				$this->logger->info( 'event.event not available — skipping booking push.', [ 'booking_id' => $wp_id ] );
				return \WP4Odoo\Sync_Result::success();
			}
			$this->ensure_event_synced_for_booking( $wp_id );
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Events and bookings bypass standard mapping — the data is pre-formatted
	 * by the handler.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'event' === $entity_type || 'booking' === $entity_type ) {
			return $wp_data;
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	// ─── Data access ───────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'event'   => $this->load_event_data( $wp_id ),
			'booking' => $this->load_booking_data( $wp_id ),
			default   => [],
		};
	}

	/**
	 * Load and format an event for the target Odoo model.
	 *
	 * @param int $post_id Event post ID.
	 * @return array<string, mixed>
	 */
	private function load_event_data( int $post_id ): array {
		$data = $this->handler->load_event( $post_id );
		if ( empty( $data ) ) {
			return [];
		}

		return $this->handler->format_event( $data, $this->has_event_model() );
	}

	/**
	 * Load and resolve a booking with Odoo references.
	 *
	 * @param int $booking_id MEC booking post ID.
	 * @return array<string, mixed>
	 */
	private function load_booking_data( int $booking_id ): array {
		$data = $this->handler->load_booking( $booking_id );
		if ( empty( $data ) ) {
			return [];
		}

		$email = $data['email'] ?? '';
		$name  = $data['name'] ?? '';

		if ( empty( $email ) ) {
			$this->logger->warning( 'MEC booking has no email.', [ 'booking_id' => $booking_id ] );
			return [];
		}

		$partner_id = $this->resolve_partner_from_email( $email, $name ?: $email );

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for MEC booking.', [ 'booking_id' => $booking_id ] );
			return [];
		}

		$event_wp_id   = $data['event_id'] ?? 0;
		$event_odoo_id = 0;
		if ( $event_wp_id > 0 ) {
			$event_odoo_id = $this->get_mapping( 'event', $event_wp_id ) ?? 0;
		}

		if ( ! $event_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo event for MEC booking.', [ 'event_id' => $event_wp_id ] );
			return [];
		}

		return $this->handler->format_booking( $data, $partner_id, $event_odoo_id );
	}

	// ─── Event auto-sync ───────────────────────────────────

	/**
	 * Ensure the event is synced before pushing a booking.
	 *
	 * @param int $booking_id MEC booking post ID.
	 * @return void
	 */
	private function ensure_event_synced_for_booking( int $booking_id ): void {
		$event_id = $this->handler->get_event_id_for_booking( $booking_id );
		$this->ensure_entity_synced( 'event', $event_id );
	}
}
