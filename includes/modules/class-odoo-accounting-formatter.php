<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared data formatting for dual Odoo accounting models.
 *
 * Provides static methods to format payment/donation data for either
 * the OCA donation.donation model or core account.move (invoice).
 *
 * Used by GiveWP_Handler, Charitable_Handler, SimplePay_Handler,
 * and AffiliateWP_Handler.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
final class Odoo_Accounting_Formatter {

	/**
	 * Format data for OCA donation.donation model.
	 *
	 * @param int    $partner_id Odoo partner ID.
	 * @param int    $product_id Odoo product ID (form/campaign).
	 * @param float  $amount     Amount.
	 * @param string $date       Date (Y-m-d).
	 * @param string $ref        Payment reference.
	 * @return array<string, mixed>
	 */
	public static function for_donation_model( int $partner_id, int $product_id, float $amount, string $date, string $ref ): array {
		return [
			'partner_id'    => $partner_id,
			'donation_date' => $date,
			'payment_ref'   => $ref,
			'line_ids'      => [
				[
					0,
					0,
					[
						'product_id' => $product_id,
						'quantity'   => 1,
						'unit_price' => $amount,
					],
				],
			],
		];
	}

	/**
	 * Format data for core account.move model (invoice).
	 *
	 * @param int    $partner_id    Odoo partner ID.
	 * @param int    $product_id    Odoo product ID (form/campaign).
	 * @param float  $amount        Amount.
	 * @param string $date          Date (Y-m-d).
	 * @param string $ref           Payment reference.
	 * @param string $line_name     Invoice line name (title of form/campaign).
	 * @param string $fallback_name Default line name if $line_name is empty.
	 * @return array<string, mixed>
	 */
	public static function for_account_move( int $partner_id, int $product_id, float $amount, string $date, string $ref, string $line_name, string $fallback_name = '' ): array {
		$line_data = [
			'product_id' => $product_id,
			'quantity'   => 1,
			'price_unit' => $amount,
			'name'       => $line_name ?: $fallback_name,
		];

		/**
		 * Filter invoice line data before sending to Odoo.
		 *
		 * Use this to inject tax_ids, analytic accounts, or other
		 * Odoo fields. Example adding a tax:
		 *
		 *     add_filter( 'wp4odoo_invoice_line_data', function( $line ) {
		 *         $line['tax_ids'] = [ [ 6, 0, [ 1 ] ] ]; // Many2many command.
		 *         return $line;
		 *     } );
		 *
		 * @since 2.9.0
		 *
		 * @param array  $line_data  Invoice line data (product_id, quantity, price_unit, name).
		 * @param int    $partner_id Odoo partner ID.
		 * @param string $ref        Payment reference.
		 */
		$line_data = apply_filters( 'wp4odoo_invoice_line_data', $line_data, $partner_id, $ref );

		return [
			'move_type'        => 'out_invoice',
			'partner_id'       => $partner_id,
			'invoice_date'     => $date,
			'ref'              => $ref,
			'invoice_line_ids' => [
				[ 0, 0, $line_data ],
			],
		];
	}

	/**
	 * Build Odoo invoice_line_ids One2many tuples from a normalized item list.
	 *
	 * Each item should have 'name', 'quantity', and 'price_unit' keys.
	 * Falls back to a single line with $fallback_name and $total when
	 * the item list produces no lines.
	 *
	 * Used by WP_Invoice_Handler and Sprout_Invoices_Handler to avoid
	 * duplicating the same line-building algorithm.
	 *
	 * @since 3.1.0
	 *
	 * @param array<int, array{name: string, quantity: float, price_unit: float}> $items         Normalized line items.
	 * @param string                                                               $fallback_name Fallback line name when no items.
	 * @param float                                                                $total         Invoice total for fallback line.
	 * @return array<int, array<int, mixed>> Odoo One2many create tuples.
	 */
	public static function build_invoice_lines( array $items, string $fallback_name, float $total ): array {
		$lines = [];

		foreach ( $items as $item ) {
			if ( '' === $item['name'] ) {
				continue;
			}

			$lines[] = [
				0,
				0,
				[
					'name'       => $item['name'],
					'quantity'   => $item['quantity'],
					'price_unit' => $item['price_unit'],
				],
			];
		}

		if ( empty( $lines ) && $total > 0 ) {
			$lines[] = [
				0,
				0,
				[
					'name'       => $fallback_name,
					'quantity'   => 1.0,
					'price_unit' => $total,
				],
			];
		}

		return $lines;
	}

	/**
	 * Format data for vendor bill (account.move with in_invoice).
	 *
	 * Unlike customer invoices (out_invoice), vendor bills represent
	 * money owed TO a partner (e.g. affiliate commissions). No product_id
	 * is required — lines use name + price_unit only.
	 *
	 * @since 3.1.0
	 *
	 * @param int    $partner_id Odoo partner ID (vendor/affiliate).
	 * @param float  $amount     Amount.
	 * @param string $date       Date (Y-m-d).
	 * @param string $ref        Bill reference.
	 * @param string $line_name  Line description.
	 * @return array<string, mixed>
	 */
	public static function for_vendor_bill( int $partner_id, float $amount, string $date, string $ref, string $line_name ): array {
		$line_data = [
			'name'       => $line_name,
			'quantity'   => 1,
			'price_unit' => $amount,
		];

		/**
		 * Filter vendor bill line data before sending to Odoo.
		 *
		 * Use this to inject tax_ids, analytic accounts, or expense
		 * account IDs. Example:
		 *
		 *     add_filter( 'wp4odoo_vendor_bill_line_data', function( $line ) {
		 *         $line['account_id'] = 42; // Commission expense account.
		 *         return $line;
		 *     } );
		 *
		 * @since 3.1.0
		 *
		 * @param array  $line_data  Bill line data (name, quantity, price_unit).
		 * @param int    $partner_id Odoo partner ID.
		 * @param string $ref        Bill reference.
		 */
		$line_data = apply_filters( 'wp4odoo_vendor_bill_line_data', $line_data, $partner_id, $ref );

		return [
			'move_type'        => 'in_invoice',
			'partner_id'       => $partner_id,
			'invoice_date'     => $date,
			'ref'              => $ref,
			'invoice_line_ids' => [
				[ 0, 0, $line_data ],
			],
		];
	}
}
