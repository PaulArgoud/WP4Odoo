<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\CPT_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bookings Handler — data access via WC CRUD classes.
 *
 * WC Bookings stores bookable products as WC products (type 'booking')
 * and individual bookings as the `wc_booking` CPT, accessed via the
 * WC_Booking CRUD class.
 *
 * Called by WC_Bookings_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
class WC_Bookings_Handler extends Booking_Handler_Base {

	// ─── Push: products (services) ──────────────────────────

	/**
	 * Load a WC Booking product by ID.
	 *
	 * @param int $product_id WC product ID.
	 * @return array<string, mixed> Product data, or empty if not found.
	 */
	public function load_product( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$this->logger->warning( 'WC Booking product not found.', [ 'product_id' => $product_id ] );
			return [];
		}

		return [
			'name'        => $product->get_name(),
			'description' => $product->get_description(),
			'price'       => (float) $product->get_regular_price(),
		];
	}

	// ─── Push: bookings ─────────────────────────────────────

	/**
	 * Extract booking fields for Booking_Module_Base.
	 *
	 * Returns the 7 fields expected by handler_extract_booking_fields():
	 * service_id, customer_email, customer_name, service_name, start, stop, description.
	 *
	 * @param int $booking_id WC Booking ID.
	 * @return array<string, mixed> Extracted fields, or empty if not found.
	 */
	public function extract_booking_fields( int $booking_id ): array {
		$booking = new \WC_Booking( $booking_id );
		if ( ! $booking->get_id() ) {
			$this->logger->warning( 'WC Booking not found.', [ 'booking_id' => $booking_id ] );
			return [];
		}

		$product_id = $booking->get_product_id();
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;

		$customer_email = '';
		$customer_name  = '';

		// Try WP user first.
		$customer_id = $booking->get_customer_id();
		if ( $customer_id > 0 ) {
			$user = get_userdata( $customer_id );
			if ( $user ) {
				$customer_email = $user->user_email;
				$customer_name  = trim( $user->first_name . ' ' . $user->last_name );
				if ( '' === $customer_name ) {
					$customer_name = $user->display_name;
				}
			}
		}

		// Fallback: WC order billing info.
		if ( '' === $customer_email ) {
			$order_id = $booking->get_order_id();
			if ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$customer_email = $order->get_billing_email();
					$customer_name  = $order->get_formatted_billing_full_name();
				}
			}
		}

		// Build description with persons count.
		$description   = '';
		$persons_total = $booking->get_persons_total();
		if ( $persons_total > 0 ) {
			/* translators: %d: number of persons */
			$description = sprintf( __( 'Persons: %d', 'wp4odoo' ), $persons_total );
		}

		return [
			'service_id'     => $product_id,
			'customer_email' => $customer_email,
			'customer_name'  => $customer_name,
			'service_name'   => $product ? $product->get_name() : '',
			'start'          => $booking->get_start_date( 'Y-m-d H:i:s' ),
			'stop'           => $booking->get_end_date( 'Y-m-d H:i:s' ),
			'description'    => $description,
		];
	}

	/**
	 * Get the product ID for a booking.
	 *
	 * Used by ensure_service_synced() to push the product before the booking.
	 *
	 * @param int $booking_id WC Booking ID.
	 * @return int Product ID, or 0 if not found.
	 */
	public function get_product_id_for_booking( int $booking_id ): int {
		$booking = new \WC_Booking( $booking_id );
		return $booking->get_product_id();
	}

	/**
	 * Check if a booking status is syncable.
	 *
	 * Only confirmed, paid, and complete bookings are pushed to Odoo.
	 *
	 * @param string $status Booking status.
	 * @return bool
	 */
	public function is_booking_syncable( string $status ): bool {
		return in_array( $status, [ 'confirmed', 'paid', 'complete' ], true );
	}

	/**
	 * Check if a booking is all-day.
	 *
	 * @param int $booking_id WC Booking ID.
	 * @return bool
	 */
	public function is_all_day( int $booking_id ): bool {
		$booking = new \WC_Booking( $booking_id );
		return $booking->is_all_day();
	}

	// ─── Pull: parse from Odoo ──────────────────────────────

	/**
	 * Parse Odoo product data into WC product format.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> Product data.
	 */
	public function parse_product_from_odoo( array $odoo_data ): array {
		return CPT_Helper::parse_service_product( $odoo_data );
	}

	// ─── Pull: save product ─────────────────────────────────

	/**
	 * Save a product pulled from Odoo as a WC Booking product.
	 *
	 * Creates or updates a WC product with the 'booking' product type.
	 *
	 * @param array<string, mixed> $data  Parsed product data.
	 * @param int                  $wp_id Existing product ID (0 to create new).
	 * @return int The product ID, or 0 on failure.
	 */
	public function save_product( array $data, int $wp_id = 0 ): int {
		$product_id = CPT_Helper::save_from_odoo( 'product', $data, $wp_id, $this->logger );

		if ( $product_id > 0 ) {
			wp_set_object_terms( $product_id, 'booking', 'product_type' );

			if ( isset( $data['list_price'] ) ) {
				update_post_meta( $product_id, '_regular_price', (string) $data['list_price'] );
				update_post_meta( $product_id, '_price', (string) $data['list_price'] );
			}
		}

		return $product_id;
	}

	// ─── Pull: delete product ───────────────────────────────

	/**
	 * Delete a WC Booking product.
	 *
	 * @param int $product_id WC product ID.
	 * @return bool True on success.
	 */
	public function delete_product( int $product_id ): bool {
		return false !== wp_delete_post( $product_id, true );
	}
}
