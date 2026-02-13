<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages module registration, declarative mutual exclusivity, and lifecycle.
 *
 * Modules declare their own exclusive group and priority via properties.
 * The registry enforces that only one module per group is booted (highest
 * priority wins). All modules are registered for admin UI visibility.
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
	 * Module IDs that have been booted.
	 *
	 * @var string[]
	 */
	private array $booted = [];

	/**
	 * Plugin instance (for third-party hook compatibility).
	 *
	 * @var \WP4Odoo_Plugin
	 */
	private \WP4Odoo_Plugin $plugin;

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings;

	/**
	 * Constructor.
	 *
	 * @param \WP4Odoo_Plugin    $plugin   Plugin instance.
	 * @param Settings_Repository $settings Settings repository.
	 */
	public function __construct( \WP4Odoo_Plugin $plugin, Settings_Repository $settings ) {
		$this->plugin   = $plugin;
		$this->settings = $settings;
	}

	/**
	 * Register all built-in modules.
	 *
	 * Mutual exclusivity is driven by each module's $exclusive_group
	 * and $exclusive_priority properties. The registry simply registers
	 * every module whose external dependency is met.
	 *
	 * @return void
	 */
	public function register_all(): void {
		$client_provider = fn() => $this->plugin->client();
		$entity_map      = new Entity_Map_Repository();
		$settings        = $this->settings;

		// CRM — always available.
		$this->register( 'crm', new Modules\CRM_Module( $client_provider, $entity_map, $settings ) );

		// Commerce group (WC > EDD > Sales).
		if ( class_exists( 'WooCommerce' ) ) {
			$this->register( 'woocommerce', new Modules\WooCommerce_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( class_exists( 'Easy_Digital_Downloads' ) ) {
			$this->register( 'edd', new Modules\EDD_Module( $client_provider, $entity_map, $settings ) );
		}
		$this->register( 'sales', new Modules\Sales_Module( $client_provider, $entity_map, $settings ) );

		// Membership group.
		if ( class_exists( 'WooCommerce' ) ) {
			$this->register( 'memberships', new Modules\Memberships_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( defined( 'MEPR_VERSION' ) ) {
			$this->register( 'memberpress', new Modules\MemberPress_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( defined( 'PMPRO_VERSION' ) ) {
			$this->register( 'pmpro', new Modules\PMPro_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( function_exists( 'rcp_get_membership' ) ) {
			$this->register( 'rcp', new Modules\RCP_Module( $client_provider, $entity_map, $settings ) );
		}

		// Independent modules.
		if ( defined( 'GIVE_VERSION' ) ) {
			$this->register( 'givewp', new Modules\GiveWP_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( class_exists( 'Charitable' ) ) {
			$this->register( 'charitable', new Modules\Charitable_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( defined( 'SIMPLE_PAY_VERSION' ) ) {
			$this->register( 'simplepay', new Modules\SimplePay_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( defined( 'WPRM_VERSION' ) ) {
			$this->register( 'wprm', new Modules\WPRM_Module( $client_provider, $entity_map, $settings ) );
		}
		$forms_active = class_exists( 'GFAPI' )
			|| function_exists( 'wpforms' )
			|| defined( 'WPCF7_VERSION' )
			|| defined( 'FLUENTFORM' )
			|| class_exists( 'FrmAppHelper' )
			|| class_exists( 'Ninja_Forms' )
			|| defined( 'FORMINATOR_VERSION' );
		if ( $forms_active ) {
			$this->register( 'forms', new Modules\Forms_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( defined( 'AMELIA_VERSION' ) ) {
			$this->register( 'amelia', new Modules\Amelia_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( class_exists( 'Bookly\Lib\Plugin' ) ) {
			$this->register( 'bookly', new Modules\Bookly_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( defined( 'LEARNDASH_VERSION' ) ) {
			$this->register( 'learndash', new Modules\LearnDash_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( defined( 'LLMS_VERSION' ) ) {
			$this->register( 'lifterlms', new Modules\LifterLMS_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( class_exists( 'WC_Subscriptions' ) ) {
			$this->register( 'wc_subscriptions', new Modules\WC_Subscriptions_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( class_exists( 'WC_Points_Rewards' ) ) {
			$this->register( 'wc_points_rewards', new Modules\WC_Points_Rewards_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( class_exists( 'WC_Product_Booking' ) ) {
			$this->register( 'wc_bookings', new Modules\WC_Bookings_Module( $client_provider, $entity_map, $settings ) );
		}
		if ( class_exists( 'Tribe__Events__Main' ) ) {
			$this->register( 'events_calendar', new Modules\Events_Calendar_Module( $client_provider, $entity_map, $settings ) );
		}

		if ( defined( 'JOB_MANAGER_VERSION' ) ) {
			$this->register( 'job_manager', new Modules\Job_Manager_Module( $client_provider, $entity_map, $settings ) );
		}

		// Meta-modules (enrich other modules, no own entity types).
		if ( class_exists( 'ACF' ) || defined( 'ACF_MAJOR_VERSION' ) ) {
			$this->register( 'acf', new Modules\ACF_Module( $client_provider, $entity_map, $settings ) );
		}

		// Allow third-party modules (closures and shared entity map available as arguments).
		do_action( 'wp4odoo_register_modules', $this->plugin, $client_provider, $entity_map );
	}

	/**
	 * Register a single module. Boots it if enabled and no higher-priority
	 * module in the same exclusive group is already booted.
	 *
	 * @param string      $id     Module identifier.
	 * @param Module_Base $module Module instance.
	 * @return void
	 */
	public function register( string $id, Module_Base $module ): void {
		$this->modules[ $id ] = $module;

		if ( ! $this->settings->is_module_enabled( $id ) ) {
			return;
		}

		// Exclusive group check: skip boot if a higher-priority module is already booted.
		$group = $module->get_exclusive_group();
		if ( '' !== $group && $this->has_booted_in_group( $group, $module->get_exclusive_priority() ) ) {
			return;
		}

		$module->boot();
		$this->booted[] = $id;
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

	/**
	 * Get the currently booted module ID for a given exclusive group.
	 *
	 * @param string $group Exclusive group name.
	 * @return string|null Module ID, or null if none booted in this group.
	 */
	public function get_active_in_group( string $group ): ?string {
		foreach ( $this->booted as $booted_id ) {
			$module = $this->modules[ $booted_id ];
			if ( $module->get_exclusive_group() === $group ) {
				return $booted_id;
			}
		}
		return null;
	}

	/**
	 * Get all enabled module IDs that conflict with a given module.
	 *
	 * Returns enabled modules in the same exclusive group (excluding the module itself).
	 *
	 * @param string $module_id Module identifier.
	 * @return string[] Conflicting module IDs.
	 */
	public function get_conflicts( string $module_id ): array {
		$module = $this->modules[ $module_id ] ?? null;
		if ( ! $module ) {
			return [];
		}

		$group = $module->get_exclusive_group();
		if ( '' === $group ) {
			return [];
		}

		$conflicts = [];
		foreach ( $this->modules as $id => $other ) {
			if ( $id === $module_id ) {
				continue;
			}
			if ( $other->get_exclusive_group() === $group
				&& $this->settings->is_module_enabled( $id ) ) {
				$conflicts[] = $id;
			}
		}

		return $conflicts;
	}

	/**
	 * Check if any module in the given exclusive group is already booted
	 * with equal or higher priority.
	 *
	 * @param string $group    Exclusive group name.
	 * @param int    $priority Priority of the module being registered.
	 * @return bool True if a competing module is already booted.
	 */
	private function has_booted_in_group( string $group, int $priority ): bool {
		foreach ( $this->booted as $booted_id ) {
			$booted_module = $this->modules[ $booted_id ];
			if ( $booted_module->get_exclusive_group() === $group
				&& $booted_module->get_exclusive_priority() >= $priority ) {
				return true;
			}
		}
		return false;
	}
}
