<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bookly Module — sync booking services and appointments with Odoo.
 *
 * Syncs Bookly services as Odoo service products (product.product)
 * and customer_appointments as Odoo calendar events (calendar.event),
 * with automatic partner resolution via Partner_Service.
 *
 * Unlike Amelia which has WordPress hooks, Bookly has NO hooks for
 * booking lifecycle events. This module uses WP-Cron polling to detect
 * changes every 5 minutes via hash comparison.
 *
 * Bidirectional: services ↔ Odoo, bookings → Odoo only.
 * No mutual exclusivity with other modules.
 *
 * Requires the Bookly plugin to be active.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class Bookly_Module extends Booking_Module_Base {

	use Bookly_Cron_Hooks;

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
	 * Booking mappings are minimal because map_to_odoo() is overridden
	 * to pass handler-formatted data directly to Odoo (partner_ids M2M commands).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'service' => [
			'title' => 'name',
			'info'  => 'description_sale',
			'price' => 'list_price',
		],
		'booking' => [
			'name'          => 'name',
			'start_date'    => 'start',
			'end_date'      => 'stop',
			'partner_ids'   => 'partner_ids',
			'internal_note' => 'description',
		],
	];

	/**
	 * Bookly data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Bookly_Handler
	 */
	private Bookly_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                      $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'bookly', 'Bookly', $client_provider, $entity_map, $settings );
		$this->handler = new Bookly_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WP-Cron polling.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->register_cron();
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
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_services' => [
				'label'       => __( 'Sync services', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Bookly services to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_bookings' => [
				'label'       => __( 'Sync bookings', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Bookly bookings to Odoo as calendar events.', 'wp4odoo' ),
			],
			'pull_services' => [
				'label'       => __( 'Pull services', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo service products into Bookly services.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for Bookly.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'Bookly\\Lib\\Plugin' ), 'Bookly' );
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
	protected function resolve_customer_name( array $customer ): string {
		if ( ! empty( $customer['full_name'] ) ) {
			return $customer['full_name'];
		}

		return trim( ( $customer['first_name'] ?? '' ) . ' ' . ( $customer['last_name'] ?? '' ) );
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
	protected function handler_load_booking( int $booking_id ): array {
		return $this->handler->load_booking( $booking_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_get_customer_data( int $customer_id ): array {
		return $this->handler->get_customer_data( $customer_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function handler_get_service_id( int $booking_id ): int {
		return $this->handler->get_service_id_for_booking( $booking_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_service_name( array $service_data ): string {
		return $service_data['title'] ?? '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_booking_start( array $data ): string {
		return $data['start_date'] ?? '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_booking_end( array $data ): string {
		return $data['end_date'] ?? '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_booking_notes( array $data ): string {
		return $data['internal_note'] ?? '';
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
