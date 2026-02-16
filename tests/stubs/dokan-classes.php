<?php
/**
 * Dokan test stubs.
 *
 * Provides minimal class and function stubs for unit testing
 * the Dokan module without the full Dokan plugin.
 *
 * @package WP4Odoo\Tests
 */

// ─── Detection ──────────────────────────────────────────

if ( ! defined( 'DOKAN_PLUGIN_VERSION' ) ) {
	define( 'DOKAN_PLUGIN_VERSION', '4.0.0' );
}

// ─── Global test stores ─────────────────────────────────

$GLOBALS['_dokan_vendors']   = [];
$GLOBALS['_dokan_orders']    = [];
$GLOBALS['_dokan_withdraws'] = [];

// ─── Dokan_Vendor stub ──────────────────────────────────

if ( ! class_exists( 'Dokan_Vendor' ) ) {
	class Dokan_Vendor {
		public int $id              = 0;
		public string $store_name   = '';
		public string $email        = '';
		public string $phone        = '';
		public string $address      = '';
		public string $city         = '';
		public string $state        = '';
		public string $country      = '';
		public string $postcode     = '';
		public string $store_banner = '';
		public bool $enabled        = true;

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

		/**
		 * Get vendor email.
		 *
		 * @return string
		 */
		public function get_email(): string {
			return $this->email;
		}

		/**
		 * Get vendor name.
		 *
		 * @return string
		 */
		public function get_name(): string {
			return $this->store_name;
		}
	}
}

// ─── Dokan withdraw stub ────────────────────────────────

if ( ! class_exists( 'Dokan_Withdraw' ) ) {
	class Dokan_Withdraw {
		public int $id         = 0;
		public int $user_id    = 0;
		public float $amount   = 0.0;
		public string $date    = '2026-01-01 00:00:00';
		public string $status  = 'pending';
		public string $method  = 'paypal';
		public string $note    = '';
		public string $ip      = '';
	}
}

// ─── dokan() function stub ──────────────────────────────

if ( ! function_exists( 'dokan' ) ) {
	/**
	 * Dokan singleton stub.
	 *
	 * @return stdClass
	 */
	function dokan() {
		static $instance;
		if ( ! $instance ) {
			$instance         = new stdClass();
			$instance->vendor = new stdClass();

			$instance->vendor->get = function ( $id ) {
				return $GLOBALS['_dokan_vendors'][ (int) $id ] ?? false;
			};
		}
		return $instance;
	}
}

// ─── API function stubs ─────────────────────────────────

if ( ! function_exists( 'dokan_get_seller_id_by_order' ) ) {
	/**
	 * Get vendor (seller) ID for a sub-order.
	 *
	 * @param int $order_id Order ID.
	 * @return int Vendor user ID.
	 */
	function dokan_get_seller_id_by_order( $order_id ) {
		$order_id = (int) $order_id;
		$order    = $GLOBALS['_dokan_orders'][ $order_id ] ?? null;
		return $order ? (int) ( $order['vendor_id'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'dokan_get_earning_by_order' ) ) {
	/**
	 * Get Dokan commission earnings for an order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $context  Context ('seller' or 'admin').
	 * @return float
	 */
	function dokan_get_earning_by_order( $order_id, $context = 'seller' ) {
		$order_id = (int) $order_id;
		$order    = $GLOBALS['_dokan_orders'][ $order_id ] ?? null;
		if ( ! $order ) {
			return 0.0;
		}
		return 'admin' === $context
			? (float) ( $order['admin_commission'] ?? 0.0 )
			: (float) ( $order['vendor_earning'] ?? 0.0 );
	}
}

if ( ! function_exists( 'dokan_get_vendor_by_product' ) ) {
	/**
	 * Get vendor for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return Dokan_Vendor|false
	 */
	function dokan_get_vendor_by_product( $product_id ) {
		$product_id = (int) $product_id;
		$author_id  = (int) get_post_field( 'post_author', $product_id );
		return $GLOBALS['_dokan_vendors'][ $author_id ] ?? false;
	}
}

if ( ! function_exists( 'dokan_get_withdraw' ) ) {
	/**
	 * Get a withdraw request by ID.
	 *
	 * @param int $withdraw_id Withdraw ID.
	 * @return Dokan_Withdraw|false
	 */
	function dokan_get_withdraw( $withdraw_id ) {
		$withdraw_id = (int) $withdraw_id;
		return $GLOBALS['_dokan_withdraws'][ $withdraw_id ] ?? false;
	}
}
