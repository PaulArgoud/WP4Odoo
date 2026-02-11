<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages module registration, mutual exclusivity, and lifecycle.
 *
 * Extracted from WP4Odoo_Plugin for SRP.
 *
 * @package WP4Odoo
 * @since   1.5.0
 */
class Module_Registry {

	/**
	 * Registered modules.
	 *
	 * @var array<string, Module_Base>
	 */
	private array $modules = [];

	/**
	 * Plugin instance (for third-party hook compatibility).
	 *
	 * @var \WP4Odoo_Plugin
	 */
	private \WP4Odoo_Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param \WP4Odoo_Plugin $plugin Plugin instance.
	 */
	public function __construct( \WP4Odoo_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register all built-in modules with mutual exclusivity rules.
	 *
	 * WooCommerce, EDD, and Sales modules are mutually exclusive (all share
	 * sale.order + product.template in Odoo). Priority: WC > EDD > Sales.
	 *
	 * @return void
	 */
	public function register_all(): void {
		$client_provider = fn() => $this->plugin->client();
		$entity_map      = new Entity_Map_Repository();

		$this->register( 'crm', new Modules\CRM_Module( $client_provider, $entity_map ) );

		$wc_active   = class_exists( 'WooCommerce' );
		$wc_enabled  = get_option( 'wp4odoo_module_woocommerce_enabled', false );
		$edd_active  = class_exists( 'Easy_Digital_Downloads' );
		$edd_enabled = get_option( 'wp4odoo_module_edd_enabled', false );

		if ( $wc_active && $wc_enabled ) {
			$this->register( 'woocommerce', new Modules\WooCommerce_Module( $client_provider, $entity_map ) );
		} elseif ( $edd_active && $edd_enabled ) {
			$this->register( 'edd', new Modules\EDD_Module( $client_provider, $entity_map ) );
		} else {
			$this->register( 'sales', new Modules\Sales_Module( $client_provider, $entity_map ) );
		}

		// Register inactive commerce modules for admin UI visibility.
		if ( $wc_active && ! $wc_enabled ) {
			$this->register( 'woocommerce', new Modules\WooCommerce_Module( $client_provider, $entity_map ) );
		}
		if ( $edd_active && ! $edd_enabled ) {
			$this->register( 'edd', new Modules\EDD_Module( $client_provider, $entity_map ) );
		}

		// Memberships module (requires WooCommerce + WC Memberships).
		if ( $wc_active ) {
			$this->register( 'memberships', new Modules\Memberships_Module( $client_provider, $entity_map ) );
		}

		// MemberPress module (mutually exclusive with WC Memberships — same Odoo models).
		if ( defined( 'MEPR_VERSION' ) ) {
			if ( ! isset( $this->modules['memberships'] ) || ! get_option( 'wp4odoo_module_memberships_enabled', false ) ) {
				$this->register( 'memberpress', new Modules\MemberPress_Module( $client_provider, $entity_map ) );
			}
		}

		// GiveWP module (donations → Odoo accounting).
		if ( defined( 'GIVE_VERSION' ) ) {
			$this->register( 'givewp', new Modules\GiveWP_Module( $client_provider, $entity_map ) );
		}

		// WP Charitable module (donations → Odoo accounting).
		if ( class_exists( 'Charitable' ) ) {
			$this->register( 'charitable', new Modules\Charitable_Module( $client_provider, $entity_map ) );
		}

		// WP Simple Pay module (Stripe payments → Odoo accounting).
		if ( defined( 'SIMPLE_PAY_VERSION' ) ) {
			$this->register( 'simplepay', new Modules\SimplePay_Module( $client_provider, $entity_map ) );
		}

		// WP Recipe Maker module (recipes → Odoo products).
		if ( defined( 'WPRM_VERSION' ) ) {
			$this->register( 'wprm', new Modules\WPRM_Module( $client_provider, $entity_map ) );
		}

		// Forms module (requires Gravity Forms or WPForms).
		$gf_active  = class_exists( 'GFAPI' );
		$wpf_active = function_exists( 'wpforms' );
		if ( $gf_active || $wpf_active ) {
			$this->register( 'forms', new Modules\Forms_Module( $client_provider, $entity_map ) );
		}

		// Allow third-party modules (closures and shared entity map available as arguments).
		do_action( 'wp4odoo_register_modules', $this->plugin, $client_provider, $entity_map );
	}

	/**
	 * Register a single module. Boots it if enabled.
	 *
	 * @param string      $id     Module identifier.
	 * @param Module_Base $module Module instance.
	 * @return void
	 */
	public function register( string $id, Module_Base $module ): void {
		$this->modules[ $id ] = $module;

		$enabled = get_option( 'wp4odoo_module_' . $id . '_enabled', false );
		if ( $enabled ) {
			$module->boot();
		}
	}

	/**
	 * Get a registered module.
	 *
	 * @param string $id Module identifier.
	 * @return Module_Base|null
	 */
	public function get( string $id ): ?Module_Base {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Get all registered modules.
	 *
	 * @return array<string, Module_Base>
	 */
	public function all(): array {
		return $this->modules;
	}
}
