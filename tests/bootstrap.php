<?php
/**
 * PHPUnit bootstrap file.
 *
 * Provides minimal WordPress function stubs so that plugin classes
 * can be loaded and tested without a full WordPress environment.
 *
 * @package WP4Odoo\Tests
 */

namespace {

	// Stub ABSPATH so includes don't call exit().
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/stub/' );
	}

	// Plugin root directory.
	define( 'WP4ODOO_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	// ─── WordPress constants ────────────────────────────────

	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	// ─── WordPress function stubs ────────────────────────────

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $options = 0, $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $option, $default = false ) {
			return $default;
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( (string) $str );
		}
	}

	if ( ! function_exists( 'absint' ) ) {
		function absint( $maybeint ) {
			return abs( (int) $maybeint );
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type = 'mysql', $gmt = false ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
	}

	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $transient ) {
			return false;
		}
	}

	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $transient, $value, $expiration = 0 ) {
			return true;
		}
	}

	if ( ! function_exists( 'delete_transient' ) ) {
		function delete_transient( $transient ) {
			return true;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value, ...$args ) {
			return $value;
		}
	}

	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( $title ) {
			return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '-', trim( (string) $title ) ) );
		}
	}

	if ( ! function_exists( 'wp_set_object_terms' ) ) {
		function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
			return [ 1 ];
		}
	}

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

	// ─── WordPress post meta / media stubs ──────────────────

	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( $post_id, $key = '', $single = false ) {
			return $single ? '' : [];
		}
	}

	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( $post_id, $meta_key, $meta_value, $prev_value = '' ) {
			return true;
		}
	}

	if ( ! function_exists( 'delete_post_meta' ) ) {
		function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
			return true;
		}
	}

	if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
		function get_post_thumbnail_id( $post = null ) {
			return 0;
		}
	}

	if ( ! function_exists( 'set_post_thumbnail' ) ) {
		function set_post_thumbnail( $post, $thumbnail_id ) {
			return true;
		}
	}

	if ( ! function_exists( 'delete_post_thumbnail' ) ) {
		function delete_post_thumbnail( $post ) {
			return true;
		}
	}

	if ( ! function_exists( 'wp_delete_attachment' ) ) {
		function wp_delete_attachment( $post_id, $force_delete = false ) {
			return true;
		}
	}

	if ( ! function_exists( 'wp_upload_dir' ) ) {
		function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
			return [
				'path'    => sys_get_temp_dir(),
				'url'     => 'http://example.com/wp-content/uploads',
				'subdir'  => '',
				'basedir' => sys_get_temp_dir(),
				'baseurl' => 'http://example.com/wp-content/uploads',
				'error'   => false,
			];
		}
	}

	if ( ! function_exists( 'trailingslashit' ) ) {
		function trailingslashit( $value ) {
			return rtrim( $value, '/\\' ) . '/';
		}
	}

	if ( ! function_exists( 'wp_insert_attachment' ) ) {
		function wp_insert_attachment( $args, $file = false, $parent_post_id = 0 ) {
			static $id = 1000;
			return ++$id;
		}
	}

	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
		function wp_generate_attachment_metadata( $attachment_id, $file ) {
			return [];
		}
	}

	if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
		function wp_update_attachment_metadata( $attachment_id, $data ) {
			return true;
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ) {
			return false;
		}
	}

	// ─── WooCommerce class stubs ────────────────────────────

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

	// ─── wpdb stub ──────────────────────────────────────────

	/**
	 * Minimal wpdb stub for unit tests.
	 *
	 * Records method calls and returns configurable values.
	 */
	class WP_DB_Stub {

		public string $prefix = 'wp_';
		public int $insert_id = 0;

		/** @var mixed */
		public $get_var_return = null;
		public array $get_results_return = [];
		public int $delete_return = 1;
		public int $query_return = 1;

		/** @var array<int, array{method: string, args: array}> */
		public array $calls = [];

		public function prepare( string $query, ...$args ): string {
			$this->calls[] = [ 'method' => 'prepare', 'args' => [ $query, ...$args ] ];
			return $query;
		}

		/** @return mixed */
		public function get_var( $query ) {
			$this->calls[] = [ 'method' => 'get_var', 'args' => [ $query ] ];
			return $this->get_var_return;
		}

		public function get_results( $query ): array {
			$this->calls[] = [ 'method' => 'get_results', 'args' => [ $query ] ];
			return $this->get_results_return;
		}

		/** @return int|false */
		public function insert( string $table, array $data ) {
			$this->calls[] = [ 'method' => 'insert', 'args' => [ $table, $data ] ];
			return 1;
		}

		/** @return int|false */
		public function update( string $table, array $data, array $where ) {
			$this->calls[] = [ 'method' => 'update', 'args' => [ $table, $data, $where ] ];
			return 1;
		}

		/** @return int|false */
		public function replace( string $table, array $data ) {
			$this->calls[] = [ 'method' => 'replace', 'args' => [ $table, $data ] ];
			return 1;
		}

		/** @return int|false */
		public function delete( string $table, array $where, ?array $format = null ) {
			$this->calls[] = [ 'method' => 'delete', 'args' => [ $table, $where, $format ] ];
			return $this->delete_return;
		}

		/** @return int|false */
		public function query( string $query ) {
			$this->calls[] = [ 'method' => 'query', 'args' => [ $query ] ];
			return $this->query_return;
		}

		public function reset(): void {
			$this->calls      = [];
			$this->get_var_return     = null;
			$this->get_results_return = [];
			$this->delete_return      = 1;
			$this->query_return       = 1;
			$this->insert_id          = 0;
		}
	}

}

// ─── Minimal Logger stub ─────────────────────────────────

namespace WP4Odoo {

	class Logger {
		private string $module;
		public function __construct( string $module = '' ) {
			$this->module = $module;
		}
		public function debug( string $message, array $context = [] ): void {}
		public function info( string $message, array $context = [] ): void {}
		public function warning( string $message, array $context = [] ): void {}
		public function error( string $message, array $context = [] ): void {}
		public function critical( string $message, array $context = [] ): void {}
	}

}

// ─── Load classes under test ─────────────────────────────

namespace {

	// Composer autoloader (for PHPUnit + test classes).
	require_once WP4ODOO_PLUGIN_DIR . 'vendor/autoload.php';

	// Plugin classes (WordPress-convention filenames, not PSR-4).
	require_once WP4ODOO_PLUGIN_DIR . 'includes/class-field-mapper.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/class-cpt-helper.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/class-entity-map-repository.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/class-sync-queue-repository.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/class-partner-service.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/class-module-base.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/class-sync-engine.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/class-queue-manager.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-currency-guard.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-variant-handler.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-image-handler.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-product-handler.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-order-handler.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-woocommerce-module.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-portal-manager.php';
	require_once WP4ODOO_PLUGIN_DIR . 'includes/modules/class-sales-module.php';

}
