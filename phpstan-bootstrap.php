<?php
/**
 * PHPStan bootstrap — defines plugin constants and stubs
 * that are normally set up by the main wp4odoo.php entry point.
 *
 * @package WP4Odoo
 */

define( 'WP4ODOO_VERSION', '2.7.5' );
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
		public function get_id(): int { return 0; }
		public function get_total(): string { return '0'; }
		public function get_date_created(): ?\WC_DateTime { return null; }
		public function get_status(): string { return ''; }
		public function set_status( string $status ): void {}
		public function get_billing_email(): string { return ''; }
		public function get_formatted_billing_full_name(): string { return ''; }
		public function get_customer_id(): int { return 0; }
		/** @return array<int, array<string, mixed>> */
		public function get_items(): array { return []; }
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
		/** @return array<int, array<string, mixed>> */
		public function get_items(): array { return []; }
		public function get_user_id(): int { return 0; }
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

// WP_CLI\Utils namespace stub loaded from separate file (PHP namespace rules).
require_once __DIR__ . '/phpstan-wp-cli-stubs.php';
