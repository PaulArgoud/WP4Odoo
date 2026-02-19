<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Rental data handler — loads and formats WooCommerce rental orders.
 *
 * Detects rental products in WC orders via a configurable meta key,
 * extracts rental dates from order item meta, and formats the data
 * as Odoo sale.order records with Odoo Rental fields.
 *
 * @package WP4Odoo
 * @since   3.7.0
 */
class WC_Rental_Handler {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Check if a WC order contains at least one rental product.
	 *
	 * @param int    $order_id       WC order ID.
	 * @param string $rental_meta_key Meta key identifying rental products.
	 * @return bool
	 */
	public function order_has_rental_items( int $order_id, string $rental_meta_key ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$is_rental  = get_post_meta( $product_id, $rental_meta_key, true );
			if ( ! empty( $is_rental ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load and format a WC order as an Odoo Rental sale.order.
	 *
	 * Only includes order lines for rental products. Non-rental items
	 * in the same order are skipped (those are handled by the main
	 * WooCommerce module).
	 *
	 * @param int      $order_id         WC order ID.
	 * @param string   $rental_meta_key  Product meta key for rental identification.
	 * @param string   $start_meta_key   Order item meta key for start date.
	 * @param string   $return_meta_key  Order item meta key for return date.
	 * @param callable $product_resolver Resolves WP product ID → Odoo product ID.
	 * @param callable $partner_resolver Resolves WP user ID → Odoo partner ID.
	 * @return array<string, mixed> Odoo sale.order data, or empty on failure.
	 */
	public function load_rental_order(
		int $order_id,
		string $rental_meta_key,
		string $start_meta_key,
		string $return_meta_key,
		callable $product_resolver,
		callable $partner_resolver
	): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->logger->warning( 'WC order not found for rental sync.', [ 'order_id' => $order_id ] );
			return [];
		}

		$user_id    = $order->get_customer_id();
		$partner_id = $partner_resolver( $user_id );

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for rental order.', [ 'order_id' => $order_id ] );
			return [];
		}

		$lines       = [];
		$pickup_date = '';
		$return_date = '';

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$is_rental  = get_post_meta( $product_id, $rental_meta_key, true );

			if ( empty( $is_rental ) ) {
				continue;
			}

			$odoo_product_id = $product_resolver( $product_id );
			if ( ! $odoo_product_id ) {
				continue;
			}

			$qty   = $item->get_quantity();
			$total = (float) $item->get_total();
			$price = $qty > 0 ? $total / $qty : $total;

			$lines[] = [
				0,
				0,
				[
					'product_id'      => $odoo_product_id,
					'product_uom_qty' => $qty,
					'price_unit'      => $price,
					'name'            => $item->get_name(),
				],
			];

			// Rental dates from item meta (use first rental item's dates).
			if ( '' === $pickup_date ) {
				$pickup_date = (string) ( $item->get_meta( $start_meta_key ) ?: '' );
			}
			if ( '' === $return_date ) {
				$return_date = (string) ( $item->get_meta( $return_meta_key ) ?: '' );
			}
		}

		if ( empty( $lines ) ) {
			$this->logger->debug( 'No rental lines found in order.', [ 'order_id' => $order_id ] );
			return [];
		}

		$date = $order->get_date_created();

		return [
			'partner_id'       => $partner_id,
			'date_order'       => $date ? $date->format( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'state'            => 'sale',
			'order_line'       => $lines,
			'is_rental'        => true,
			'pickup_date'      => $pickup_date,
			'return_date'      => $return_date,
			'client_order_ref' => sprintf( 'WC-RENTAL-%d', $order_id ),
		];
	}
}
