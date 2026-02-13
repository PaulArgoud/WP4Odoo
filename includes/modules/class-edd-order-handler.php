<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;
use WP4Odoo\Partner_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD Order Handler — order data access and status mapping.
 *
 * Centralises load, save, and status-mapping operations for EDD orders.
 * Called by EDD_Module via its load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
class EDD_Order_Handler {

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
	 * Load EDD order data.
	 *
	 * @param int $order_id EDD order ID.
	 * @return array
	 */
	public function load( int $order_id ): array {
		$order = edd_get_order( $order_id );
		if ( ! $order ) {
			return [];
		}

		// Resolve partner_id via Partner_Service.
		$partner_id = null;
		if ( '' !== $order->email ) {
			$partner_id = $this->partner_service->get_or_create(
				$order->email,
				[ 'name' => $order->email ]
			);
		}

		return [
			'total'        => (string) $order->total,
			'date_created' => $order->date_created,
			'status'       => $this->map_edd_status_to_odoo( $order->status ),
			'partner_id'   => $partner_id,
		];
	}

	// ─── Save ────────────────────────────────────────────────

	/**
	 * Save order data from Odoo.
	 *
	 * Primarily used for status updates. Order creation from Odoo
	 * is not supported.
	 *
	 * @param array $data  Mapped order data.
	 * @param int   $wp_id Existing order ID (0 to skip).
	 * @return int Order ID or 0 on failure.
	 */
	public function save( array $data, int $wp_id = 0 ): int {
		if ( 0 === $wp_id ) {
			$this->logger->warning( 'Order creation from Odoo is not supported. Use Easy Digital Downloads to create orders.' );
			return 0;
		}

		$order = edd_get_order( $wp_id );
		if ( ! $order ) {
			$this->logger->error( 'EDD order not found.', [ 'order_id' => $wp_id ] );
			return 0;
		}

		if ( isset( $data['status'] ) ) {
			$edd_status = $this->map_odoo_status_to_edd( $data['status'] );
			edd_update_order_status( $wp_id, $edd_status );
		}

		return $wp_id;
	}

	// ─── Status Mapping ─────────────────────────────────────

	/**
	 * EDD → Odoo order status mapping.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'pending'   => 'draft',
		'complete'  => 'sale',
		'failed'    => 'cancel',
		'refunded'  => 'cancel',
		'abandoned' => 'cancel',
		'revoked'   => 'cancel',
	];

	/**
	 * Odoo → EDD order status mapping.
	 *
	 * @var array<string, string>
	 */
	private const REVERSE_STATUS_MAP = [
		'draft'  => 'pending',
		'sent'   => 'pending',
		'sale'   => 'complete',
		'done'   => 'complete',
		'cancel' => 'failed',
	];

	/**
	 * Map an EDD order status to an Odoo sale.order state.
	 *
	 * @param string $edd_status EDD status value.
	 * @return string Odoo state.
	 */
	public function map_edd_status_to_odoo( string $edd_status ): string {
		return Status_Mapper::resolve( $edd_status, self::STATUS_MAP, 'wp4odoo_edd_order_status_map', 'draft' );
	}

	/**
	 * Map an Odoo sale.order state to an EDD order status.
	 *
	 * @param string $odoo_state Odoo state value.
	 * @return string EDD status.
	 */
	public function map_odoo_status_to_edd( string $odoo_state ): string {
		return Status_Mapper::resolve( $odoo_state, self::REVERSE_STATUS_MAP, 'wp4odoo_edd_odoo_status_map', 'pending' );
	}
}
