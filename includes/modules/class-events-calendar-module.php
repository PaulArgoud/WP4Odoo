<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events Calendar Module — bidirectional sync for events and tickets,
 * push-only for attendees.
 *
 * Syncs The Events Calendar events as Odoo events (event.event) or calendar
 * entries (calendar.event fallback), Event Tickets RSVP ticket types as
 * service products (product.product), and RSVP attendees as event
 * registrations (event.registration).
 *
 * Events and tickets are bidirectional (push + pull). Attendees are
 * push-only (they originate in WordPress RSVP forms).
 *
 * Dual-model: probes Odoo for event.event model at runtime.
 * If available, events and attendees use the rich event module.
 * If not, events fall back to calendar.event and attendees are skipped.
 *
 * Requires The Events Calendar to be active. Event Tickets is optional
 * (enables ticket and attendee sync when present).
 *
 * Independent module — coexists with all other modules.
 *
 * @package WP4Odoo
 * @since   2.7.0
 */
class Events_Calendar_Module extends Module_Base {

	use Events_Calendar_Hooks;

	/**
	 * Sync direction: bidirectional for events/tickets, push-only for attendees.
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
	 * get_odoo_model() override. Attendees require event.event.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'event'    => 'event.event',
		'ticket'   => 'product.product',
		'attendee' => 'event.registration',
	];

	/**
	 * Default field mappings.
	 *
	 * Event and attendee mappings are identity (pre-formatted by handler).
	 * Ticket mapping uses standard field mapping.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'event'    => [
			'name'        => 'name',
			'date_begin'  => 'date_begin',
			'date_end'    => 'date_end',
			'date_tz'     => 'date_tz',
			'description' => 'description',
		],
		'ticket'   => [
			'name'       => 'name',
			'list_price' => 'list_price',
			'type'       => 'type',
		],
		'attendee' => [
			'event_id'   => 'event_id',
			'partner_id' => 'partner_id',
			'name'       => 'name',
			'email'      => 'email',
		],
	];

	/**
	 * Events Calendar data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Events_Calendar_Handler
	 */
	private Events_Calendar_Handler $handler;

	/**
	 * Cached event.event model detection result.
	 *
	 * @var bool|null
	 */
	private ?bool $event_model_detected = null;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'events_calendar', 'The Events Calendar', $client_provider, $entity_map, $settings );
		$this->handler = new Events_Calendar_Handler( $this->logger );
	}

	/**
	 * Boot the module: register Events Calendar + Event Tickets hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'Tribe__Events__Main' ) ) {
			$this->logger->warning( \__( 'Events Calendar module enabled but The Events Calendar is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_events'] ) ) {
			\add_action( 'save_post_tribe_events', [ $this, 'on_event_save' ], 10, 1 );
		}

		// Ticket and attendee hooks only if Event Tickets is active.
		if ( class_exists( 'Tribe__Tickets__Main' ) ) {
			if ( ! empty( $settings['sync_tickets'] ) ) {
				\add_action( 'save_post_tribe_rsvp_tickets', [ $this, 'on_ticket_save' ], 10, 1 );
			}

			if ( ! empty( $settings['sync_attendees'] ) ) {
				\add_action( 'event_tickets_rsvp_ticket_created', [ $this, 'on_rsvp_attendee_created' ], 10, 4 );
			}
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_events'    => true,
			'sync_tickets'   => true,
			'sync_attendees' => true,
			'pull_events'    => true,
			'pull_tickets'   => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_events'    => [
				'label'       => \__( 'Sync events', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => \__( 'Push events to Odoo (event.event or calendar.event).', 'wp4odoo' ),
			],
			'sync_tickets'   => [
				'label'       => \__( 'Sync tickets', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => \__( 'Push RSVP ticket types to Odoo as service products. Requires Event Tickets.', 'wp4odoo' ),
			],
			'sync_attendees' => [
				'label'       => \__( 'Sync attendees', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => \__( 'Push RSVP attendees to Odoo as event registrations. Requires Event Tickets and Odoo Events module.', 'wp4odoo' ),
			],
			'pull_events'    => [
				'label'       => \__( 'Pull events from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => \__( 'Pull event changes from Odoo back to WordPress.', 'wp4odoo' ),
			],
			'pull_tickets'   => [
				'label'       => \__( 'Pull tickets from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => \__( 'Pull ticket product changes from Odoo back to WordPress.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for The Events Calendar.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'Tribe__Events__Main' ), 'The Events Calendar' );
	}

	// ─── Dual-model detection ──────────────────────────────

	/**
	 * Check whether Odoo has the event.event model (Events module).
	 *
	 * Probes ir.model. Result cached in transient (1 hour) and in-memory.
	 *
	 * @return bool
	 */
	private function has_event_model(): bool {
		if ( null !== $this->event_model_detected ) {
			return $this->event_model_detected;
		}

		$cached = \get_transient( 'wp4odoo_has_event_event' );
		if ( false !== $cached ) {
			$this->event_model_detected = (bool) $cached;
			return $this->event_model_detected;
		}

		try {
			$count  = $this->client()->search_count(
				'ir.model',
				[ [ 'model', '=', 'event.event' ] ]
			);
			$result = $count > 0;
		} catch ( \Exception $e ) {
			$result = false;
		}

		\set_transient( 'wp4odoo_has_event_event', $result ? 1 : 0, HOUR_IN_SECONDS );
		$this->event_model_detected = $result;

		return $result;
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
			return 'calendar.event';
		}

		return parent::get_odoo_model( $entity_type );
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Attendees are push-only and cannot be pulled. Events and tickets
	 * delegate to the parent pull_from_odoo() infrastructure.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'attendee' === $entity_type ) {
			$this->logger->info( 'Attendee pull not supported — attendees originate in WordPress.', [ 'odoo_id' => $odoo_id ] );
			return \WP4Odoo\Sync_Result::success( 0 );
		}

		$settings = $this->get_settings();

		if ( 'event' === $entity_type && empty( $settings['pull_events'] ) ) {
			return \WP4Odoo\Sync_Result::success( 0 );
		}

		if ( 'ticket' === $entity_type && empty( $settings['pull_tickets'] ) ) {
			return \WP4Odoo\Sync_Result::success( 0 );
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * Events use the handler's parse method (dual-model aware).
	 * Tickets use standard reverse field mapping from parent.
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
		return match ( $entity_type ) {
			'event'  => $this->handler->save_event( $data, $wp_id ),
			'ticket' => $this->handler->save_ticket( $data, $wp_id ),
			default  => 0,
		};
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress post ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'event' === $entity_type || 'ticket' === $entity_type ) {
			$deleted = \wp_delete_post( $wp_id, true );
			return false !== $deleted && null !== $deleted;
		}

		return false;
	}

	// ─── Push override ─────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For attendees: skip if event.event not available, ensure event synced.
	 * For tickets: standard push (product.product always available).
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		// Attendees require the Odoo Events module.
		if ( 'attendee' === $entity_type && 'delete' !== $action ) {
			if ( ! $this->has_event_model() ) {
				$this->logger->info( 'event.event not available — skipping attendee push.', [ 'attendee_id' => $wp_id ] );
				return \WP4Odoo\Sync_Result::success( 0 );
			}
			$this->ensure_event_synced_for_attendee( $wp_id );
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Events and attendees bypass standard mapping — the data is pre-formatted
	 * by the handler. Tickets use standard field mapping plus a hardcoded type.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'event' === $entity_type || 'attendee' === $entity_type ) {
			return $wp_data;
		}

		$mapped = parent::map_to_odoo( $entity_type, $wp_data );

		if ( 'ticket' === $entity_type ) {
			$mapped['type'] = 'service';
		}

		return $mapped;
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
			'event'    => $this->load_event_data( $wp_id ),
			'ticket'   => $this->handler->load_ticket( $wp_id ),
			'attendee' => $this->load_attendee_data( $wp_id ),
			default    => [],
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
	 * Load and resolve an attendee with Odoo references.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return array<string, mixed>
	 */
	private function load_attendee_data( int $attendee_id ): array {
		$data = $this->handler->load_attendee( $attendee_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve attendee → Odoo partner.
		$email = $data['email'] ?? '';
		$name  = $data['name'] ?? '';

		if ( empty( $email ) ) {
			$this->logger->warning( 'RSVP attendee has no email.', [ 'attendee_id' => $attendee_id ] );
			return [];
		}

		$partner_id = $this->partner_service()->get_or_create(
			$email,
			[ 'name' => $name ?: $email ],
			0
		);

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for attendee.', [ 'attendee_id' => $attendee_id ] );
			return [];
		}

		// Resolve event → Odoo event ID.
		$event_wp_id   = $data['event_id'] ?? 0;
		$event_odoo_id = 0;
		if ( $event_wp_id > 0 ) {
			$event_odoo_id = $this->get_mapping( 'event', $event_wp_id ) ?? 0;
		}

		if ( ! $event_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo event for attendee.', [ 'event_id' => $event_wp_id ] );
			return [];
		}

		return $this->handler->format_attendee( $data, $partner_id, $event_odoo_id );
	}

	// ─── Event auto-sync ───────────────────────────────────

	/**
	 * Ensure the event is synced before pushing an attendee.
	 *
	 * @param int $attendee_id Attendee post ID.
	 * @return void
	 */
	private function ensure_event_synced_for_attendee( int $attendee_id ): void {
		$event_id = $this->handler->get_event_id_for_attendee( $attendee_id );
		$this->ensure_entity_synced( 'event', $event_id );
	}
}
