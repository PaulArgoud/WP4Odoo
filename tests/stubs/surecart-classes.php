<?php
/**
 * SureCart class and function stubs for PHPUnit tests.
 *
 * @package WP4Odoo\Tests
 */

// ─── Global namespace: constants and stores ─────────────

namespace {

	if ( ! defined( 'SURECART_VERSION' ) ) {
		define( 'SURECART_VERSION', '3.0.0' );
	}

	$GLOBALS['_surecart_products']      = [];
	$GLOBALS['_surecart_orders']        = [];
	$GLOBALS['_surecart_subscriptions'] = [];
}

// ─── SureCart model stubs ───────────────────────────────

namespace SureCart\Models {

	/**
	 * SureCart Product model stub.
	 */
	class Product {

		/**
		 * Product ID.
		 *
		 * @var string
		 */
		public string $id = '';

		/**
		 * Product name.
		 *
		 * @var string
		 */
		public string $name = '';

		/**
		 * Product slug.
		 *
		 * @var string
		 */
		public string $slug = '';

		/**
		 * Product description.
		 *
		 * @var string
		 */
		public string $description = '';

		/**
		 * Product price in cents.
		 *
		 * @var int
		 */
		public int $price = 0;

		/**
		 * Find a product by ID.
		 *
		 * @param string $id Product ID.
		 * @return self|null
		 */
		public static function find( string $id ): ?self {
			$data = $GLOBALS['_surecart_products'][ $id ] ?? null;
			if ( ! $data ) {
				return null;
			}

			$product              = new self();
			$product->id          = $id;
			$product->name        = $data['name'] ?? '';
			$product->slug        = $data['slug'] ?? '';
			$product->description = $data['description'] ?? '';
			$product->price       = $data['price'] ?? 0;

			return $product;
		}
	}

	/**
	 * SureCart Checkout model stub.
	 */
	class Checkout {

		/**
		 * Checkout ID.
		 *
		 * @var string
		 */
		public string $id = '';

		/**
		 * Customer email.
		 *
		 * @var string
		 */
		public string $email = '';

		/**
		 * Customer name.
		 *
		 * @var string
		 */
		public string $name = '';

		/**
		 * Total amount in cents.
		 *
		 * @var int
		 */
		public int $total_amount = 0;

		/**
		 * Order creation date.
		 *
		 * @var string
		 */
		public string $created_at = '';

		/**
		 * Line items.
		 *
		 * @var array
		 */
		public array $line_items = [];

		/**
		 * Find a checkout by ID.
		 *
		 * @param string $id Checkout ID.
		 * @return self|null
		 */
		public static function find( string $id ): ?self {
			$data = $GLOBALS['_surecart_orders'][ $id ] ?? null;
			if ( ! $data ) {
				return null;
			}

			$checkout               = new self();
			$checkout->id           = $id;
			$checkout->email        = $data['email'] ?? '';
			$checkout->name         = $data['name'] ?? '';
			$checkout->total_amount = $data['total_amount'] ?? 0;
			$checkout->created_at   = $data['created_at'] ?? '';
			$checkout->line_items   = $data['line_items'] ?? [];

			return $checkout;
		}
	}

	/**
	 * SureCart Subscription model stub.
	 */
	class Subscription {

		/**
		 * Subscription ID.
		 *
		 * @var string
		 */
		public string $id = '';

		/**
		 * Customer email.
		 *
		 * @var string
		 */
		public string $email = '';

		/**
		 * Customer name.
		 *
		 * @var string
		 */
		public string $name = '';

		/**
		 * Product ID.
		 *
		 * @var string
		 */
		public string $product_id = '';

		/**
		 * Subscription status (active, past_due, canceled, trialing).
		 *
		 * @var string
		 */
		public string $status = 'active';

		/**
		 * Billing period (monthly, yearly, weekly, daily).
		 *
		 * @var string
		 */
		public string $billing_period = 'monthly';

		/**
		 * Billing interval.
		 *
		 * @var int
		 */
		public int $billing_interval = 1;

		/**
		 * Start date.
		 *
		 * @var string
		 */
		public string $created_at = '';

		/**
		 * Amount in cents.
		 *
		 * @var int
		 */
		public int $amount = 0;

		/**
		 * Product name.
		 *
		 * @var string
		 */
		public string $product_name = '';

		/**
		 * Find a subscription by ID.
		 *
		 * @param string $id Subscription ID.
		 * @return self|null
		 */
		public static function find( string $id ): ?self {
			$data = $GLOBALS['_surecart_subscriptions'][ $id ] ?? null;
			if ( ! $data ) {
				return null;
			}

			$sub                   = new self();
			$sub->id               = $id;
			$sub->email            = $data['email'] ?? '';
			$sub->name             = $data['name'] ?? '';
			$sub->product_id       = $data['product_id'] ?? '';
			$sub->status           = $data['status'] ?? 'active';
			$sub->billing_period   = $data['billing_period'] ?? 'monthly';
			$sub->billing_interval = $data['billing_interval'] ?? 1;
			$sub->created_at       = $data['created_at'] ?? '';
			$sub->amount           = $data['amount'] ?? 0;
			$sub->product_name     = $data['product_name'] ?? '';

			return $sub;
		}

		/**
		 * Update the subscription status.
		 *
		 * @param string $status New status.
		 * @return bool
		 */
		public function update_status( string $status ): bool {
			$this->status = $status;
			if ( isset( $GLOBALS['_surecart_subscriptions'][ $this->id ] ) ) {
				$GLOBALS['_surecart_subscriptions'][ $this->id ]['status'] = $status;
			}
			return true;
		}
	}
}
