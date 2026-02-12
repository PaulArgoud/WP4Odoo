<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Subscriptions Handler — data access for subscription products,
 * subscriptions, and renewal orders.
 *
 * Loads WC Subscriptions entities, maps statuses and billing periods to Odoo,
 * and pre-formats data for sale.subscription and account.move models.
 * Also provides reverse parsing (Odoo → WP) and save methods for pull sync.
 *
 * Called by WC_Subscriptions_Module via its load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.6.5
 */
class WC_Subscriptions_Handler {

	/**
	 * Subscription status mapping: WCS → Odoo sale.subscription state.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'pending'        => 'draft',
		'active'         => 'in_progress',
		'on-hold'        => 'paused',
		'cancelled'      => 'close',
		'expired'        => 'close',
		'pending-cancel' => 'in_progress',
		'switched'       => 'close',
	];

	/**
	 * Renewal order status mapping: WC order status → Odoo account.move state.
	 *
	 * @var array<string, string>
	 */
	private const RENEWAL_STATUS_MAP = [
		'completed'  => 'posted',
		'processing' => 'draft',
		'failed'     => 'cancel',
	];

	/**
	 * Billing period mapping: WCS → Odoo recurring_rule_type.
	 *
	 * @var array<string, string>
	 */
	private const BILLING_PERIOD_MAP = [
		'day'   => 'daily',
		'week'  => 'weekly',
		'month' => 'monthly',
		'year'  => 'yearly',
	];

	/**
	 * Reverse status mapping: Odoo sale.subscription state → WCS status.
	 *
	 * @var array<string, string>
	 */
	private const REVERSE_STATUS_MAP = [
		'draft'       => 'pending',
		'in_progress' => 'active',
		'paused'      => 'on-hold',
		'close'       => 'cancelled',
	];

	/**
	 * Reverse billing period mapping: Odoo recurring_rule_type → WCS period.
	 *
	 * @var array<string, string>
	 */
	private const REVERSE_BILLING_PERIOD_MAP = [
		'daily'   => 'day',
		'weekly'  => 'week',
		'monthly' => 'month',
		'yearly'  => 'year',
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

	// ─── Load product ──────────────────────────────────────

	/**
	 * Load a WooCommerce subscription product.
	 *
	 * @param int $product_id WC product ID.
	 * @return array<string, mixed> Product data for field mapping, or empty if not found.
	 */
	public function load_product( int $product_id ): array {
		$product = \wc_get_product( $product_id );
		if ( ! $product ) {
			$this->logger->warning( 'WC Subscription product not found.', [ 'product_id' => $product_id ] );
			return [];
		}

		$product_type = $product->get_type();
		if ( ! in_array( $product_type, [ 'subscription', 'variable-subscription' ], true ) ) {
			$this->logger->warning(
				'Product is not a subscription type.',
				[
					'product_id' => $product_id,
					'type'       => $product_type,
				]
			);
			return [];
		}

		return [
			'product_name' => $product->get_name(),
			'list_price'   => (float) $product->get_price(),
			'type'         => 'service',
		];
	}

	// ─── Load subscription ─────────────────────────────────

	/**
	 * Load a WooCommerce subscription.
	 *
	 * Returns raw data with user_id and item product_id for resolution by the module.
	 *
	 * @param int $sub_id WC Subscription ID.
	 * @return array<string, mixed> Subscription data, or empty if not found.
	 */
	public function load_subscription( int $sub_id ): array {
		$subscription = \wcs_get_subscription( $sub_id );
		if ( ! $subscription ) {
			$this->logger->warning( 'WC Subscription not found.', [ 'sub_id' => $sub_id ] );
			return [];
		}

		$items      = $subscription->get_items();
		$product_id = 0;
		$line_total = 0.0;
		$line_name  = '';

		if ( ! empty( $items ) ) {
			$first_item = reset( $items );
			$product_id = (int) ( $first_item['product_id'] ?? 0 );
			$line_total = (float) ( $first_item['line_total'] ?? 0.0 );
			$line_name  = $first_item['name'] ?? '';
		}

		return [
			'user_id'          => $subscription->get_user_id(),
			'product_id'       => $product_id,
			'product_name'     => $line_name,
			'billing_period'   => $subscription->get_billing_period(),
			'billing_interval' => $subscription->get_billing_interval(),
			'start_date'       => $subscription->get_date( 'start_date' ),
			'next_payment'     => $subscription->get_date( 'next_payment' ),
			'end_date'         => $subscription->get_date( 'end' ),
			'line_total'       => $line_total,
			'status'           => $subscription->get_status(),
		];
	}

	// ─── Format subscription ───────────────────────────────

	/**
	 * Format subscription data for Odoo sale.subscription model.
	 *
	 * Returns pre-formatted data with One2many tuples for recurring_invoice_line_ids.
	 *
	 * @param array<string, mixed> $data             Raw subscription data from load_subscription().
	 * @param int                  $product_odoo_id  Resolved Odoo product.product ID.
	 * @param int                  $partner_id       Resolved Odoo partner ID.
	 * @return array<string, mixed> Data for sale.subscription create/write.
	 */
	public function format_subscription( array $data, int $product_odoo_id, int $partner_id ): array {
		return [
			'partner_id'                 => $partner_id,
			'date_start'                 => substr( $data['start_date'] ?? '', 0, 10 ),
			'recurring_next_date'        => substr( $data['next_payment'] ?? '', 0, 10 ),
			'recurring_rule_type'        => $this->map_billing_period( $data['billing_period'] ?? 'month' ),
			'recurring_interval'         => (int) ( $data['billing_interval'] ?? 1 ),
			'recurring_invoice_line_ids' => [
				[
					0,
					0,
					[
						'product_id' => $product_odoo_id,
						'quantity'   => 1,
						'price_unit' => (float) ( $data['line_total'] ?? 0.0 ),
						'name'       => $data['product_name'] ?: \__( 'WC Subscription', 'wp4odoo' ),
					],
				],
			],
		];
	}

	// ─── Format renewal invoice ────────────────────────────

	/**
	 * Format a renewal order as an Odoo account.move (invoice).
	 *
	 * Returns pre-formatted data with One2many tuples for invoice_line_ids.
	 *
	 * @param array<string, mixed> $data            Renewal order data.
	 * @param int                  $product_odoo_id Resolved Odoo product.product ID.
	 * @param int                  $partner_id      Resolved Odoo partner ID.
	 * @return array<string, mixed> Data for account.move create.
	 */
	public function format_renewal_invoice( array $data, int $product_odoo_id, int $partner_id ): array {
		return [
			'move_type'        => 'out_invoice',
			'partner_id'       => $partner_id,
			'invoice_date'     => $data['date'] ?? '',
			'ref'              => $data['ref'] ?? '',
			'invoice_line_ids' => [
				[
					0,
					0,
					[
						'product_id' => $product_odoo_id,
						'quantity'   => 1,
						'price_unit' => (float) ( $data['total'] ?? 0.0 ),
						'name'       => $data['product_name'] ?: \__( 'WC Subscription renewal', 'wp4odoo' ),
					],
				],
			],
		];
	}

	// ─── Status mapping ────────────────────────────────────

	/**
	 * Map a WCS subscription status to an Odoo sale.subscription state.
	 *
	 * @param string $status WCS subscription status.
	 * @return string Odoo sale.subscription state.
	 */
	public function map_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::STATUS_MAP, 'wp4odoo_wcs_status_map', 'draft' );
	}

	/**
	 * Map a WC order status to an Odoo account.move state (for renewals).
	 *
	 * @param string $status WC order status.
	 * @return string Odoo account.move state.
	 */
	public function map_renewal_status_to_odoo( string $status ): string {
		return Status_Mapper::resolve( $status, self::RENEWAL_STATUS_MAP, 'wp4odoo_wcs_renewal_status_map', 'draft' );
	}

	// ─── Billing period mapping ────────────────────────────

	/**
	 * Map a WCS billing period to an Odoo recurring_rule_type.
	 *
	 * @param string $period WCS billing period (day, week, month, year).
	 * @return string Odoo recurring_rule_type.
	 */
	public function map_billing_period( string $period ): string {
		return Status_Mapper::resolve( $period, self::BILLING_PERIOD_MAP, 'wp4odoo_wcs_billing_period_map', 'monthly' );
	}

	// ─── Reverse status mapping ───────────────────────────

	/**
	 * Map an Odoo sale.subscription state to a WCS subscription status.
	 *
	 * @param string $odoo_state Odoo subscription state.
	 * @return string WCS subscription status.
	 */
	public function map_odoo_status_to_wcs( string $odoo_state ): string {
		return Status_Mapper::resolve( $odoo_state, self::REVERSE_STATUS_MAP, 'wp4odoo_wcs_reverse_status_map', 'pending' );
	}

	/**
	 * Map an Odoo recurring_rule_type to a WCS billing period.
	 *
	 * @param string $odoo_rule Odoo recurring rule type.
	 * @return string WCS billing period.
	 */
	public function map_odoo_billing_period_to_wcs( string $odoo_rule ): string {
		return Status_Mapper::resolve( $odoo_rule, self::REVERSE_BILLING_PERIOD_MAP, 'wp4odoo_wcs_reverse_billing_period_map', 'month' );
	}

	// ─── Parse subscription from Odoo ─────────────────────

	/**
	 * Parse Odoo sale.subscription data into WordPress-compatible format.
	 *
	 * Reverse of format_subscription(). Applies reverse status and billing
	 * period mappings.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> WordPress subscription data.
	 */
	public function parse_subscription_from_odoo( array $odoo_data ): array {
		$state = $odoo_data['stage_id'][1] ?? ( $odoo_data['state'] ?? 'draft' );
		if ( is_array( $state ) ) {
			$state = 'draft';
		}

		return [
			'status'           => $this->map_odoo_status_to_wcs( (string) $state ),
			'start_date'       => $odoo_data['date_start'] ?? '',
			'next_payment'     => $odoo_data['recurring_next_date'] ?? '',
			'billing_period'   => $this->map_odoo_billing_period_to_wcs( $odoo_data['recurring_rule_type'] ?? 'monthly' ),
			'billing_interval' => (int) ( $odoo_data['recurring_interval'] ?? 1 ),
		];
	}

	// ─── Save subscription ────────────────────────────────

	/**
	 * Save pulled subscription data to WooCommerce.
	 *
	 * Only updates existing subscriptions (no creation from Odoo side —
	 * subscriptions are created by WC checkout). Applies status changes
	 * via update_status().
	 *
	 * @param array<string, mixed> $data  Parsed subscription data.
	 * @param int                  $wp_id WC Subscription ID (must be > 0).
	 * @return int Subscription ID on success, 0 on failure.
	 */
	public function save_subscription( array $data, int $wp_id ): int {
		if ( $wp_id <= 0 ) {
			$this->logger->warning( 'Cannot create subscriptions from Odoo — subscriptions originate in WooCommerce.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		$subscription = \wcs_get_subscription( $wp_id );
		if ( ! $subscription ) {
			$this->logger->warning( 'WC Subscription not found for pull update.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		$new_status     = $data['status'] ?? '';
		$current_status = $subscription->get_status();

		if ( ! empty( $new_status ) && $new_status !== $current_status ) {
			$subscription->update_status( $new_status );
		}

		return $wp_id;
	}

	// ─── Helpers ───────────────────────────────────────────

	/**
	 * Get the first subscription product ID from a renewal order.
	 *
	 * @param int $order_id WC order ID.
	 * @return int Product ID, or 0 if not found.
	 */
	public function get_product_id_for_renewal( int $order_id ): int {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}

		$items = $order->get_items();
		if ( empty( $items ) ) {
			return 0;
		}

		$first_item = reset( $items );
		return (int) ( $first_item['product_id'] ?? 0 );
	}
}
