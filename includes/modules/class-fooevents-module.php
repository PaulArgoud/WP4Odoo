<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

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
class FooEvents_Module extends Events_Module_Base {

	use FooEvents_Hooks;

	protected const PLUGIN_MIN_VERSION  = '1.18';
	protected const PLUGIN_TESTED_UP_TO = '2.0';

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
}
