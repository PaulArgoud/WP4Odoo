<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Field_Mapper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Currency Guard — detects Odoo/WC currency mismatches.
 *
 * Centralises the check used by WooCommerce_Module and Variant_Handler
 * to skip price updates when the Odoo product currency differs from
 * the WooCommerce shop currency.
 *
 * @package WP4Odoo
 * @since   1.8.0
 */
class Currency_Guard {

	/**
	 * Check whether the Odoo currency matches the WC shop currency.
	 *
	 * Accepts a raw currency value (Many2one array, string code, or null)
	 * and returns a structured result.
	 *
	 * @param mixed $currency_value Odoo currency_id (Many2one array, string, or null/false).
	 * @return array{mismatch: bool, odoo_currency: string, wc_currency: string}
	 */
	public static function check( mixed $currency_value ): array {
		$odoo_currency = '';

		if ( is_array( $currency_value ) || ( is_int( $currency_value ) && $currency_value > 0 ) ) {
			$odoo_currency = Field_Mapper::many2one_to_name( $currency_value ) ?? '';
		} elseif ( is_string( $currency_value ) && '' !== $currency_value ) {
			$odoo_currency = $currency_value;
		}

		$wc_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$mismatch    = '' !== $odoo_currency && '' !== $wc_currency && $odoo_currency !== $wc_currency;

		return [
			'mismatch'      => $mismatch,
			'odoo_currency' => $odoo_currency,
			'wc_currency'   => $wc_currency,
		];
	}
}
