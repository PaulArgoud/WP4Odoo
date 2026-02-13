<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ecwid Handler — data access for Ecwid products and orders.
 *
 * Transforms Ecwid REST API response data into Odoo-compatible formats.
 * The actual API fetching is done by the Ecwid_Cron_Hooks trait; this class
 * handles pure data transformation.
 *
 * Called by Ecwid_Module via its load_wp_data dispatch and cron polling.
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
class Ecwid_Handler {

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

	// ─── Load product ─────────────────────────────────────

	/**
	 * Transform an Ecwid API product into Odoo product.product data.
	 *
	 * @param array<string, mixed> $api_data Ecwid product data from REST API.
	 * @return array<string, mixed> Product data for field mapping, or empty if invalid.
	 */
	public function load_product( array $api_data ): array {
		$name = (string) ( $api_data['name'] ?? '' );
		if ( '' === $name ) {
			$this->logger->warning( 'Ecwid product has no name.', [ 'data' => $api_data ] );
			return [];
		}

		$price = (float) ( $api_data['price'] ?? 0 );
		$sku   = (string) ( $api_data['sku'] ?? '' );
		$desc  = (string) ( $api_data['description'] ?? '' );

		return [
			'product_name' => $name,
			'list_price'   => $price,
			'default_code' => $sku,
			'description'  => wp_strip_all_tags( $desc ),
			'type'         => 'consu',
		];
	}

	// ─── Load order ───────────────────────────────────────

	/**
	 * Transform an Ecwid API order into Odoo sale.order data.
	 *
	 * @param array<string, mixed> $api_data   Ecwid order data from REST API.
	 * @param int                  $partner_id Resolved Odoo partner ID.
	 * @return array<string, mixed> Order data for field mapping, or empty if invalid.
	 */
	public function load_order( array $api_data, int $partner_id ): array {
		$order_number = (string) ( $api_data['orderNumber'] ?? '' );
		$total        = (float) ( $api_data['total'] ?? 0 );
		$date         = (string) ( $api_data['createDate'] ?? '' );
		$items        = (array) ( $api_data['items'] ?? [] );

		$lines = [];
		foreach ( $items as $item ) {
			$item_name = (string) ( $item['name'] ?? '' );
			if ( '' === $item_name ) {
				continue;
			}

			$lines[] = [
				0,
				0,
				[
					'name'            => $item_name,
					'product_uom_qty' => (float) ( $item['quantity'] ?? 1 ),
					'price_unit'      => (float) ( $item['price'] ?? 0 ),
				],
			];
		}

		if ( empty( $lines ) && $total > 0 ) {
			$lines[] = [
				0,
				0,
				[
					/* translators: %s: order number. */
					'name'            => sprintf( __( 'Order %s', 'wp4odoo' ), $order_number ),
					'product_uom_qty' => 1,
					'price_unit'      => $total,
				],
			];
		}

		$date_order = '';
		if ( $date ) {
			$date_order = substr( $date, 0, 10 );
		}

		return [
			'partner_id'       => $partner_id,
			'date_order'       => $date_order ?: gmdate( 'Y-m-d' ),
			'client_order_ref' => $order_number,
			'order_line'       => $lines,
		];
	}

	// ─── API helpers ──────────────────────────────────────

	/**
	 * Fetch products from the Ecwid REST API.
	 *
	 * @param string $store_id  Ecwid store ID.
	 * @param string $api_token Ecwid API token.
	 * @return array<int, array<string, mixed>> List of product data arrays.
	 */
	public function fetch_products( string $store_id, string $api_token ): array {
		return $this->fetch_api( $store_id, $api_token, 'products' );
	}

	/**
	 * Fetch orders from the Ecwid REST API.
	 *
	 * @param string $store_id  Ecwid store ID.
	 * @param string $api_token Ecwid API token.
	 * @return array<int, array<string, mixed>> List of order data arrays.
	 */
	public function fetch_orders( string $store_id, string $api_token ): array {
		return $this->fetch_api( $store_id, $api_token, 'orders' );
	}

	// ─── Private ──────────────────────────────────────────

	/**
	 * Maximum items per API page.
	 *
	 * @var int
	 */
	private const API_PAGE_LIMIT = 100;

	/**
	 * Safety cap on pages to prevent runaway loops.
	 *
	 * @var int
	 */
	private const API_MAX_PAGES = 50;

	/**
	 * Call the Ecwid REST API with pagination and return all items.
	 *
	 * @param string $store_id  Store ID.
	 * @param string $api_token API secret token.
	 * @param string $endpoint  API endpoint ('products' or 'orders').
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_api( string $store_id, string $api_token, string $endpoint ): array {
		if ( '' === $store_id || '' === $api_token ) {
			return [];
		}

		$all_items = [];
		$offset    = 0;

		for ( $page = 0; $page < self::API_MAX_PAGES; $page++ ) {
			$url = sprintf(
				'https://app.ecwid.com/api/v3/%s/%s?offset=%d&limit=%d',
				$store_id,
				$endpoint,
				$offset,
				self::API_PAGE_LIMIT
			);

			$response = wp_remote_get(
				$url,
				[
					'headers' => [ 'Authorization' => 'Bearer ' . $api_token ],
					'timeout' => 30,
				]
			);

			if ( is_wp_error( $response ) ) {
				$this->logger->warning(
					'Ecwid API request failed.',
					[
						'endpoint' => $endpoint,
						'error'    => $response->get_error_message(),
					]
				);
				break;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$this->logger->warning(
					'Ecwid API returned non-200 status.',
					[
						'endpoint' => $endpoint,
						'code'     => $code,
					]
				);
				break;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) ) {
				break;
			}

			$items = $body['items'] ?? [];
			if ( empty( $items ) ) {
				break;
			}

			array_push( $all_items, ...$items );

			$total   = (int) ( $body['total'] ?? 0 );
			$offset += self::API_PAGE_LIMIT;

			if ( $offset >= $total ) {
				break;
			}
		}

		return $all_items;
	}
}
