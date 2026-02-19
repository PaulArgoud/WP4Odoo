<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

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
class MEC_Module extends Events_Module_Base {

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

	// ─── Events_Module_Base abstract implementations ────────

	/**
	 * {@inheritDoc}
	 */
	protected function get_attendance_entity_type(): string {
		return 'booking';
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
		return $this->handler->load_booking( $wp_id );
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
		return $this->handler->get_event_id_for_booking( $attendance_id );
	}
}
