<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for booking/appointment modules (Amelia, Bookly).
 *
 * Provides shared sync logic for modules that push booking services
 * as Odoo products (product.product) and appointments/bookings as
 * Odoo calendar events (calendar.event), with customer resolution
 * via Partner_Service.
 *
 * Subclasses provide plugin-specific handler delegation and field
 * extraction via abstract methods. All are one-liners.
 *
 * @package WP4Odoo
 * @since   2.2.0
 */
abstract class Booking_Module_Base extends Module_Base {

	// ─── Subclass configuration ─────────────────────────────

	/**
	 * Get the entity type used for bookings.
	 *
	 * @return string 'appointment' for Amelia, 'booking' for Bookly.
	 */
	abstract protected function get_booking_entity_type(): string;

	/**
	 * Get the fallback label when service name is unavailable.
	 *
	 * @return string Translatable label, e.g. 'Appointment' or 'Booking'.
	 */
	abstract protected function get_fallback_label(): string;

	/**
	 * Resolve a customer display name from customer data.
	 *
	 * Returns the best available name from the plugin's customer record.
	 * Empty string means no customer name is available.
	 *
	 * @param array $customer Customer data from handler_get_customer_data().
	 * @return string Customer display name, or empty string.
	 */
	abstract protected function resolve_customer_name( array $customer ): string;

	// ─── Handler delegation ─────────────────────────────────

	/**
	 * Load a service from the plugin's handler.
	 *
	 * @param int $service_id Plugin-native service ID.
	 * @return array<string, mixed> Service data, or empty if not found.
	 */
	abstract protected function handler_load_service( int $service_id ): array;

	/**
	 * Load a booking/appointment from the plugin's handler.
	 *
	 * The returned array must include 'service_id' and 'customer_id' keys.
	 *
	 * @param int $booking_id Plugin-native booking/appointment ID.
	 * @return array<string, mixed> Booking data, or empty if not found.
	 */
	abstract protected function handler_load_booking( int $booking_id ): array;

	/**
	 * Get customer data from the plugin's handler.
	 *
	 * The returned array must include an 'email' key.
	 *
	 * @param int $customer_id Plugin-native customer ID.
	 * @return array<string, mixed> Customer data, or empty if not found.
	 */
	abstract protected function handler_get_customer_data( int $customer_id ): array;

	/**
	 * Get the service ID associated with a booking.
	 *
	 * @param int $booking_id Plugin-native booking/appointment ID.
	 * @return int Service ID, or 0 if not found.
	 */
	abstract protected function handler_get_service_id( int $booking_id ): int;

	// ─── Data extraction (plugin-specific field names) ──────

	/**
	 * Extract the service display name from loaded service data.
	 *
	 * @param array $service_data Data from handler_load_service().
	 * @return string Service name.
	 */
	abstract protected function get_service_name( array $service_data ): string;

	/**
	 * Extract the booking start datetime from loaded booking data.
	 *
	 * @param array $data Data from handler_load_booking().
	 * @return string Start datetime string.
	 */
	abstract protected function get_booking_start( array $data ): string;

	/**
	 * Extract the booking end datetime from loaded booking data.
	 *
	 * @param array $data Data from handler_load_booking().
	 * @return string End datetime string.
	 */
	abstract protected function get_booking_end( array $data ): string;

	/**
	 * Extract internal notes from loaded booking data.
	 *
	 * @param array $data Data from handler_load_booking().
	 * @return string Notes.
	 */
	abstract protected function get_booking_notes( array $data ): string;

	// ─── Shared sync direction ──────────────────────────────

	/**
	 * Sync direction: push-only (WP → Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For bookings: ensures the associated service is synced first.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       Plugin entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( $this->get_booking_entity_type() === $entity_type && 'delete' !== $action ) {
			$this->ensure_service_synced( $wp_id );
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Bookings bypass standard mapping — the data is pre-formatted
	 * for calendar.event (including partner_ids M2M commands).
	 * Services use standard field mapping plus a hardcoded type.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( $this->get_booking_entity_type() === $entity_type ) {
			return $wp_data;
		}

		$mapped = parent::map_to_odoo( $entity_type, $wp_data );

		if ( 'service' === $entity_type ) {
			$mapped['type'] = 'service';
		}

		return $mapped;
	}

	// ─── Data access ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       Plugin entity ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'service'                        => $this->handler_load_service( $wp_id ),
			$this->get_booking_entity_type() => $this->load_booking_data( $wp_id ),
			default                          => [],
		};
	}

	/**
	 * Load and resolve a booking with Odoo references.
	 *
	 * Reads booking data from plugin tables, resolves the customer
	 * to an Odoo partner, and formats as calendar.event data.
	 *
	 * @param int $booking_id Plugin booking/appointment ID.
	 * @return array<string, mixed>
	 */
	private function load_booking_data( int $booking_id ): array {
		$data = $this->handler_load_booking( $booking_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Load service name for the event title.
		$service_id   = $data['service_id'] ?? 0;
		$service_data = $service_id > 0 ? $this->handler_load_service( $service_id ) : [];
		$service_name = ! empty( $service_data )
			? ( $this->get_service_name( $service_data ) ?: $this->get_fallback_label() )
			: $this->get_fallback_label();

		// Resolve customer → Odoo partner.
		$partner_ids = [];
		$customer_id = $data['customer_id'] ?? 0;
		$customer    = [];
		if ( $customer_id > 0 ) {
			$customer = $this->handler_get_customer_data( $customer_id );
			if ( ! empty( $customer['email'] ) ) {
				$name       = $this->resolve_customer_name( $customer );
				$partner_id = $this->partner_service()->get_or_create(
					$customer['email'],
					[ 'name' => $name ?: $customer['email'] ],
					0
				);
				if ( $partner_id ) {
					$partner_ids = [ [ 4, $partner_id, 0 ] ];
				}
			}
		}

		// Compose event name: "Service — Customer".
		$customer_name = ! empty( $customer ) ? $this->resolve_customer_name( $customer ) : '';

		$event_name = $customer_name
			/* translators: %1$s: service name, %2$s: customer name */
			? sprintf( __( '%1$s — %2$s', 'wp4odoo' ), $service_name, $customer_name )
			: $service_name;

		return [
			'name'        => $event_name,
			'start'       => $this->get_booking_start( $data ),
			'stop'        => $this->get_booking_end( $data ),
			'partner_ids' => $partner_ids,
			'description' => $this->get_booking_notes( $data ),
		];
	}

	/**
	 * Ensure the service is synced to Odoo before pushing a booking.
	 *
	 * @param int $booking_id Plugin booking/appointment ID.
	 * @return void
	 */
	private function ensure_service_synced( int $booking_id ): void {
		$service_id = $this->handler_get_service_id( $booking_id );
		$this->ensure_entity_synced( 'service', $service_id );
	}
}
