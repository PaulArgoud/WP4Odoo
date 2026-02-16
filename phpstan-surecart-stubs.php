<?php
/**
 * SureCart namespace stubs for PHPStan.
 *
 * Separated into its own file because PHP namespace declarations
 * cannot be mixed with non-namespaced code in the same file.
 *
 * @package WP4Odoo
 */

namespace SureCart\Models {

	if ( ! class_exists( Product::class ) ) {
		class Product {
			public string $id          = '';
			public string $name        = '';
			public string $slug        = '';
			public string $description = '';
			public int $price          = 0;

			public static function find( string $id ): ?self {
				return null;
			}
		}
	}

	if ( ! class_exists( Checkout::class ) ) {
		class Checkout {
			public string $id           = '';
			public string $email        = '';
			public string $name         = '';
			public int $total_amount    = 0;
			public string $created_at   = '';
			/** @var array<int, array<string, mixed>> */
			public array $line_items    = [];

			public static function find( string $id ): ?self {
				return null;
			}
		}
	}

	if ( ! class_exists( Subscription::class ) ) {
		class Subscription {
			public string $id               = '';
			public string $email            = '';
			public string $name             = '';
			public string $product_id       = '';
			public string $status           = 'active';
			public string $billing_period   = 'monthly';
			public int $billing_interval    = 1;
			public string $created_at       = '';
			public int $amount              = 0;
			public string $product_name     = '';

			public static function find( string $id ): ?self {
				return null;
			}

			public function update_status( string $status ): bool {
				return true;
			}
		}
	}
}
