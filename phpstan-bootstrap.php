<?php
/**
 * PHPStan bootstrap — defines plugin constants and stubs
 * that are normally set up by the main wp4odoo.php entry point.
 *
 * @package WP4Odoo
 */

define( 'WP4ODOO_VERSION', '3.2.0' );
define( 'WP4ODOO_PLUGIN_FILE', __DIR__ . '/wp4odoo.php' );
define( 'WP4ODOO_PLUGIN_DIR', __DIR__ . '/' );
define( 'WP4ODOO_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp4odoo/' );
define( 'WP4ODOO_PLUGIN_BASENAME', 'wp4odoo/wp4odoo.php' );
define( 'WP4ODOO_MIN_ODOO_VERSION', 14 );
define( 'WPINC', 'wp-includes' );

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Plugin singleton stub — shared with unit tests (single source of truth).
require_once __DIR__ . '/tests/stubs/plugin-stub.php';

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
		public function get_id(): int { return 0; }
		public function get_name(): string { return ''; }
		public function set_name( string $name ): void {}
		public function get_type(): string { return 'simple'; }
		public function get_price(): string { return ''; }
		public function get_sku(): string { return ''; }
		public function set_sku( string $sku ): void {}
		public function get_regular_price(): string { return ''; }
		public function set_regular_price( string $price ): void {}
		public function get_sale_price(): string { return ''; }
		/** @param string|int|float $price */
		public function set_sale_price( $price ): void {}
		public function get_stock_quantity(): ?int { return null; }
		public function set_stock_quantity( ?int $quantity ): void {}
		public function managing_stock(): bool { return false; }
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
		public function get_id(): int { return 0; }
		public function get_total(): string { return '0'; }
		public function get_date_created(): ?\WC_DateTime { return null; }
		public function get_status(): string { return ''; }
		public function set_status( string $status ): void {}
		public function get_billing_email(): string { return ''; }
		public function get_formatted_billing_full_name(): string { return ''; }
		public function get_customer_id(): int { return 0; }
		/** @return WC_Order_Item[] */
		public function get_items( string $type = '' ): array { return []; }
		/** @return WC_Order_Item_Shipping[] */
		public function get_shipping_methods(): array { return []; }
		/** @param mixed $value */
		public function update_meta_data( string $key, $value ): void {}
		/**
		 * @param string $key
		 * @param bool   $single
		 * @return mixed
		 */
		public function get_meta( string $key, bool $single = true ) { return ''; }
		public function get_parent_id(): int { return 0; }
		public function add_order_note( string $note ): int { return 0; }
		public function save(): int { return 0; }
	}

	class WC_Order_Item {
		public function get_name(): string { return ''; }
		public function get_quantity(): int { return 1; }
		public function get_total(): string { return '0.00'; }
		public function get_tax_class(): string { return ''; }
		public function get_product_id(): int { return 0; }
	}

	class WC_Order_Item_Tax {
		public function get_rate_id(): int { return 0; }
		public function get_label(): string { return ''; }
		public function get_tax_total(): string { return '0.00'; }
		public function get_rate_code(): string { return ''; }
	}

	class WC_Order_Item_Shipping {
		public function get_method_id(): string { return ''; }
		public function get_method_title(): string { return ''; }
		public function get_total(): string { return '0.00'; }
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

// ─── WC Memberships stubs ───────────────────────────────

if ( ! function_exists( 'wc_memberships' ) ) {
	/** @return object */
	function wc_memberships() {
		return new stdClass();
	}
}

if ( ! function_exists( 'wc_memberships_get_user_membership' ) ) {
	/**
	 * @param int $membership_id
	 * @return WC_Memberships_User_Membership|false
	 */
	function wc_memberships_get_user_membership( $membership_id = 0 ) {
		return false;
	}
}

if ( ! function_exists( 'wc_memberships_get_membership_plan' ) ) {
	/**
	 * @param int $plan_id
	 * @return WC_Memberships_Membership_Plan|false
	 */
	function wc_memberships_get_membership_plan( $plan_id = 0 ) {
		return false;
	}
}

if ( ! class_exists( 'WC_Memberships_User_Membership' ) ) {
	class WC_Memberships_User_Membership {
		public function get_id(): int { return 0; }
		public function get_plan_id(): int { return 0; }
		public function get_plan(): ?WC_Memberships_Membership_Plan { return null; }
		public function get_user_id(): int { return 0; }
		public function get_status(): string { return ''; }
		public function get_start_date( string $format = 'Y-m-d H:i:s' ): string { return ''; }
		public function get_end_date( string $format = 'Y-m-d H:i:s' ): string { return ''; }
		public function get_cancelled_date( string $format = 'Y-m-d H:i:s' ): string { return ''; }
		public function get_paused_date( string $format = 'Y-m-d H:i:s' ): string { return ''; }
		public function get_order_id(): int { return 0; }
		public function get_product_id(): int { return 0; }
	}
}

if ( ! class_exists( 'WC_Memberships_Membership_Plan' ) ) {
	class WC_Memberships_Membership_Plan {
		public function get_id(): int { return 0; }
		public function get_name(): string { return ''; }
		/** @return int[] */
		public function get_product_ids(): array { return []; }
		public function get_access_length_amount(): int { return 0; }
		public function get_access_length_period(): string { return ''; }
	}
}

// ─── Gravity Forms / WPForms stubs ──────────────────────

if ( ! class_exists( 'GFAPI' ) ) {
	class GFAPI {
		/** @param int $entry_id */
		public static function get_entry( int $entry_id ): array { return []; }
		/** @param int $form_id */
		public static function get_form( int $form_id ): array { return []; }
	}
}

if ( ! class_exists( 'GF_Field' ) ) {
	class GF_Field {
		public string $type  = '';
		public int    $id    = 0;
		public string $label = '';
	}
}

if ( ! function_exists( 'wpforms' ) ) {
	/** @return object */
	function wpforms() { return new stdClass(); }
}

// ─── Contact Form 7 stubs ───────────────────────────────

if ( ! defined( 'WPCF7_VERSION' ) ) {
	define( 'WPCF7_VERSION', '6.0' );
}

if ( ! class_exists( 'WPCF7_FormTag' ) ) {
	class WPCF7_FormTag {
		public string $basetype = '';
		public string $name     = '';
	}
}

if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	class WPCF7_ContactForm {
		public function title(): string { return ''; }
		/** @return WPCF7_FormTag[] */
		public function scan_form_tags(): array { return []; }
	}
}

if ( ! class_exists( 'WPCF7_Submission' ) ) {
	class WPCF7_Submission {
		public static function get_instance(): ?self { return null; }
		/** @return array<string, string> */
		public function get_posted_data(): array { return []; }
	}
}

// ─── Fluent Forms stubs ─────────────────────────────────

if ( ! defined( 'FLUENTFORM' ) ) {
	define( 'FLUENTFORM', true );
}

// ─── Formidable Forms stubs ─────────────────────────────

if ( ! class_exists( 'FrmAppHelper' ) ) {
	class FrmAppHelper {}
}

if ( ! class_exists( 'FrmField' ) ) {
	class FrmField {
		/** @return array */
		public static function getAll( array $conditions = [] ): array { return []; }
	}
}

if ( ! class_exists( 'FrmEntryMeta' ) ) {
	class FrmEntryMeta {
		/** @return array */
		public static function getAll( array $conditions = [] ): array { return []; }
	}
}

if ( ! class_exists( 'FrmForm' ) ) {
	class FrmForm {
		public string $name = '';
		public static function getOne( int $form_id ): ?self { return null; }
	}
}

// ─── Ninja Forms stubs ──────────────────────────────────

if ( ! class_exists( 'Ninja_Forms' ) ) {
	class Ninja_Forms {}
}

// ─── Forminator stubs ───────────────────────────────────

if ( ! defined( 'FORMINATOR_VERSION' ) ) {
	define( 'FORMINATOR_VERSION', '1.34.0' );
}

// ─── EDD stubs ──────────────────────────────────────────

if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
	class Easy_Digital_Downloads {}
}

if ( ! class_exists( 'EDD_Download' ) ) {
	class EDD_Download {
		/** @var int */
		public int $ID = 0;
		public function get_price(): string { return ''; }
		public function get_ID(): int { return 0; }
	}
}

if ( ! class_exists( 'EDD_Customer' ) ) {
	class EDD_Customer {
		public int $id      = 0;
		public string $email = '';
		public string $name  = '';
		public int $user_id  = 0;
	}
}

// EDD\Orders namespace stub loaded from separate file (PHP namespace rules).
require_once __DIR__ . '/phpstan-edd-stubs.php';

// ─── MemberPress stubs ──────────────────────────────────

if ( ! defined( 'MEPR_VERSION' ) ) {
	define( 'MEPR_VERSION', '1.0.0' );
}

if ( ! class_exists( 'MeprProduct' ) ) {
	class MeprProduct {
		public int $ID = 0;
		public string $post_title = '';
		public string $price = '0.00';
		public function __construct( int $id = 0 ) { $this->ID = $id; }
		public function get_price(): string { return $this->price; }
	}
}

if ( ! class_exists( 'MeprTransaction' ) ) {
	class MeprTransaction {
		public int $id = 0;
		public int $user_id = 0;
		public int $product_id = 0;
		public float $amount = 0.0;
		public string $trans_num = '';
		public string $created_at = '';
		public string $status = '';
		public int $subscription_id = 0;
		public function __construct( int $id = 0 ) { $this->id = $id; }
	}
}

if ( ! class_exists( 'MeprSubscription' ) ) {
	class MeprSubscription {
		public int $id = 0;
		public int $user_id = 0;
		public int $product_id = 0;
		public string $price = '0.00';
		public string $status = '';
		public string $created_at = '';
		public function __construct( int $id = 0 ) { $this->id = $id; }
	}
}

// ─── PMPro stubs ────────────────────────────────────────

if ( ! defined( 'PMPRO_VERSION' ) ) {
	define( 'PMPRO_VERSION', '3.4.1' );
}

if ( ! function_exists( 'pmpro_getLevel' ) ) {
	/**
	 * @param int $level_id
	 * @return PMPro_Membership_Level|false
	 */
	function pmpro_getLevel( int $level_id = 0 ) {
		return false;
	}
}

if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
	/**
	 * @param int $user_id
	 * @return PMPro_Membership_Level|false
	 */
	function pmpro_getMembershipLevelForUser( int $user_id = 0 ) {
		return false;
	}
}

if ( ! class_exists( 'PMPro_Membership_Level' ) ) {
	class PMPro_Membership_Level {
		public int $id = 0;
		public string $name = '';
		public string $description = '';
		public string $initial_payment = '0.00';
		public string $billing_amount = '0.00';
		public int $cycle_number = 0;
		public string $cycle_period = '';
		public int $billing_limit = 0;
		public string $trial_amount = '0.00';
		public int $trial_limit = 0;
		public int $expiration_number = 0;
		public string $expiration_period = '';
		public bool $allow_signups = true;
	}
}

if ( ! class_exists( 'MemberOrder' ) ) {
	class MemberOrder {
		public int $id = 0;
		public string $code = '';
		public int $user_id = 0;
		public int $membership_id = 0;
		public string $subtotal = '0.00';
		public string $tax = '0.00';
		public string $total = '0.00';
		public string $status = '';
		public string $gateway = '';
		public string $payment_transaction_id = '';
		public string $subscription_transaction_id = '';
		public string $notes = '';
		public string $timestamp = '';
		public function __construct( int $id = 0 ) { $this->id = $id; }
		/** @return PMPro_Membership_Level|false */
		public function getMembershipLevel() { return pmpro_getLevel( $this->membership_id ); }
		/** @return WP_User|false */
		public function getUser() { return get_userdata( $this->user_id ); }
	}
}

// ─── RCP stubs ──────────────────────────────────────────

if ( ! function_exists( 'rcp_get_membership' ) ) {
	/**
	 * @param int $membership_id
	 * @return RCP_Membership|false
	 */
	function rcp_get_membership( int $membership_id = 0 ) {
		return false;
	}
}

if ( ! function_exists( 'rcp_get_membership_level' ) ) {
	/**
	 * @param int $level_id
	 * @return object|false
	 */
	function rcp_get_membership_level( int $level_id = 0 ) {
		return false;
	}
}

if ( ! function_exists( 'rcp_get_customer_by_user_id' ) ) {
	/**
	 * @param int $user_id
	 * @return RCP_Customer|false
	 */
	function rcp_get_customer_by_user_id( int $user_id = 0 ) {
		return false;
	}
}

if ( ! class_exists( 'RCP_Membership' ) ) {
	class RCP_Membership {
		public function get_id(): int { return 0; }
		public function get_customer_id(): int { return 0; }
		/** @return RCP_Customer|false */
		public function get_customer() { return false; }
		public function get_object_id(): int { return 0; }
		public function get_status(): string { return ''; }
		public function get_created_date(): string { return ''; }
		public function get_expiration_date( bool $formatted = true ): string { return ''; }
		public function get_initial_amount( bool $formatted = false ): float { return 0.0; }
		public function get_recurring_amount( bool $formatted = false ): float { return 0.0; }
		public function is_recurring(): bool { return false; }
		public function is_active(): bool { return false; }
		public function is_expired(): bool { return false; }
		public function get_times_billed(): int { return 0; }
	}
}

if ( ! class_exists( 'RCP_Customer' ) ) {
	class RCP_Customer {
		public function get_id(): int { return 0; }
		public function get_user_id(): int { return 0; }
	}
}

if ( ! class_exists( 'RCP_Payments' ) ) {
	class RCP_Payments {
		/** @return object|null */
		public function get_payment( int $payment_id ) { return null; }
	}
}

// ─── GiveWP stubs ───────────────────────────────────────

if ( ! defined( 'GIVE_VERSION' ) ) {
	define( 'GIVE_VERSION', '3.0.0' );
}

if ( ! class_exists( 'Give' ) ) {
	class Give {
		public static function instance(): self { return new self(); }
	}
}

if ( ! function_exists( 'give' ) ) {
	/** @return Give */
	function give(): Give { return Give::instance(); }
}

// ─── WP Charitable stubs ────────────────────────────────

if ( ! class_exists( 'Charitable' ) ) {
	class Charitable {
		public static function instance(): self { return new self(); }
	}
}

// ─── WP Simple Pay stubs ────────────────────────────────

if ( ! defined( 'SIMPLE_PAY_VERSION' ) ) {
	define( 'SIMPLE_PAY_VERSION', '4.16.1' );
}

// ─── Sprout Invoices stubs ──────────────────────────────

if ( ! class_exists( 'SI_Post_Type' ) ) {
	class SI_Post_Type {
		public int $id = 0;
	}
}

if ( ! class_exists( 'SI_Invoice' ) ) {
	class SI_Invoice extends SI_Post_Type {
		public static function get_instance( int $id ): self {
			$instance = new self();
			$instance->id = $id;
			return $instance;
		}
	}
}

if ( ! class_exists( 'SI_Payment' ) ) {
	class SI_Payment extends SI_Post_Type {
		public static function get_instance( int $id ): self {
			$instance = new self();
			$instance->id = $id;
			return $instance;
		}
	}
}

// ─── WP-Invoice stubs ───────────────────────────────────

if ( ! class_exists( 'WPI_Invoice' ) ) {
	class WPI_Invoice {
		/** @var array<string, mixed> */
		public array $data = [];
		public function load_invoice( string $args ): void {}
	}
}

// ─── WP Crowdfunding stubs ──────────────────────────────

if ( ! function_exists( 'wpneo_crowdfunding_init' ) ) {
	function wpneo_crowdfunding_init(): void {}
}

// ─── Ecwid stubs ────────────────────────────────────────

if ( ! defined( 'ECWID_PLUGIN_DIR' ) ) {
	define( 'ECWID_PLUGIN_DIR', '/tmp/ecwid/' );
}

// ─── ShopWP stubs ───────────────────────────────────────

if ( ! defined( 'SHOPWP_PLUGIN_DIR' ) ) {
	define( 'SHOPWP_PLUGIN_DIR', '/tmp/shopwp/' );
}

// ─── WP Recipe Maker stubs ──────────────────────────────

if ( ! defined( 'WPRM_VERSION' ) ) {
	define( 'WPRM_VERSION', '10.3.2' );
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
		/** @param array<string, mixed> $assoc_args */
		public static function confirm( string $message, array $assoc_args = [] ): void {}
	}
}

// ─── Amelia stubs ───────────────────────────────────────

if ( ! defined( 'AMELIA_VERSION' ) ) {
	define( 'AMELIA_VERSION', '1.2.37' );
}

// Bookly namespace stub loaded from separate file (PHP namespace rules).
require_once __DIR__ . '/phpstan-bookly-stubs.php';

// ─── LearnDash stubs ────────────────────────────────────

if ( ! defined( 'LEARNDASH_VERSION' ) ) {
	define( 'LEARNDASH_VERSION', '4.18.0' );
}

if ( ! function_exists( 'learndash_get_course_price' ) ) {
	/**
	 * @param int $course_id
	 * @return array{type: string, price: string}
	 */
	function learndash_get_course_price( int $course_id = 0 ): array {
		return [ 'type' => 'free', 'price' => '' ];
	}
}

if ( ! function_exists( 'learndash_get_group_price' ) ) {
	/**
	 * @param int $group_id
	 * @return array{type: string, price: string}
	 */
	function learndash_get_group_price( int $group_id = 0 ): array {
		return [ 'type' => 'free', 'price' => '' ];
	}
}

if ( ! function_exists( 'learndash_get_setting' ) ) {
	/**
	 * @param int    $post_id
	 * @param string $key
	 * @return mixed
	 */
	function learndash_get_setting( int $post_id = 0, string $key = '' ) {
		return '';
	}
}

if ( ! function_exists( 'learndash_user_get_course_date' ) ) {
	/**
	 * @param int $user_id
	 * @param int $course_id
	 * @return string
	 */
	function learndash_user_get_course_date( int $user_id = 0, int $course_id = 0 ): string {
		return '';
	}
}

// ─── WC Subscriptions stubs ─────────────────────────────

if ( ! class_exists( 'WC_Subscriptions' ) ) {
	class WC_Subscriptions {
		public static string $version = '6.0.0';
	}
}

if ( ! class_exists( 'WC_Subscription' ) ) {
	class WC_Subscription extends WC_Order {
		public function get_billing_period(): string { return 'month'; }
		public function get_billing_interval(): int { return 1; }
		public function get_date( string $type ): string { return ''; }
		public function get_parent_id(): int { return 0; }
		/** @return WC_Order_Item[] */
		public function get_items( string $type = '' ): array { return []; }
		public function get_user_id(): int { return 0; }
		public function update_status( string $new_status ): void {}
	}
}

if ( ! function_exists( 'wcs_get_subscription' ) ) {
	/**
	 * @param int $subscription_id
	 * @return WC_Subscription|false
	 */
	function wcs_get_subscription( int $subscription_id = 0 ) {
		return false;
	}
}

// ─── LifterLMS stubs ────────────────────────────────────

if ( ! defined( 'LLMS_VERSION' ) ) {
	define( 'LLMS_VERSION', '7.8.5' );
}

if ( ! class_exists( 'LLMS_Order' ) ) {
	class LLMS_Order {
		public function __construct( int $id = 0 ) {}
		public function get( string $key ): string { return ''; }
		public function get_id(): int { return 0; }
		public function get_product_id(): int { return 0; }
		public function get_customer_id(): int { return 0; }
		public function get_total(): float { return 0.0; }
		public function get_status(): string { return ''; }
		public function get_date( string $key = '' ): string { return ''; }
		public function get_payment_gateway(): string { return ''; }
	}
}

if ( ! class_exists( 'LLMS_Student' ) ) {
	class LLMS_Student {
		public function __construct( int $id = 0 ) {}
		public function get_id(): int { return 0; }
		public function is_enrolled( int $product_id = 0 ): bool { return false; }
		public function get_enrollment_status( int $product_id = 0 ): string { return ''; }
		public function get_enrollment_date( int $product_id = 0, string $format = 'Y-m-d H:i:s' ): string { return ''; }
	}
}

if ( ! function_exists( 'llms_get_student' ) ) {
	/**
	 * @param int $user_id
	 * @return LLMS_Student|false
	 */
	function llms_get_student( int $user_id = 0 ) {
		return false;
	}
}

// ─── The Events Calendar + Event Tickets stubs ─────────

if ( ! class_exists( 'Tribe__Events__Main' ) ) {
	class Tribe__Events__Main {
		const VERSION             = '6.8.0';
		const POSTTYPE            = 'tribe_events';
		const VENUE_POST_TYPE     = 'tribe_venue';
		const ORGANIZER_POST_TYPE = 'tribe_organizer';
		public static string $version = '6.8.0';
	}
}

if ( ! class_exists( 'Tribe__Tickets__Main' ) ) {
	class Tribe__Tickets__Main {
		public static string $version = '5.14.0';
	}
}

// ─── WC Bookings stubs ──────────────────────────────────

if ( ! class_exists( 'WC_Booking' ) ) {
	class WC_Booking {
		public function __construct( int $id = 0 ) {}
		public function get_id(): int { return 0; }
		public function get_product_id(): int { return 0; }
		public function get_start_date( string $format = 'Y-m-d H:i:s' ): string { return ''; }
		public function get_end_date( string $format = 'Y-m-d H:i:s' ): string { return ''; }
		public function get_status(): string { return ''; }
		public function is_all_day(): bool { return false; }
		/** @return array<int, int> */
		public function get_persons(): array { return []; }
		public function get_persons_total(): int { return 0; }
		public function get_customer_id(): int { return 0; }
		public function get_order_id(): int { return 0; }
		public function get_cost(): float { return 0.0; }
	}
}

if ( ! class_exists( 'WC_Product_Booking' ) ) {
	class WC_Product_Booking extends WC_Product {
		public function get_type(): string { return 'booking'; }
		public function get_duration(): int { return 1; }
		public function get_duration_unit(): string { return 'hour'; }
		public function get_base_cost(): string { return '0'; }
	}
}

// ─── WP Job Manager stubs ────────────────────────────────

if ( ! defined( 'JOB_MANAGER_VERSION' ) ) {
	define( 'JOB_MANAGER_VERSION', '2.4.0' );
}

// ─── ACF stubs ──────────────────────────────────────────

if ( ! defined( 'ACF_MAJOR_VERSION' ) ) {
	define( 'ACF_MAJOR_VERSION', 6 );
}

if ( ! class_exists( 'ACF' ) ) {
	class ACF {}
}

if ( ! function_exists( 'get_field' ) ) {
	function get_field( string $selector, $post_id = false ) { return null; }
}

if ( ! function_exists( 'update_field' ) ) {
	function update_field( string $selector, $value, $post_id = false ): bool { return true; }
}

// ─── WC Points & Rewards stubs ──────────────────────

if ( ! class_exists( 'WC_Points_Rewards' ) ) {
	class WC_Points_Rewards {
		public static string $version = '1.7.0';
	}
}

if ( ! class_exists( 'WC_Points_Rewards_Manager' ) ) {
	class WC_Points_Rewards_Manager {
		public static function get_users_points( int $user_id ): int { return 0; }
		public static function set_points_balance( int $user_id, int $points, string $type = 'admin-adjustment' ): void {}
		/** @param mixed $data */
		public static function increase_points( int $user_id, int $points, string $type = 'order-placed', $data = null, int $order_id = 0 ): void {}
		/** @param mixed $data */
		public static function decrease_points( int $user_id, int $points, string $type = 'order-redeem', $data = null, int $order_id = 0 ): void {}
	}
}

// ─── Awesome Support stubs ──────────────────────────────

if ( ! defined( 'WPAS_VERSION' ) ) {
	define( 'WPAS_VERSION', '6.2.6' );
}

if ( ! function_exists( 'wpas_insert_ticket' ) ) {
	/**
	 * @param array  $data Ticket data.
	 * @param int    $user_id User ID.
	 * @param string $status Status.
	 * @return int|WP_Error
	 */
	function wpas_insert_ticket( array $data = [], int $user_id = 0, string $status = 'queued' ) {
		return 0;
	}
}

if ( ! function_exists( 'wpas_update_ticket_status' ) ) {
	/**
	 * @param int    $ticket_id Ticket ID.
	 * @param string $new_status New status.
	 * @return bool
	 */
	function wpas_update_ticket_status( int $ticket_id, string $new_status ): bool {
		return true;
	}
}

if ( ! function_exists( 'wpas_get_ticket_status' ) ) {
	/**
	 * @param int $ticket_id Ticket ID.
	 * @return string
	 */
	function wpas_get_ticket_status( int $ticket_id ): string {
		return 'open';
	}
}

// ─── SupportCandy stubs ─────────────────────────────────

if ( ! defined( 'WPSC_VERSION' ) ) {
	define( 'WPSC_VERSION', '3.2.8' );
}

// ─── WC Product Bundles / Composite Products stubs ──────

if ( ! class_exists( 'WC_Bundles' ) ) {
	class WC_Bundles {}
}

if ( ! class_exists( 'WC_Composite_Products' ) ) {
	class WC_Composite_Products {}
}

if ( ! class_exists( 'WC_Product_Bundle' ) ) {
	class WC_Product_Bundle extends WC_Product {
		public function get_type(): string { return 'bundle'; }
		/** @return WC_Bundled_Item[] */
		public function get_bundled_items(): array { return []; }
	}
}

if ( ! class_exists( 'WC_Bundled_Item' ) ) {
	class WC_Bundled_Item {
		public function get_product_id(): int { return 0; }
		public function get_quantity( string $context = 'min' ): int { return 1; }
		public function is_optional(): bool { return false; }
	}
}

if ( ! class_exists( 'WC_Product_Composite' ) ) {
	class WC_Product_Composite extends WC_Product {
		public function get_type(): string { return 'composite'; }
		/** @return array<int, array<string, mixed>> */
		public function get_composite_data(): array { return []; }
	}
}

// ─── AffiliateWP stubs ──────────────────────────────────

if ( ! defined( 'AFFILIATEWP_VERSION' ) ) {
	define( 'AFFILIATEWP_VERSION', '2.25.3' );
}

if ( ! function_exists( 'affiliate_wp' ) ) {
	/** @return stdClass */
	function affiliate_wp() { return new stdClass(); }
}

if ( ! class_exists( 'AffWP_Affiliate' ) ) {
	class AffWP_Affiliate {
		public int $affiliate_id = 0;
		public int $user_id = 0;
		public string $payment_email = '';
		public string $status = 'active';
		public string $rate_type = 'percentage';
		public float $rate = 20.0;
		public float $earnings = 0.0;
		public float $unpaid_earnings = 0.0;
		public int $referrals = 0;
		public int $visits = 0;
		public string $date_registered = '';
	}
}

if ( ! class_exists( 'AffWP_Referral' ) ) {
	class AffWP_Referral {
		public int $referral_id = 0;
		public int $affiliate_id = 0;
		public int $visit_id = 0;
		public float $amount = 0.0;
		public string $currency = 'USD';
		public string $status = 'pending';
		public string $description = '';
		public string $reference = '';
		public string $context = '';
		public string $campaign = '';
		public string $date = '';
		public int $payout_id = 0;
	}
}

if ( ! function_exists( 'affwp_get_affiliate' ) ) {
	/**
	 * @param int $affiliate_id
	 * @return AffWP_Affiliate|false
	 */
	function affwp_get_affiliate( $affiliate_id = 0 ) { return false; }
}

if ( ! function_exists( 'affwp_get_referral' ) ) {
	/**
	 * @param int $referral_id
	 * @return AffWP_Referral|false
	 */
	function affwp_get_referral( $referral_id = 0 ) { return false; }
}

// ─── Dokan stubs ─────────────────────────────────────────

if ( ! defined( 'DOKAN_PLUGIN_VERSION' ) ) {
	define( 'DOKAN_PLUGIN_VERSION', '4.0.0' );
}

if ( ! function_exists( 'dokan' ) ) {
	/** @return stdClass */
	function dokan() { return new stdClass(); }
}

if ( ! function_exists( 'dokan_get_seller_id_by_order' ) ) {
	/**
	 * @param int $order_id
	 * @return int
	 */
	function dokan_get_seller_id_by_order( $order_id ) { return 0; }
}

if ( ! function_exists( 'dokan_get_earning_by_order' ) ) {
	/**
	 * @param int    $order_id
	 * @param string $context
	 * @return float
	 */
	function dokan_get_earning_by_order( $order_id, $context = 'seller' ) { return 0.0; }
}

if ( ! function_exists( 'dokan_get_withdraw' ) ) {
	/**
	 * @param int $withdraw_id
	 * @return object|false
	 */
	function dokan_get_withdraw( $withdraw_id ) { return false; }
}

// ─── WCFM stubs ──────────────────────────────────────────

if ( ! defined( 'WCFM_VERSION' ) ) {
	define( 'WCFM_VERSION', '6.7.0' );
}

if ( ! function_exists( 'wcfm_get_vendor_store_name' ) ) {
	/**
	 * @param int $vendor_id
	 * @return string
	 */
	function wcfm_get_vendor_store_name( $vendor_id ) { return ''; }
}

if ( ! function_exists( 'wcfm_get_vendor_id_by_order' ) ) {
	/**
	 * @param int $order_id
	 * @return int
	 */
	function wcfm_get_vendor_id_by_order( $order_id ) { return 0; }
}

if ( ! function_exists( 'wcfm_get_commission' ) ) {
	/**
	 * @param int $commission_id
	 * @return object|false
	 */
	function wcfm_get_commission( $commission_id ) { return false; }
}

if ( ! function_exists( 'wcfm_get_withdrawal' ) ) {
	/**
	 * @param int $withdrawal_id
	 * @return object|false
	 */
	function wcfm_get_withdrawal( $withdrawal_id ) { return false; }
}

// ─── WPML stubs ─────────────────────────────────────────

if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
	define( 'ICL_SITEPRESS_VERSION', '4.7.0' );
}

if ( ! class_exists( 'SitePress' ) ) {
	class SitePress {
		public function get_default_language(): string { return 'en'; }
	}
}

// ─── Polylang stubs ─────────────────────────────────────

if ( ! defined( 'POLYLANG_VERSION' ) ) {
	define( 'POLYLANG_VERSION', '3.6.0' );
}

if ( ! function_exists( 'pll_default_language' ) ) {
	function pll_default_language(): string { return 'en'; }
}

if ( ! function_exists( 'pll_languages_list' ) ) {
	/** @return array<int, string> */
	function pll_languages_list( array $args = [] ): array { return []; }
}

if ( ! function_exists( 'pll_get_post_translations' ) ) {
	/** @return array<string, int> */
	function pll_get_post_translations( int $post_id ): array { return []; }
}

if ( ! function_exists( 'pll_get_post_language' ) ) {
	/** @return string|false */
	function pll_get_post_language( int $post_id, string $field = 'slug' ) { return false; }
}

if ( ! function_exists( 'pll_get_post' ) ) {
	/** @return int|false */
	function pll_get_post( int $post_id, string $lang = '' ) { return false; }
}

if ( ! function_exists( 'pll_set_post_language' ) ) {
	function pll_set_post_language( int $post_id, string $lang ): void {}
}

if ( ! function_exists( 'pll_save_post_translations' ) ) {
	/** @param array<string, int> $translations */
	function pll_save_post_translations( array $translations ): void {}
}

if ( ! function_exists( 'pll_get_term_translations' ) ) {
	/** @return array<string, int> */
	function pll_get_term_translations( int $term_id ): array { return []; }
}

if ( ! function_exists( 'pll_get_term_language' ) ) {
	/** @return string|false */
	function pll_get_term_language( int $term_id, string $field = 'slug' ) { return false; }
}

if ( ! function_exists( 'pll_set_term_language' ) ) {
	function pll_set_term_language( int $term_id, string $lang ): void {}
}

if ( ! function_exists( 'pll_save_term_translations' ) ) {
	/** @param array<string, int> $translations */
	function pll_save_term_translations( array $translations ): void {}
}

// ─── WP All Import stubs ─────────────────────────────────

if ( ! defined( 'PMXI_VERSION' ) ) {
	define( 'PMXI_VERSION', '4.8.0' );
}

if ( ! function_exists( 'wp_all_import_get_import_id' ) ) {
	/**
	 * @return int
	 */
	function wp_all_import_get_import_id(): int {
		return 0;
	}
}

// ─── BuddyBoss / BuddyPress stubs ───────────────────────

if ( ! defined( 'BP_VERSION' ) ) {
	define( 'BP_VERSION', '2.6.0' );
}

if ( ! function_exists( 'buddypress' ) ) {
	/** @return stdClass */
	function buddypress(): stdClass { return new stdClass(); }
}

if ( ! function_exists( 'bp_get_profile_field_data' ) ) {
	/**
	 * @param array $args
	 * @return string
	 */
	function bp_get_profile_field_data( array $args = [] ): string { return ''; }
}

if ( ! function_exists( 'xprofile_set_field_data' ) ) {
	/**
	 * @param string $field
	 * @param int    $user_id
	 * @param mixed  $value
	 * @return bool
	 */
	function xprofile_set_field_data( string $field, int $user_id, $value ): bool { return true; }
}

if ( ! function_exists( 'groups_get_group' ) ) {
	/**
	 * @param int $group_id
	 * @return object|null
	 */
	function groups_get_group( int $group_id ) { return null; }
}

if ( ! function_exists( 'groups_get_user_groups' ) ) {
	/**
	 * @param int $user_id
	 * @return array{groups: array<int>, total: int}
	 */
	function groups_get_user_groups( int $user_id ): array { return [ 'groups' => [], 'total' => 0 ]; }
}

// ─── GamiPress stubs ─────────────────────────────────────

if ( ! defined( 'GAMIPRESS_VERSION' ) ) {
	define( 'GAMIPRESS_VERSION', '2.8.0' );
}

if ( ! function_exists( 'gamipress' ) ) {
	/** @return stdClass */
	function gamipress(): stdClass { return new stdClass(); }
}

if ( ! function_exists( 'gamipress_get_user_points' ) ) {
	/**
	 * @param int    $user_id     WordPress user ID.
	 * @param string $points_type Points type slug.
	 * @return int
	 */
	function gamipress_get_user_points( int $user_id, string $points_type = 'points' ): int { return 0; }
}

if ( ! function_exists( 'gamipress_award_points_to_user' ) ) {
	/**
	 * @param int    $user_id     WordPress user ID.
	 * @param int    $points      Points to award.
	 * @param string $points_type Points type slug.
	 * @param array  $args        Additional arguments.
	 * @return void
	 */
	function gamipress_award_points_to_user( int $user_id, int $points, string $points_type = 'points', array $args = [] ): void {}
}

if ( ! function_exists( 'gamipress_deduct_points_to_user' ) ) {
	/**
	 * @param int    $user_id     WordPress user ID.
	 * @param int    $points      Points to deduct.
	 * @param string $points_type Points type slug.
	 * @param array  $args        Additional arguments.
	 * @return void
	 */
	function gamipress_deduct_points_to_user( int $user_id, int $points, string $points_type = 'points', array $args = [] ): void {}
}

// ─── SureCart stubs ──────────────────────────────────────

if ( ! defined( 'SURECART_VERSION' ) ) {
	define( 'SURECART_VERSION', '3.0.0' );
}

// SureCart namespace stub loaded from separate file (PHP namespace rules).
require_once __DIR__ . '/phpstan-surecart-stubs.php';

// ─── MailPoet stubs ──────────────────────────────────────

if ( ! defined( 'MAILPOET_VERSION' ) ) {
	define( 'MAILPOET_VERSION', '5.0.0' );
}

// MailPoet namespace stub loaded from separate file (PHP namespace rules).
require_once __DIR__ . '/phpstan-mailpoet-stubs.php';

// WP_CLI\Utils namespace stub loaded from separate file (PHP namespace rules).
require_once __DIR__ . '/phpstan-wp-cli-stubs.php';
