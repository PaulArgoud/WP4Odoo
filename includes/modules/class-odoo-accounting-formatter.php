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
 * Used by GiveWP_Handler, Charitable_Handler, and SimplePay_Handler.
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
}
