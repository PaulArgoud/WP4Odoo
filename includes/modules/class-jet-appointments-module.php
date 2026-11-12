<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetAppointments Module — sync booking services and appointments with Odoo.
 *
 * Syncs JetAppointments services as Odoo service products (product.product)
 * and appointments as Odoo calendar events (calendar.event), with automatic
 * partner resolution via Partner_Service.
 *
 * JetAppointments stores appointments as CPTs (`jet-appointment`) with meta
 * fields. Services are a configurable CPT (typically `jet-service`).
 *
 * Bidirectional: services ↔ Odoo, appointments → Odoo only.
 * No mutual exclusivity with other modules.
 *
 * Requires JetAppointments by Crocoblock to be active.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class Jet_Appointments_Module extends Booking_Module_Base {

	use Jet_Appointments_Hooks;

	protected const PLUGIN_MIN_VERSION  = '1.0';
	protected const PLUGIN_TESTED_UP_TO = '2.3';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'service'     => 'product.product',
		'appointment' => 'calendar.event',
	];

	/**
	 * Default field mappings.
	 *
	 * Appointment mappings are minimal because map_to_odoo() is overridden
	 * by Booking_Module_Base to pass handler-formatted data directly.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'service'     => [
			'name'        => 'name',
			'description' => 'description_sale',
			'price'       => 'list_price',
		],
		'appointment' => [
			'name'        => 'name',
			'start'       => 'start',
			'stop'        => 'stop',
			'partner_ids' => 'partner_ids',
			'description' => 'description',
		],
	];

	/**
	 * JetAppointments data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Jet_Appointments_Handler
	 */
	private Jet_Appointments_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'jet_appointments', 'JetAppointments', $client_provider, $entity_map, $settings );
		$this->handler = new Jet_Appointments_Handler( $this->logger );
	}

	/**
	 * Boot the module: register JetAppointments hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->register_hooks();
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_services'     => true,
			'sync_appointments' => true,
			'pull_services'     => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_services'     => [
				'label'       => __( 'Sync services', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push JetAppointments services to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_appointments' => [
				'label'       => __( 'Sync appointments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push JetAppointments appointments to Odoo as calendar events.', 'wp4odoo' ),
			],
			'pull_services'     => [
				'label'       => __( 'Pull services', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo service products into JetAppointments services.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Whether the third-party plugin(s) this module integrates with are active.
	 *
	 * Single source of truth for module activation, shared by the registry
	 * and the dependency check.
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		return defined( 'JET_APB_VERSION' ) || class_exists( 'JET_APB\Plugin' );
	}

	/**
	 * Get external dependency status for JetAppointments.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency(
			static::detect(),
			'JetAppointments'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'JET_APB_VERSION' ) ? (string) JET_APB_VERSION : '';
	}

	// ─── Booking_Module_Base abstracts ─────────────────────

	/**
	 * {@inheritDoc}
	 */
	protected function get_booking_entity_type(): string {
		return 'appointment';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_fallback_label(): string {
		return __( 'Appointment', 'wp4odoo' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_load_service( int $service_id ): array {
		return $this->handler->load_service( $service_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_extract_booking_fields( int $booking_id ): array {
		return $this->handler->extract_booking_fields( $booking_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_get_service_id( int $booking_id ): int {
		return $this->handler->get_service_id( $booking_id );
	}

	// ─── Pull: handler delegation ──────────────────────────

	/**
	 * {@inheritDoc}
	 */
	protected function handler_parse_service_from_odoo( array $odoo_data ): array {
		return $this->handler->parse_service_from_odoo( $odoo_data );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_save_service( array $data, int $wp_id ): int {
		return $this->handler->save_service( $data, $wp_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_delete_service( int $service_id ): bool {
		return $this->handler->delete_service( $service_id );
	}
}
