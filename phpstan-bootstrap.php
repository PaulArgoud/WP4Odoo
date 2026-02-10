<?php
/**
 * PHPStan bootstrap — defines plugin constants and stubs
 * that are normally set up by the main wp4odoo.php entry point.
 *
 * @package WP4Odoo
 */

define( 'WP4ODOO_VERSION', '1.9.8' );
define( 'WP4ODOO_PLUGIN_FILE', __DIR__ . '/wp4odoo.php' );
define( 'WP4ODOO_PLUGIN_DIR', __DIR__ . '/' );
define( 'WP4ODOO_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp4odoo/' );
define( 'WP4ODOO_PLUGIN_BASENAME', 'wp4odoo/wp4odoo.php' );
define( 'WP4ODOO_MIN_ODOO_VERSION', 14 );
define( 'WPINC', 'wp-includes' );

/**
 * Stub for the main plugin singleton.
 *
 * The real class is defined in wp4odoo.php which is outside
 * the PHPStan scan path.
 */
class WP4Odoo_Plugin {

	/** @return static */
	public static function instance(): static {
		return new static();
	}

	/** @return \WP4Odoo\Module_Base|null */
	public function get_module( string $id ): ?\WP4Odoo\Module_Base {
		return null;
	}

	/** @return array<string, \WP4Odoo\Module_Base> */
	public function get_modules(): array {
		return [];
	}

	/** @return \WP4Odoo\API\Odoo_Client */
	public function client(): \WP4Odoo\API\Odoo_Client {
		return new \WP4Odoo\API\Odoo_Client();
	}
}

// ─── WooCommerce stubs ──────────────────────────────────
// Minimal stubs so PHPStan can analyse WooCommerce_Module
// without requiring the full WC codebase.

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * @param int $product_id
	 * @return WC_Product|false
	 */
	function wc_get_product( $product_id = 0 ) {
		return false;
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $order_id
	 * @return WC_Order|false
	 */
	function wc_get_order( $order_id = 0 ) {
		return false;
	}
}

if ( ! function_exists( 'wc_update_product_stock' ) ) {
	/**
	 * @param int      $product_id
	 * @param int|null $stock_quantity
	 * @return int|bool
	 */
	function wc_update_product_stock( $product_id, $stock_quantity = null ) {
		return true;
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		public function get_name(): string { return ''; }
		public function set_name( string $name ): void {}
		public function get_sku(): string { return ''; }
		public function set_sku( string $sku ): void {}
		public function get_regular_price(): string { return ''; }
		public function set_regular_price( string $price ): void {}
		public function get_stock_quantity(): ?int { return null; }
		public function set_stock_quantity( ?int $quantity ): void {}
		public function set_manage_stock( bool $manage ): void {}
		public function get_weight(): string { return ''; }
		public function set_weight( string $weight ): void {}
		public function get_description(): string { return ''; }
		public function set_description( string $description ): void {}
		public function save(): int { return 0; }
		public function delete( bool $force = false ): bool { return true; }
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public function get_total(): string { return '0'; }
		public function get_date_created(): ?\WC_DateTime { return null; }
		public function get_status(): string { return ''; }
		public function set_status( string $status ): void {}
		public function get_billing_email(): string { return ''; }
		public function get_formatted_billing_full_name(): string { return ''; }
		public function get_customer_id(): int { return 0; }
		public function save(): int { return 0; }
	}
}

if ( ! class_exists( 'WC_Product_Variable' ) ) {
	class WC_Product_Variable extends WC_Product {
		public function __construct( int $id = 0 ) {}
		/** @param WC_Product_Attribute[] $attributes */
		public function set_attributes( array $attributes ): void {}
	}
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	class WC_Product_Variation extends WC_Product {
		public function set_parent_id( int $parent_id ): void {}
		/** @param array<string, string> $attributes */
		public function set_attributes( $attributes ): void {}
	}
}

if ( ! class_exists( 'WC_Product_Attribute' ) ) {
	class WC_Product_Attribute {
		public function set_name( string $name ): void {}
		/** @param string[] $options */
		public function set_options( array $options ): void {}
		public function set_visible( bool $visible ): void {}
		public function set_variation( bool $variation ): void {}
		public function set_position( int $position ): void {}
	}
}

if ( ! function_exists( 'wc_get_products' ) ) {
	/**
	 * @param array $args
	 * @return array
	 */
	function wc_get_products( $args = [] ) {
		return [];
	}
}

if ( ! class_exists( 'WC_DateTime' ) ) {
	class WC_DateTime extends \DateTime {
	}
}

// ─── WP-CLI stubs ───────────────────────────────────────

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', false );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/** @param string $command */
		public static function add_command( string $command, string $class ): void {}
		public static function line( string $message = '' ): void {}
		public static function success( string $message ): void {}
		public static function warning( string $message ): void {}
		public static function error( string $message ): void {}
	}
}

// WP_CLI\Utils namespace stub loaded from separate file (PHP namespace rules).
require_once __DIR__ . '/phpstan-wp-cli-stubs.php';
