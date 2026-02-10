<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;
use WP4Odoo\Partner_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order Handler — WooCommerce order data access.
 *
 * Centralises load, save and status-mapping operations for WC orders.
 * Called by WooCommerce_Module via its load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   1.8.0
 */
class Order_Handler {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Partner service for customer ↔ res.partner resolution.
	 *
	 * @var Partner_Service
	 */
	private Partner_Service $partner_service;

	/**
	 * Constructor.
	 *
	 * @param Logger          $logger          Logger instance.
	 * @param Partner_Service $partner_service Partner service.
	 */
	public function __construct( Logger $logger, Partner_Service $partner_service ) {
		$this->logger          = $logger;
		$this->partner_service = $partner_service;
	}

	// ─── Load ────────────────────────────────────────────────

	/**
	 * Load WooCommerce order data.
	 *
	 * @param int $wp_id Order ID.
	 * @return array
	 */
	public function load( int $wp_id ): array {
		$order = wc_get_order( $wp_id );
		if ( ! $order ) {
			return [];
		}

		// Resolve partner_id via Partner_Service.
		$partner_id = null;
		$email      = $order->get_billing_email();
		if ( $email ) {
			$user_id    = $order->get_customer_id();
			$partner_id = $this->partner_service->get_or_create(
				$email,
				[ 'name' => $order->get_formatted_billing_full_name() ],
				$user_id
			);
		}

		return [
			'total'        => $order->get_total(),
			'date_created' => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : '',
			'status'       => $order->get_status(),
			'partner_id'   => $partner_id,
		];
	}

	// ─── Save ────────────────────────────────────────────────

	/**
	 * Save order data to WooCommerce.
	 *
	 * Primarily used for status updates from Odoo.
	 *
	 * @param array $data  Mapped order data.
	 * @param int   $wp_id Existing order ID (0 to skip — order creation from Odoo is not supported).
	 * @return int Order ID or 0 on failure.
	 */
	public function save( array $data, int $wp_id = 0 ): int {
		if ( 0 === $wp_id ) {
			$this->logger->warning( 'Order creation from Odoo is not supported. Use WooCommerce to create orders.' );
			return 0;
		}

		$order = wc_get_order( $wp_id );
		if ( ! $order ) {
			$this->logger->error( 'WC order not found.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		if ( isset( $data['status'] ) ) {
			$order->set_status( $this->map_odoo_status_to_wc( $data['status'] ) );
		}

		$order->save();

		return $wp_id;
	}

	// ─── Status Mapping ─────────────────────────────────────

	/**
	 * Map an Odoo sale.order state to a WooCommerce order status.
	 *
	 * @param string $odoo_state Odoo state value.
	 * @return string WC status (without 'wc-' prefix).
	 */
	public function map_odoo_status_to_wc( string $odoo_state ): string {
		$default_map = [
			'draft'  => 'pending',
			'sent'   => 'on-hold',
			'sale'   => 'processing',
			'done'   => 'completed',
			'cancel' => 'cancelled',
		];

		/**
		 * Filters the Odoo → WooCommerce order status mapping.
		 *
		 * @since 1.9.1
		 *
		 * @param array<string, string> $map Odoo state => WC status (without wc- prefix).
		 */
		$map = apply_filters( 'wp4odoo_order_status_map', $default_map );

		return $map[ $odoo_state ] ?? 'on-hold';
	}
}
