<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Amelia Module — push booking services and appointments to Odoo.
 *
 * Syncs Amelia services as Odoo service products (product.product)
 * and appointments as Odoo calendar events (calendar.event), with
 * automatic partner resolution via Partner_Service.
 *
 * Unlike WooCommerce or EDD, Amelia stores data in its own custom
 * tables — the handler queries them directly via $wpdb.
 *
 * Push-only (WP → Odoo). No mutual exclusivity with other modules.
 *
 * Requires the Amelia Booking plugin to be active.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class Amelia_Module extends Booking_Module_Base {

	use Amelia_Hooks;

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
	 * to pass handler-formatted data directly to Odoo (partner_ids M2M commands).
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
			'name'          => 'name',
			'bookingStart'  => 'start',
			'bookingEnd'    => 'stop',
			'partner_ids'   => 'partner_ids',
			'internalNotes' => 'description',
		],
	];

	/**
	 * Amelia data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Amelia_Handler
	 */
	private Amelia_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                      $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'amelia', 'Amelia', $client_provider, $entity_map, $settings );
		$this->handler = new Amelia_Handler( $this->logger );
	}

	/**
	 * Boot the module: register Amelia hooks.
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
				'description' => __( 'Push Amelia services to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_appointments' => [
				'label'       => __( 'Sync appointments', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Amelia appointments to Odoo as calendar events.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for Amelia.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'AMELIA_VERSION' ), 'Amelia Booking' );
	}

	// ─── Booking_Module_Base abstracts ──────────────────────

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
	protected function resolve_customer_name( array $customer ): string {
		return trim( ( $customer['firstName'] ?? '' ) . ' ' . ( $customer['lastName'] ?? '' ) );
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
		return $this->handler->load_appointment( $booking_id );
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
		return $this->handler->get_service_id_for_appointment( $booking_id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_service_name( array $service_data ): string {
		return $service_data['name'] ?? '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_booking_start( array $data ): string {
		return $data['bookingStart'] ?? '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_booking_end( array $data ): string {
		return $data['bookingEnd'] ?? '';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_booking_notes( array $data ): string {
		return $data['internalNotes'] ?? '';
	}
}
