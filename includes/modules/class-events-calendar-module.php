<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

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
 * Exclusive group: events — mutually exclusive with Modern Events Calendar.
 *
 * @package WP4Odoo
 * @since   2.7.0
 */
class Events_Calendar_Module extends Events_Module_Base {

	use Events_Calendar_Hooks;

	protected const PLUGIN_MIN_VERSION  = '6.0';
	protected const PLUGIN_TESTED_UP_TO = '6.15';

	/**
	 * Exclusive group — only one events module can boot.
	 *
	 * @var string
	 */
	protected string $exclusive_group = 'events';

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
			\add_action( 'save_post_tribe_events', $this->safe_callback( [ $this, 'on_event_save' ] ), 10, 1 );
		}

		// Ticket and attendee hooks only if Event Tickets is active.
		if ( class_exists( 'Tribe__Tickets__Main' ) ) {
			if ( ! empty( $settings['sync_tickets'] ) ) {
				\add_action( 'save_post_tribe_rsvp_tickets', $this->safe_callback( [ $this, 'on_ticket_save' ] ), 10, 1 );
			}

			if ( ! empty( $settings['sync_attendees'] ) ) {
				\add_action( 'event_tickets_rsvp_ticket_created', $this->safe_callback( [ $this, 'on_rsvp_attendee_created' ] ), 10, 4 );
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

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return class_exists( 'Tribe__Events__Main' ) ? \Tribe__Events__Main::VERSION : '';
	}

	// ─── Events_Module_Base abstract implementations ────────

	/**
	 * {@inheritDoc}
	 */
	protected function get_attendance_entity_type(): string {
		return 'attendee';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_load_event( int $wp_id ): array {
		return $this->handler->load_event( $wp_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_load_attendance( int $wp_id ): array {
		return $this->handler->load_attendee( $wp_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_save_event( array $data, int $wp_id ): int {
		return $this->handler->save_event( $data, $wp_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_get_event_id_for_attendance( int $attendance_id ): int {
		return $this->handler->get_event_id_for_attendee( $attendance_id );
	}

	// ─── Ticket-specific overrides ──────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * Adds ticket loading on top of the base event + attendance dispatch.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		if ( 'ticket' === $entity_type ) {
			return $this->handler->load_ticket( $wp_id );
		}

		return parent::load_wp_data( $entity_type, $wp_id );
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Tickets use standard field mapping plus a hardcoded service type.
	 * Events and attendees handled by parent (identity pass-through).
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		$mapped = parent::map_to_odoo( $entity_type, $wp_data );

		if ( 'ticket' === $entity_type ) {
			$mapped['type'] = 'service';
		}

		return $mapped;
	}

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Adds ticket pull setting check on top of base pull logic.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'ticket' === $entity_type && empty( $this->get_settings()['pull_tickets'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * Adds ticket save on top of base event save.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'ticket' === $entity_type ) {
			return $this->handler->save_ticket( $data, $wp_id );
		}

		return parent::save_wp_data( $entity_type, $data, $wp_id );
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Adds ticket delete on top of base event delete.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress post ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'ticket' === $entity_type ) {
			$deleted = \wp_delete_post( $wp_id, true );
			return false !== $deleted && null !== $deleted;
		}

		return parent::delete_wp_data( $entity_type, $wp_id );
	}

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Adds ticket dedup on top of base event + attendee dedup.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'ticket' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return parent::get_dedup_domain( $entity_type, $odoo_values );
	}
}
