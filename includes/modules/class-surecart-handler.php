<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SureCart Handler — data access for products, orders, and subscriptions.
 *
 * Loads SureCart entities via their REST model API, maps statuses and billing
 * periods to Odoo, and pre-formats data for product.template, sale.order,
 * and sale.subscription models.
 *
 * Called by SureCart_Module via its load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class SureCart_Handler {

	/**
	 * Subscription status mapping: SureCart → Odoo sale.subscription state.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'active'   => 'in_progress',
		'past_due' => 'paused',
		'canceled' => 'close',
		'trialing' => 'draft',
	];

	/**
	 * Reverse status mapping: Odoo sale.subscription state → SureCart status.
	 *
	 * @var array<string, string>
	 */
	private const REVERSE_STATUS_MAP = [
		'draft'       => 'trialing',
		'in_progress' => 'active',
		'paused'      => 'past_due',
		'close'       => 'canceled',
	];

	/**
	 * Billing period mapping: SureCart → Odoo recurring_rule_type.
	 *
	 * @var array<string, string>
	 */
	private const BILLING_PERIOD_MAP = [
		'monthly' => 'monthly',
		'yearly'  => 'yearly',
		'weekly'  => 'weekly',
		'daily'   => 'daily',
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

	// ─── Load Product ─────────────────────────────────────

	/**
	 * Load a SureCart product.
	 *
	 * @param int $product_id SureCart product ID (numeric).
	 * @return array<string, mixed> Normalized product data, or empty if not found.
	 */
	public function load_product( int $product_id ): array {
		$product = \SureCart\Models\Product::find( (string) $product_id );
		if ( ! $product ) {
			$this->logger->warning( 'SureCart product not found.', [ 'product_id' => $product_id ] );
			return [];
		}

		return [
			'name'        => $product->name,
			'slug'        => $product->slug,
			'description' => $product->description,
			'price'       => (float) $product->price / 100.0,
		];
	}

	// ─── Load Order ───────────────────────────────────────

	/**
	 * Load a SureCart order (checkout).
	 *
	 * @param int $order_id SureCart checkout ID (numeric).
	 * @return array<string, mixed> Normalized order data, or empty if not found.
	 */
	public function load_order( int $order_id ): array {
		$checkout = \SureCart\Models\Checkout::find( (string) $order_id );
		if ( ! $checkout ) {
			$this->logger->warning( 'SureCart order not found.', [ 'order_id' => $order_id ] );
			return [];
		}

		return [
			'email'        => $checkout->email,
			'name'         => $checkout->name,
			'total_amount' => (float) $checkout->total_amount / 100.0,
			'created_at'   => $checkout->created_at,
			'line_items'   => $checkout->line_items,
		];
	}

	// ─── Load Subscription ────────────────────────────────

	/**
	 * Load a SureCart subscription.
	 *
	 * @param int $sub_id SureCart subscription ID (numeric).
	 * @return array<string, mixed> Normalized subscription data, or empty if not found.
	 */
	public function load_subscription( int $sub_id ): array {
		$sub = \SureCart\Models\Subscription::find( (string) $sub_id );
		if ( ! $sub ) {
			$this->logger->warning( 'SureCart subscription not found.', [ 'sub_id' => $sub_id ] );
			return [];
		}

		return [
			'email'            => $sub->email,
			'name'             => $sub->name,
			'product_id'       => $sub->product_id,
			'status'           => $sub->status,
			'billing_period'   => $sub->billing_period,
			'billing_interval' => $sub->billing_interval,
			'created_at'       => $sub->created_at,
			'amount'           => (float) $sub->amount / 100.0,
			'product_name'     => $sub->product_name,
		];
	}

	// ─── Save Subscription Status ─────────────────────────

	/**
	 * Save a subscription status pulled from Odoo.
	 *
	 * @param int    $wp_id       SureCart subscription ID (numeric).
	 * @param string $odoo_status Odoo sale.subscription state.
	 * @return bool True if updated successfully.
	 */
	public function save_subscription_status( int $wp_id, string $odoo_status ): bool {
		$sub = \SureCart\Models\Subscription::find( (string) $wp_id );
		if ( ! $sub ) {
			$this->logger->warning( 'SureCart subscription not found for status update.', [ 'wp_id' => $wp_id ] );
			return false;
		}

		$sc_status = $this->map_status_from_odoo( $odoo_status );
		if ( $sc_status === $sub->status ) {
			return true; // No change needed.
		}

		return $sub->update_status( $sc_status );
	}

	// ─── Format Order Lines ───────────────────────────────

	/**
	 * Format SureCart line items as Odoo One2many tuples.
	 *
	 * Each line item becomes a [0, 0, {product_id, product_uom_qty, price_unit, name}] tuple.
	 *
	 * @param array    $line_items     SureCart line items.
	 * @param callable $product_mapper Resolves SureCart product ID → Odoo product ID.
	 * @return array<int, array> Odoo One2many tuples.
	 */
	public function format_order_lines( array $line_items, callable $product_mapper ): array {
		$lines = [];

		foreach ( $line_items as $item ) {
			$sc_product_id = $item['product_id'] ?? '';
			$odoo_product  = $product_mapper( $sc_product_id );
			$quantity      = (int) ( $item['quantity'] ?? 1 );
			$price         = (float) ( $item['price'] ?? 0 ) / 100.0;
			$item_name     = $item['name'] ?? __( 'SureCart item', 'wp4odoo' );

			$lines[] = [
				0,
				0,
				[
					'product_id'      => $odoo_product,
					'product_uom_qty' => $quantity,
					'price_unit'      => $price,
					'name'            => $item_name,
				],
			];
		}

		return $lines;
	}

	// ─── Billing Period Mapping ───────────────────────────

	/**
	 * Map a SureCart billing period to an Odoo recurring_rule_type.
	 *
	 * @param string $sc_period SureCart billing period (monthly, yearly, weekly, daily).
	 * @return string Odoo recurring_rule_type.
	 */
	public function map_billing_period( string $sc_period ): string {
		return Status_Mapper::resolve( $sc_period, self::BILLING_PERIOD_MAP, 'wp4odoo_surecart_billing_period_map', 'monthly' );
	}

	// ─── Status Mapping ──────────────────────────────────

	/**
	 * Map a SureCart subscription status to an Odoo sale.subscription state.
	 *
	 * @param string $sc_status SureCart status (active, past_due, canceled, trialing).
	 * @return string Odoo sale.subscription state.
	 */
	public function map_status_to_odoo( string $sc_status ): string {
		return Status_Mapper::resolve( $sc_status, self::STATUS_MAP, 'wp4odoo_surecart_status_map', 'draft' );
	}

	/**
	 * Map an Odoo sale.subscription state to a SureCart subscription status.
	 *
	 * @param string $odoo_status Odoo state.
	 * @return string SureCart subscription status.
	 */
	public function map_status_from_odoo( string $odoo_status ): string {
		return Status_Mapper::resolve( $odoo_status, self::REVERSE_STATUS_MAP, 'wp4odoo_surecart_reverse_status_map', 'active' );
	}
}
