<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dokan Handler — data access for marketplace vendors, sub-orders,
 * commissions, and payouts.
 *
 * Loads Dokan vendor data and formats sub-orders as purchase orders,
 * commissions as vendor bills (`account.move` with `move_type=in_invoice`),
 * and payouts as account payments (`account.payment`).
 *
 * Called by Dokan_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class Dokan_Handler {

	/**
	 * Vendor status mapping: Dokan → Odoo-friendly label.
	 *
	 * - enabled (active vendor) → active
	 * - disabled (suspended vendor) → inactive
	 *
	 * @var array<string, string>
	 */
	private const VENDOR_STATUS_MAP = [
		'enabled'  => 'active',
		'disabled' => 'inactive',
	];

	/**
	 * Sub-order status mapping: WooCommerce order statuses → Odoo purchase.order state.
	 *
	 * @var array<string, string>
	 */
	private const ORDER_STATUS_MAP = [
		'pending'    => 'draft',
		'processing' => 'purchase',
		'on-hold'    => 'draft',
		'completed'  => 'done',
		'cancelled'  => 'cancel',
		'refunded'   => 'cancel',
		'failed'     => 'cancel',
	];

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

	// ─── Vendor ─────────────────────────────────────────────

	/**
	 * Load vendor data as Odoo partner fields.
	 *
	 * Returns data suitable for `res.partner` with `supplier_rank = 1`.
	 *
	 * @param int $user_id WordPress user ID (vendor).
	 * @return array<string, mixed> Partner data, or empty if not found.
	 */
	public function load_vendor( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning( 'Dokan vendor user not found.', [ 'user_id' => $user_id ] );
			return [];
		}

		$store_info = $this->get_vendor_store_info( $user_id );
		$store_name = $store_info['store_name'] ?? '';
		$address    = $store_info['address'] ?? [];

		$name         = $store_name ?: $user->display_name;
		$street_parts = array_filter(
			[
				$address['street_1'] ?? '',
			]
		);

		return [
			'store_name'    => $store_name,
			'name'          => $name,
			'email'         => $user->user_email,
			'phone'         => $store_info['phone'] ?? '',
			'street'        => implode( ', ', $street_parts ),
			'city'          => $address['city'] ?? '',
			'zip'           => $address['zip'] ?? '',
			'supplier_rank' => 1,
			'is_company'    => $this->is_company_name( $name ),
		];
	}

	/**
	 * Save vendor status from Odoo pull.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $status  Odoo-side status string.
	 * @return bool True on success.
	 */
	public function save_vendor_status( int $user_id, string $status ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning( 'Cannot save vendor status — user not found.', [ 'user_id' => $user_id ] );
			return false;
		}

		$enabled = 'active' === $status;
		update_user_meta( $user_id, 'dokan_enable_selling', $enabled ? 'yes' : 'no' );

		$this->logger->info(
			'Updated Dokan vendor status from Odoo.',
			[
				'user_id' => $user_id,
				'status'  => $status,
				'enabled' => $enabled,
			]
		);

		return true;
	}

	/**
	 * Map a Dokan vendor status to an Odoo-friendly status string.
	 *
	 * @param string $status Dokan vendor status.
	 * @return string Odoo-friendly status.
	 */
	public function map_vendor_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::VENDOR_STATUS_MAP, 'wp4odoo_dokan_vendor_status_map', 'active' );
	}

	// ─── Sub-order ──────────────────────────────────────────

	/**
	 * Load a Dokan sub-order as Odoo purchase order fields.
	 *
	 * Resolves the vendor user ID and formats WC order items as
	 * purchase.order line One2many tuples `[(0, 0, {...})]`.
	 *
	 * @param int $order_id WC sub-order ID.
	 * @param int $partner_id Resolved Odoo partner ID (vendor).
	 * @return array<string, mixed> Purchase order data, or empty if not found.
	 */
	public function load_sub_order( int $order_id, int $partner_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->logger->warning( 'Dokan sub-order not found.', [ 'order_id' => $order_id ] );
			return [];
		}

		$date_created = $order->get_date_created();
		$parent_id    = $order->get_parent_id();

		/* translators: 1: sub-order ID, 2: parent order ID */
		$origin = sprintf( __( 'WC #%1$d (sub-order of #%2$d)', 'wp4odoo' ), $order_id, $parent_id );

		$items = $order->get_items();
		$lines = $this->format_purchase_order_lines( $items );

		return [
			'partner_id' => $partner_id,
			'date_order' => $date_created ? $date_created->format( 'Y-m-d' ) : '',
			'origin'     => $origin,
			'order_line' => $lines,
		];
	}

	/**
	 * Load a commission as Odoo vendor bill fields.
	 *
	 * Uses Odoo_Accounting_Formatter::for_vendor_bill() for consistent
	 * formatting with other modules (AffiliateWP, etc.).
	 *
	 * @param int   $order_id  WC order ID the commission is for.
	 * @param int   $partner_id Resolved Odoo partner ID (vendor).
	 * @param float $amount     Commission amount.
	 * @return array<string, mixed> Vendor bill data.
	 */
	public function load_commission( int $order_id, int $partner_id, float $amount ): array {
		/* translators: 1: order ID */
		$line_name = sprintf( __( 'Marketplace commission — Order #%d', 'wp4odoo' ), $order_id );

		$order        = wc_get_order( $order_id );
		$date_created = $order ? $order->get_date_created() : null;
		$date         = $date_created ? $date_created->format( 'Y-m-d' ) : gmdate( 'Y-m-d' );

		return Odoo_Accounting_Formatter::for_vendor_bill(
			$partner_id,
			$amount,
			$date,
			'dokan-comm-' . $order_id,
			$line_name
		);
	}

	/**
	 * Load a payout (withdraw request) as Odoo account.payment fields.
	 *
	 * @param int $withdraw_id Dokan withdraw ID.
	 * @param int $partner_id  Resolved Odoo partner ID (vendor).
	 * @return array<string, mixed> Payment data, or empty if not found.
	 */
	public function load_payout( int $withdraw_id, int $partner_id ): array {
		$withdraw = dokan_get_withdraw( $withdraw_id );
		if ( ! $withdraw ) {
			$this->logger->warning( 'Dokan withdraw not found.', [ 'withdraw_id' => $withdraw_id ] );
			return [];
		}

		$date = substr( $withdraw->date, 0, 10 );

		return [
			'partner_id'   => $partner_id,
			'amount'       => (float) $withdraw->amount,
			'date'         => $date,
			'ref'          => 'dokan-payout-' . $withdraw_id,
			'payment_type' => 'outbound',
			'partner_type' => 'supplier',
		];
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Get the vendor user ID for a Dokan sub-order.
	 *
	 * @param int $order_id WC sub-order ID.
	 * @return int Vendor user ID, or 0 if not found.
	 */
	public function get_vendor_id_for_order( int $order_id ): int {
		return (int) dokan_get_seller_id_by_order( $order_id );
	}

	/**
	 * Get the vendor user ID for a Dokan withdraw request.
	 *
	 * @param int $withdraw_id Dokan withdraw ID.
	 * @return int Vendor user ID, or 0 if not found.
	 */
	public function get_vendor_id_for_withdraw( int $withdraw_id ): int {
		$withdraw = dokan_get_withdraw( $withdraw_id );
		return $withdraw ? (int) $withdraw->user_id : 0;
	}

	/**
	 * Format WC order items as purchase.order line One2many tuples.
	 *
	 * @param array<int, mixed> $items WC order items.
	 * @return array<int, array<int, mixed>> Odoo One2many create tuples.
	 */
	public function format_purchase_order_lines( array $items ): array {
		$lines = [];

		foreach ( $items as $item ) {
			$name = is_object( $item ) && method_exists( $item, 'get_name' )
				? $item->get_name()
				: ( $item['name'] ?? __( 'Product', 'wp4odoo' ) );

			$qty = is_object( $item ) && method_exists( $item, 'get_quantity' )
				? (float) $item->get_quantity()
				: (float) ( $item['qty'] ?? 1 );

			$total = is_object( $item ) && method_exists( $item, 'get_total' )
				? (float) $item->get_total()
				: (float) ( $item['total'] ?? 0.0 );

			$price_unit = $qty > 0 ? $total / $qty : $total;

			$lines[] = [
				0,
				0,
				[
					'name'        => $name,
					'product_qty' => $qty,
					'price_unit'  => $price_unit,
				],
			];
		}

		return $lines;
	}

	/**
	 * Map an order status to an Odoo purchase.order state.
	 *
	 * @param string $status WC order status (without wc- prefix).
	 * @return string Odoo purchase.order state.
	 */
	public function map_order_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::ORDER_STATUS_MAP, 'wp4odoo_dokan_order_status_map', 'draft' );
	}

	/**
	 * Determine whether a name looks like a company name.
	 *
	 * Simple heuristic: names containing common business suffixes or
	 * more than 3 words are treated as companies.
	 *
	 * @param string $name Name to check.
	 * @return bool True if likely a company name.
	 */
	private function is_company_name( string $name ): bool {
		$business_suffixes = [ 'LLC', 'Inc', 'Ltd', 'Corp', 'GmbH', 'SAS', 'SARL', 'S.A.', 'Pty' ];

		foreach ( $business_suffixes as $suffix ) {
			if ( stripos( $name, $suffix ) !== false ) {
				return true;
			}
		}

		return str_word_count( $name ) > 3;
	}

	/**
	 * Get vendor store info from Dokan.
	 *
	 * @param int $user_id Vendor user ID.
	 * @return array<string, mixed> Store info array.
	 */
	private function get_vendor_store_info( int $user_id ): array {
		$store_info = (array) get_user_meta( $user_id, 'dokan_profile_settings', true );

		if ( empty( $store_info ) ) {
			return [
				'store_name' => '',
				'address'    => [],
				'phone'      => '',
			];
		}

		return $store_info;
	}
}
