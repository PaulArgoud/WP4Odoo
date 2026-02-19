<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentBooking Module — sync booking services and appointments with Odoo.
 *
 * Syncs FluentBooking calendars as Odoo service products (product.product)
 * and bookings as Odoo calendar events (calendar.event), with automatic
 * partner resolution via Partner_Service.
 *
 * FluentBooking stores data in its own custom tables — the handler queries
 * them directly via $wpdb (same pattern as Amelia).
 *
 * Part of the Fluent ecosystem (FluentCRM, Fluent Forms, Fluent Support).
 *
 * Bidirectional: services ↔ Odoo, bookings → Odoo only.
 *
 * @package WP4Odoo
 * @since   3.8.0
 */
class Fluent_Booking_Module extends Booking_Module_Base {

	use Fluent_Booking_Hooks;

	protected const PLUGIN_MIN_VERSION  = '1.0';
	protected const PLUGIN_TESTED_UP_TO = '1.5';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'service' => 'product.product',
		'booking' => 'calendar.event',
	];

	/**
	 * Default field mappings.
	 *
	 * Booking mappings are minimal because Booking_Module_Base overrides
	 * map_to_odoo() to pass handler-formatted data directly to Odoo.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'service' => [
			'name'        => 'name',
			'description' => 'description_sale',
			'price'       => 'list_price',
		],
		'booking' => [
			'name'        => 'name',
			'start'       => 'start',
			'stop'        => 'stop',
			'partner_ids' => 'partner_ids',
			'description' => 'description',
		],
	];

	/**
	 * FluentBooking data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Fluent_Booking_Handler
	 */
	private Fluent_Booking_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'fluent_booking', 'FluentBooking', $client_provider, $entity_map, $settings );
		$this->handler = new Fluent_Booking_Handler( $this->logger );
	}

	/**
	 * Boot the module: register FluentBooking hooks.
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
			'sync_services' => true,
			'sync_bookings' => true,
			'pull_services' => true,
		];
	}

	/**
	 * Third-party tables accessed directly via $wpdb.
	 *
	 * @return array<int, string>
	 */
	protected function get_required_tables(): array {
		return [
			'fluentbooking_calendars',
			'fluentbooking_bookings',
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_services' => [
				'label'       => __( 'Sync calendars', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push FluentBooking calendars to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_bookings' => [
				'label'       => __( 'Sync bookings', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push FluentBooking bookings to Odoo as calendar events.', 'wp4odoo' ),
			],
			'pull_services' => [
				'label'       => __( 'Pull calendars', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo service products into FluentBooking calendars.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'FLUENT_BOOKING_VERSION' ), 'FluentBooking' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'FLUENT_BOOKING_VERSION' ) ? FLUENT_BOOKING_VERSION : '';
	}

	// ─── Booking_Module_Base abstracts ──────────────────────

	/**
	 * {@inheritDoc}
	 */
	protected function get_booking_entity_type(): string {
		return 'booking';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_fallback_label(): string {
		return __( 'Booking', 'wp4odoo' );
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
		$data = $this->handler->load_booking( $booking_id );
		if ( empty( $data ) ) {
			return [];
		}

		$calendar_id   = (int) ( $data['calendar_id'] ?? 0 );
		$calendar_data = $calendar_id > 0 ? $this->handler->load_service( $calendar_id ) : [];
		$service_name  = $calendar_data['name'] ?? '';

		$customer_name = trim( ( $data['first_name'] ?? '' ) . ' ' . ( $data['last_name'] ?? '' ) );

		return [
			'service_id'     => $calendar_id,
			'customer_email' => $data['email'] ?? '',
			'customer_name'  => $customer_name,
			'service_name'   => $service_name,
			'start'          => $data['start_time'] ?? '',
			'stop'           => $data['end_time'] ?? '',
			'description'    => $data['description'] ?? '',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_get_service_id( int $booking_id ): int {
		return $this->handler->get_service_id_for_booking( $booking_id );
	}

	// ─── Pull: handler delegation ───────────────────────────

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
