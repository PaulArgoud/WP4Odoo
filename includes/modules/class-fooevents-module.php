<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Odoo_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FooEvents Module — bidirectional sync for WC product events,
 * push-only for attendees/tickets.
 *
 * FooEvents turns WooCommerce products into events with ticketing.
 * This module syncs event products as Odoo events (event.event) or
 * calendar entries (calendar.event fallback), and ticket holders as
 * event registrations (event.registration).
 *
 * Events are bidirectional (push + pull). Attendees are push-only
 * (they originate from WooCommerce order completion).
 *
 * Dual-model: probes Odoo for event.event model at runtime.
 * If available, events and attendees use the rich event module.
 * If not, events fall back to calendar.event and attendees are skipped.
 *
 * Requires WooCommerce module to be active (FooEvents extends WC products).
 *
 * @package WP4Odoo
 * @since   3.8.0
 */
class FooEvents_Module extends Module_Base {

	use FooEvents_Hooks;

	protected const PLUGIN_MIN_VERSION  = '1.18';
	protected const PLUGIN_TESTED_UP_TO = '2.0';

	/**
	 * Sync direction: bidirectional for events, push-only for attendees.
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
		'attendee' => 'event.registration',
	];

	/**
	 * Default field mappings.
	 *
	 * Event and attendee mappings are identity (pre-formatted by handler).
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
		'attendee' => [
			'event_id'   => 'event_id',
			'partner_id' => 'partner_id',
			'name'       => 'name',
			'email'      => 'email',
		],
	];

	/**
	 * FooEvents data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var FooEvents_Handler
	 */
	private FooEvents_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'fooevents', 'FooEvents', $client_provider, $entity_map, $settings );
		$this->handler = new FooEvents_Handler( $this->logger );
	}

	/**
	 * Required modules — FooEvents needs WooCommerce.
	 *
	 * @return string[]
	 */
	public function get_required_modules(): array {
		return [ 'woocommerce' ];
	}

	/**
	 * Boot the module: register FooEvents hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'FooEvents' ) && ! defined( 'FOOEVENTS_VERSION' ) ) {
			$this->logger->warning( \__( 'FooEvents module enabled but FooEvents for WooCommerce is not active.', 'wp4odoo' ) );
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
			'sync_events'    => true,
			'sync_attendees' => true,
			'pull_events'    => false,
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
				'description' => \__( 'Push FooEvents products to Odoo (event.event or calendar.event).', 'wp4odoo' ),
			],
			'sync_attendees' => [
				'label'       => \__( 'Sync attendees', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => \__( 'Push ticket holders to Odoo as event registrations. Requires Odoo Events module.', 'wp4odoo' ),
			],
			'pull_events'    => [
				'label'       => \__( 'Pull events from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => \__( 'Pull event changes from Odoo back to WordPress. Products are primarily managed by WooCommerce module.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for FooEvents.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		$available = class_exists( 'FooEvents' ) || defined( 'FOOEVENTS_VERSION' );
		return $this->check_dependency( $available, 'FooEvents for WooCommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'FOOEVENTS_VERSION' ) ? (string) FOOEVENTS_VERSION : '';
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Events dedup by name. Attendees dedup by email on event.registration.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'event' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		if ( 'attendee' === $entity_type && ! empty( $odoo_values['email'] ) && ! empty( $odoo_values['event_id'] ) ) {
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
	 * Attendees are push-only and cannot be pulled. Events delegate to
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
		if ( 'attendee' === $entity_type ) {
			$this->logger->info( 'Attendee pull not supported — attendees originate from WooCommerce orders.', [ 'odoo_id' => $odoo_id ] );
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
	 * For attendees: skip if event.event not available, ensure event synced.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'attendee' === $entity_type && 'delete' !== $action ) {
			if ( ! $this->has_event_model() ) {
				$this->logger->info( 'event.event not available — skipping attendee push.', [ 'attendee_id' => $wp_id ] );
				return \WP4Odoo\Sync_Result::success();
			}
			$this->ensure_event_synced_for_attendee( $wp_id );
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Events and attendees bypass standard mapping — the data is pre-formatted
	 * by the handler.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'event' === $entity_type || 'attendee' === $entity_type ) {
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
			'event'    => $this->load_event_data( $wp_id ),
			'attendee' => $this->load_attendee_data( $wp_id ),
			default    => [],
		};
	}

	/**
	 * Load and format an event for the target Odoo model.
	 *
	 * @param int $product_id WC product ID.
	 * @return array<string, mixed>
	 */
	private function load_event_data( int $product_id ): array {
		$data = $this->handler->load_event( $product_id );
		if ( empty( $data ) ) {
			return [];
		}

		return $this->handler->format_event( $data, $this->has_event_model() );
	}

	/**
	 * Load and resolve an attendee with Odoo references.
	 *
	 * @param int $ticket_id FooEvents ticket post ID.
	 * @return array<string, mixed>
	 */
	private function load_attendee_data( int $ticket_id ): array {
		$data = $this->handler->load_attendee( $ticket_id );
		if ( empty( $data ) ) {
			return [];
		}

		$email = $data['email'] ?? '';
		$name  = $data['name'] ?? '';

		if ( empty( $email ) ) {
			$this->logger->warning( 'FooEvents attendee has no email.', [ 'ticket_id' => $ticket_id ] );
			return [];
		}

		$partner_id = $this->resolve_partner_from_email( $email, $name ?: $email );

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for FooEvents attendee.', [ 'ticket_id' => $ticket_id ] );
			return [];
		}

		$event_wp_id   = $data['event_id'] ?? 0;
		$event_odoo_id = 0;
		if ( $event_wp_id > 0 ) {
			$event_odoo_id = $this->get_mapping( 'event', $event_wp_id ) ?? 0;
		}

		if ( ! $event_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo event for FooEvents attendee.', [ 'event_id' => $event_wp_id ] );
			return [];
		}

		return $this->handler->format_attendee( $data, $partner_id, $event_odoo_id );
	}

	// ─── Event auto-sync ───────────────────────────────────

	/**
	 * Ensure the event is synced before pushing an attendee.
	 *
	 * @param int $ticket_id FooEvents ticket post ID.
	 * @return void
	 */
	private function ensure_event_synced_for_attendee( int $ticket_id ): void {
		$event_id = $this->handler->get_event_id_for_attendee( $ticket_id );
		$this->ensure_entity_synced( 'event', $event_id );
	}
}
