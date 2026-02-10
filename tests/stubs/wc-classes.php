<?php
/**
 * WooCommerce class and function stubs for PHPUnit tests.
 *
 * @package WP4Odoo\Tests
 */

// ─── WC functions ───────────────────────────────────────

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $product_id = 0 ) {
		return false;
	}
}

if ( ! function_exists( 'wc_get_products' ) ) {
	function wc_get_products( $args = [] ) {
		return [];
	}
}

if ( ! function_exists( 'wc_update_product_stock' ) ) {
	function wc_update_product_stock( $product_id, $stock_quantity = null ) {
		return true;
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() {
		return 'EUR';
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id = 0 ) {
		return $GLOBALS['_wc_orders'][ $order_id ] ?? false;
	}
}

// ─── WC classes ─────────────────────────────────────────

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		protected int $id = 0;
		protected array $data = [];
		public function get_name(): string { return $this->data['name'] ?? ''; }
		public function set_name( string $name ): void { $this->data['name'] = $name; }
		public function get_sku(): string { return $this->data['sku'] ?? ''; }
		public function set_sku( string $sku ): void { $this->data['sku'] = $sku; }
		public function get_regular_price(): string { return $this->data['regular_price'] ?? ''; }
		public function set_regular_price( string $price ): void { $this->data['regular_price'] = $price; }
		public function get_stock_quantity(): ?int { return $this->data['stock_quantity'] ?? null; }
		public function set_stock_quantity( ?int $quantity ): void { $this->data['stock_quantity'] = $quantity; }
		public function set_manage_stock( bool $manage ): void {}
		public function get_weight(): string { return $this->data['weight'] ?? ''; }
		public function set_weight( string $weight ): void { $this->data['weight'] = $weight; }
		public function get_description(): string { return $this->data['description'] ?? ''; }
		public function set_description( string $description ): void { $this->data['description'] = $description; }
		public function save(): int { return $this->id ?: 1; }
		public function delete( bool $force = false ): bool { return true; }
	}
}

if ( ! class_exists( 'WC_Product_Variable' ) ) {
	class WC_Product_Variable extends WC_Product {
		public function __construct( int $id = 0 ) { $this->id = $id; }
		public function set_attributes( $attributes ): void {}
	}
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	class WC_Product_Variation extends WC_Product {
		protected int $parent_id = 0;
		protected array $attrs = [];
		public function set_parent_id( int $parent_id ): void { $this->parent_id = $parent_id; }
		public function set_attributes( $attributes ): void { $this->attrs = is_array( $attributes ) ? $attributes : []; }
	}
}

if ( ! class_exists( 'WC_Product_Attribute' ) ) {
	class WC_Product_Attribute {
		private string $name = '';
		private array $options = [];
		private bool $visible = true;
		private bool $variation = false;
		private int $position = 0;
		public function set_name( string $name ): void { $this->name = $name; }
		public function set_options( array $options ): void { $this->options = $options; }
		public function set_visible( bool $visible ): void { $this->visible = $visible; }
		public function set_variation( bool $variation ): void { $this->variation = $variation; }
		public function set_position( int $position ): void { $this->position = $position; }
		public function get_name(): string { return $this->name; }
		public function get_options(): array { return $this->options; }
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		protected int $id = 0;
		protected array $data = [];
		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}
		public function get_id(): int { return $this->id; }
		public function get_total(): string { return $this->data['total'] ?? '0.00'; }
		public function get_date_created(): ?\DateTimeImmutable {
			return isset( $this->data['date_created'] ) ? new \DateTimeImmutable( $this->data['date_created'] ) : null;
		}
		public function get_status(): string { return $this->data['status'] ?? 'pending'; }
		public function set_status( string $status ): void { $this->data['status'] = $status; }
		public function get_billing_email(): string { return $this->data['billing_email'] ?? ''; }
		public function get_formatted_billing_full_name(): string { return $this->data['billing_name'] ?? ''; }
		public function get_customer_id(): int { return $this->data['customer_id'] ?? 0; }
		public function save(): int { return $this->id ?: 1; }
		/** @param array<string, mixed> $data */
		public function set_data( array $data ): void { $this->data = $data; }
	}
}
