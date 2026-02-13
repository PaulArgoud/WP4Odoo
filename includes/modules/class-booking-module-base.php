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
 * Provides shared sync logic for modules that sync booking services
 * as Odoo products (product.product) and appointments/bookings as
 * Odoo calendar events (calendar.event), with customer resolution
 * via Partner_Service.
 *
 * Bidirectional: services are synced both ways (Odoo ↔ WP),
 * bookings/appointments are push-only (they originate in WordPress).
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

	// ─── Handler delegation ─────────────────────────────────

	/**
	 * Load a service from the plugin's handler.
	 *
	 * @param int $service_id Plugin-native service ID.
	 * @return array<string, mixed> Service data, or empty if not found.
	 */
	abstract protected function handler_load_service( int $service_id ): array;

	/**
	 * Load and extract booking fields from the plugin's handler.
	 *
	 * The returned array must include:
	 * - 'service_id'     (int)    Plugin-native service ID.
	 * - 'customer_email' (string) Customer email address.
	 * - 'customer_name'  (string) Customer display name.
	 * - 'service_name'   (string) Service display name.
	 * - 'start'          (string) Booking start datetime.
	 * - 'stop'           (string) Booking end datetime.
	 * - 'description'    (string) Internal notes.
	 *
	 * @param int $booking_id Plugin-native booking/appointment ID.
	 * @return array<string, mixed> Extracted booking fields, or empty if not found.
	 */
	abstract protected function handler_extract_booking_fields( int $booking_id ): array;

	/**
	 * Get the service ID associated with a booking.
	 *
	 * @param int $booking_id Plugin-native booking/appointment ID.
	 * @return int Service ID, or 0 if not found.
	 */
	abstract protected function handler_get_service_id( int $booking_id ): int;

	// ─── Handler delegation: pull ───────────────────────────

	/**
	 * Parse Odoo product data into plugin-native service format.
	 *
	 * Reverse of handler_load_service() + map_to_odoo(). Subclasses
	 * delegate to their handler's parse method.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> Plugin-native service data.
	 */
	abstract protected function handler_parse_service_from_odoo( array $odoo_data ): array;

	/**
	 * Save a service pulled from Odoo to the plugin's data store.
	 *
	 * @param array<string, mixed> $data  Parsed service data.
	 * @param int                  $wp_id Existing service ID (0 to create new).
	 * @return int The service ID, or 0 on failure.
	 */
	abstract protected function handler_save_service( array $data, int $wp_id ): int;

	/**
	 * Delete a service from the plugin's data store.
	 *
	 * @param int $service_id Plugin-native service ID.
	 * @return bool True on success.
	 */
	abstract protected function handler_delete_service( int $service_id ): bool;

	// ─── Shared sync direction ──────────────────────────────

	/**
	 * Sync direction: bidirectional (services ↔, bookings →).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
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

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Only services are pulled — bookings/appointments originate in WordPress.
	 * Gated on the pull_services setting.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( $this->get_booking_entity_type() === $entity_type ) {
			$this->logger->info( "{$entity_type} pull not supported — {$entity_type}s originate in WordPress.", [ 'odoo_id' => $odoo_id ] );
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'service' === $entity_type ) {
			$settings = $this->get_settings();
			if ( empty( $settings['pull_services'] ) ) {
				return \WP4Odoo\Sync_Result::success();
			}
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * Services delegate to the handler's parse method.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'service' === $entity_type ) {
			return $this->handler_parse_service_from_odoo( $odoo_data );
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
		if ( 'service' === $entity_type ) {
			return $this->handler_save_service( $data, $wp_id );
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'service' === $entity_type ) {
			return $this->handler_delete_service( $wp_id );
		}

		return false;
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
	 * Delegates field extraction to handler_extract_booking_fields(),
	 * resolves the customer to an Odoo partner, and formats as
	 * calendar.event data.
	 *
	 * @param int $booking_id Plugin booking/appointment ID.
	 * @return array<string, mixed>
	 */
	private function load_booking_data( int $booking_id ): array {
		$fields = $this->handler_extract_booking_fields( $booking_id );
		if ( empty( $fields ) ) {
			return [];
		}

		$service_name   = $fields['service_name'] ?: $this->get_fallback_label();
		$customer_name  = $fields['customer_name'] ?? '';
		$customer_email = $fields['customer_email'] ?? '';

		// Resolve customer → Odoo partner.
		$partner_ids = [];
		if ( ! empty( $customer_email ) ) {
			$partner_id = $this->resolve_partner_from_email( $customer_email, $customer_name ?: $customer_email );
			if ( $partner_id ) {
				$partner_ids = [ [ 4, $partner_id, 0 ] ];
			}
		}

		// Compose event name: "Service — Customer".
		$event_name = $customer_name
			/* translators: %1$s: service name, %2$s: customer name */
			? sprintf( __( '%1$s — %2$s', 'wp4odoo' ), $service_name, $customer_name )
			: $service_name;

		return [
			'name'        => $event_name,
			'start'       => $fields['start'] ?? '',
			'stop'        => $fields['stop'] ?? '',
			'partner_ids' => $partner_ids,
			'description' => $fields['description'] ?? '',
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
