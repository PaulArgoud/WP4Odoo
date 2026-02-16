<?php
/**
 * WCFM Marketplace test stubs.
 *
 * Provides minimal class and function stubs for unit testing
 * the WCFM module without the full WCFM Marketplace plugin.
 *
 * @package WP4Odoo\Tests
 */

// ─── Detection ──────────────────────────────────────────

if ( ! defined( 'WCFM_VERSION' ) ) {
	define( 'WCFM_VERSION', '6.7.0' );
}

if ( ! defined( 'WCFMmp_VERSION' ) ) {
	define( 'WCFMmp_VERSION', '2.11.0' );
}

// ─── Global test stores ─────────────────────────────────

$GLOBALS['_wcfm_vendors']     = [];
$GLOBALS['_wcfm_commissions'] = [];
$GLOBALS['_wcfm_withdrawals'] = [];
$GLOBALS['_wcfm_orders']      = [];

// ─── WCFMmp_Vendor stub ─────────────────────────────────

if ( ! class_exists( 'WCFMmp_Vendor' ) ) {
	class WCFMmp_Vendor {
		public int $id            = 0;
		public string $store_name = '';
		public string $email      = '';
		public string $phone      = '';
		public string $address    = '';
		public string $city       = '';
		public string $state      = '';
		public string $country    = '';
		public string $postcode   = '';
		public bool $enabled      = true;

		/**
		 * Get store info.
		 *
		 * @return array<string, mixed>
		 */
		public function get_shop_info(): array {
			return [
				'store_name' => $this->store_name,
				'address'    => [
					'street_1' => $this->address,
					'city'     => $this->city,
					'state'    => $this->state,
					'country'  => $this->country,
					'zip'      => $this->postcode,
				],
				'phone'      => $this->phone,
			];
		}
	}
}

// ─── $WCFM global stub ─────────────────────────────────

if ( ! isset( $GLOBALS['WCFM'] ) ) {
	$GLOBALS['WCFM'] = new stdClass();

	$GLOBALS['WCFM']->wcfmmp_vendor = new stdClass();

	$GLOBALS['WCFM']->wcfmmp_vendor->get = function ( $id ) {
		return $GLOBALS['_wcfm_vendors'][ (int) $id ] ?? false;
	};
}

// ─── API function stubs ─────────────────────────────────

if ( ! function_exists( 'wcfm_get_vendor_id_by_post' ) ) {
	/**
	 * Get vendor user ID from a post/product.
	 *
	 * @param int $post_id Post ID.
	 * @return int Vendor user ID.
	 */
	function wcfm_get_vendor_id_by_post( $post_id ) {
		$post_id   = (int) $post_id;
		$author_id = (int) get_post_field( 'post_author', $post_id );
		return $author_id;
	}
}

if ( ! function_exists( 'wcfm_get_vendor_store_name' ) ) {
	/**
	 * Get vendor store name.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return string
	 */
	function wcfm_get_vendor_store_name( $vendor_id ) {
		$vendor_id = (int) $vendor_id;
		$vendor    = $GLOBALS['_wcfm_vendors'][ $vendor_id ] ?? null;
		return $vendor ? $vendor->store_name : '';
	}
}

if ( ! function_exists( 'wcfm_get_vendor_id_by_order' ) ) {
	/**
	 * Get vendor ID for a sub-order.
	 *
	 * @param int $order_id Order ID.
	 * @return int Vendor user ID.
	 */
	function wcfm_get_vendor_id_by_order( $order_id ) {
		$order_id = (int) $order_id;
		$order    = $GLOBALS['_wcfm_orders'][ $order_id ] ?? null;
		return $order ? (int) ( $order['vendor_id'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wcfm_get_commission' ) ) {
	/**
	 * Get commission data by ID.
	 *
	 * @param int $commission_id Commission ID.
	 * @return object|false
	 */
	function wcfm_get_commission( $commission_id ) {
		$commission_id = (int) $commission_id;
		return $GLOBALS['_wcfm_commissions'][ $commission_id ] ?? false;
	}
}

if ( ! function_exists( 'wcfm_get_withdrawal' ) ) {
	/**
	 * Get withdrawal data by ID.
	 *
	 * @param int $withdrawal_id Withdrawal ID.
	 * @return object|false
	 */
	function wcfm_get_withdrawal( $withdrawal_id ) {
		$withdrawal_id = (int) $withdrawal_id;
		return $GLOBALS['_wcfm_withdrawals'][ $withdrawal_id ] ?? false;
	}
}
