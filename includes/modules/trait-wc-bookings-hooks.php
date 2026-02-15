<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bookings hook callbacks for push operations.
 *
 * Extracted from WC_Bookings_Module for single responsibility.
 * Handles booking product saves (filtered by 'booking' product type)
 * and booking status changes.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 * - get_handler(): WC_Bookings_Handler
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
trait WC_Bookings_Hooks {

	/**
	 * Register WC Bookings hooks.
	 *
	 * Called by boot().
	 *
	 * @return void
	 */
	protected function register_hooks(): void {
		if ( ! class_exists( 'WC_Product_Booking' ) ) {
			$this->logger->warning( __( 'WC Bookings module enabled but WooCommerce Bookings is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_products'] ) ) {
			add_action( 'save_post_product', $this->safe_callback( [ $this, 'on_product_save' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_bookings'] ) ) {
			add_action( 'woocommerce_booking_status_changed', $this->safe_callback( [ $this, 'on_booking_status_changed' ] ), 10, 3 );
		}
	}

	/**
	 * Handle WC Booking product save.
	 *
	 * Only processes products with the 'booking' product type.
	 * Same pattern as WC_Subscriptions_Hooks::on_product_save().
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_product_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		if ( 'booking' !== $product->get_type() ) {
			return;
		}

		$this->push_entity( 'wc_bookings', 'product', 'sync_products', $post_id );
	}

	/**
	 * Handle WC Booking status change.
	 *
	 * Syncs bookings when they transition to a syncable status
	 * (confirmed, paid, complete). Enqueues a delete when cancelled.
	 *
	 * @param string $from       Previous status.
	 * @param string $to         New status.
	 * @param int    $booking_id WC Booking ID.
	 * @return void
	 */
	public function on_booking_status_changed( string $from, string $to, int $booking_id ): void {
		if ( ! $this->should_sync( 'sync_bookings' ) ) {
			return;
		}

		// Syncable statuses: confirmed, paid, complete → create/update.
		if ( $this->get_handler()->is_booking_syncable( $to ) ) {
			$odoo_id = $this->get_mapping( 'booking', $booking_id ) ?? 0;
			$action  = $odoo_id ? 'update' : 'create';

			Queue_Manager::push( 'wc_bookings', 'booking', $action, $booking_id, $odoo_id );
			return;
		}

		// Cancelled → delete if mapping exists.
		if ( 'cancelled' === $to ) {
			$odoo_id = $this->get_mapping( 'booking', $booking_id );
			if ( $odoo_id ) {
				Queue_Manager::push( 'wc_bookings', 'booking', 'delete', $booking_id, $odoo_id );
			}
		}
	}
}
