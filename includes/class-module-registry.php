<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages module registration, declarative mutual exclusivity, and lifecycle.
 *
 * Modules declare their own exclusive group via properties. The registry
 * enforces that only one module per group is booted — the first module
 * registered in a group wins. Registration order (driven by the declarative
 * list in register_all()) determines precedence. All modules are registered
 * for admin UI visibility regardless of boot status.
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
	 * Version warnings collected during registration.
	 *
	 * Keyed by module ID, each value is an array of notice arrays.
	 *
	 * @var array<string, array<array{type: string, message: string}>>
	 */
	private array $version_warnings = [];

	/**
	 * Deferred modules: detected but not yet instantiated.
	 *
	 * Modules that pass detection but are disabled are stored here to
	 * avoid unnecessary class loading. Materialized on demand via get()
	 * or all().
	 *
	 * @since 3.6.0
	 * @var array<string, class-string<Module_Base>>
	 */
	private array $deferred = [];

	/**
	 * Client provider closure for deferred instantiation.
	 *
	 * @since 3.6.0
	 * @var \Closure|null
	 */
	private ?\Closure $client_provider = null;

	/**
	 * Entity map repository for deferred instantiation.
	 *
	 * @since 3.6.0
	 * @var Entity_Map_Repository|null
	 */
	private ?Entity_Map_Repository $entity_map = null;

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
	 * property. The first module registered in a group wins — subsequent
	 * modules in the same group are blocked. Registration order in this
	 * list determines precedence.
	 *
	 * @return void
	 */
	public function register_all(): void {
		$client_provider = fn() => $this->plugin->client();
		$entity_map      = new Entity_Map_Repository();
		$settings        = $this->settings;

		$this->client_provider = $client_provider;
		$this->entity_map      = $entity_map;

		// CRM — always available.
		$this->register( 'crm', new Modules\CRM_Module( $client_provider, $entity_map, $settings ) );

		// Declarative module registry: [ id, class, detection_callback ].
		// Modules with null detection are always registered.
		// phpcs:disable Squiz.PHP.CommentedOutCode.Found
		$module_defs = [
			// Commerce group (WC > EDD > Sales).
			[ 'woocommerce', Modules\WooCommerce_Module::class, fn() => class_exists( 'WooCommerce' ) ],
			[ 'edd', Modules\EDD_Module::class, fn() => class_exists( 'Easy_Digital_Downloads' ) ],
			[ 'sales', Modules\Sales_Module::class, null ],

			// Membership group.
			[ 'memberships', Modules\Memberships_Module::class, fn() => class_exists( 'WooCommerce' ) ],
			[ 'memberpress', Modules\MemberPress_Module::class, fn() => defined( 'MEPR_VERSION' ) ],
			[ 'pmpro', Modules\PMPro_Module::class, fn() => defined( 'PMPRO_VERSION' ) ],
			[ 'rcp', Modules\RCP_Module::class, fn() => function_exists( 'rcp_get_membership' ) ],

			// Independent modules.
			[ 'givewp', Modules\GiveWP_Module::class, fn() => defined( 'GIVE_VERSION' ) ],
			[ 'charitable', Modules\Charitable_Module::class, fn() => class_exists( 'Charitable' ) ],
			[ 'simplepay', Modules\SimplePay_Module::class, fn() => defined( 'SIMPLE_PAY_VERSION' ) ],
			[ 'wprm', Modules\WPRM_Module::class, fn() => defined( 'WPRM_VERSION' ) ],
			[ 'amelia', Modules\Amelia_Module::class, fn() => defined( 'AMELIA_VERSION' ) ],
			[ 'bookly', Modules\Bookly_Module::class, fn() => class_exists( 'Bookly\Lib\Plugin' ) ],
			[ 'jet_appointments', Modules\Jet_Appointments_Module::class, fn() => defined( 'JET_APB_VERSION' ) || class_exists( 'JET_APB\Plugin' ) ],
			[ 'jet_booking', Modules\Jet_Booking_Module::class, fn() => defined( 'JET_ABAF_VERSION' ) || class_exists( 'JET_ABAF\Plugin' ) ],
			[ 'project_manager', Modules\Project_Manager_Module::class, fn() => defined( 'CPM_VERSION' ) || class_exists( 'WeDevs\PM\Core\WP\WP_Project_Manager' ) ],
			[ 'learndash', Modules\LearnDash_Module::class, fn() => defined( 'LEARNDASH_VERSION' ) ],
			[ 'tutorlms', Modules\TutorLMS_Module::class, fn() => defined( 'TUTOR_VERSION' ) ],
			[ 'lifterlms', Modules\LifterLMS_Module::class, fn() => defined( 'LLMS_VERSION' ) ],
			[ 'wc_subscriptions', Modules\WC_Subscriptions_Module::class, fn() => class_exists( 'WC_Subscriptions' ) ],
			[ 'wc_b2b', Modules\WC_B2B_Module::class, fn() => defined( 'WWP_PLUGIN_VERSION' ) || class_exists( 'B2bking' ) ],
			[ 'wc_points_rewards', Modules\WC_Points_Rewards_Module::class, fn() => class_exists( 'WC_Points_Rewards' ) ],
			[ 'wc_bookings', Modules\WC_Bookings_Module::class, fn() => class_exists( 'WC_Product_Booking' ) ],
			[ 'events_calendar', Modules\Events_Calendar_Module::class, fn() => class_exists( 'Tribe__Events__Main' ) ],
			[ 'job_manager', Modules\Job_Manager_Module::class, fn() => defined( 'JOB_MANAGER_VERSION' ) ],
			[ 'wc_bundle_bom', Modules\WC_Bundle_BOM_Module::class, fn() => class_exists( 'WC_Bundles' ) || class_exists( 'WC_Composite_Products' ) ],
			[ 'wc_addons', Modules\WC_Addons_Module::class, fn() => class_exists( 'WC_Product_Addons' ) || defined( 'THWEPO_VERSION' ) || defined( 'PPOM_VERSION' ) ],
			[ 'jeero_configurator', Modules\Jeero_Configurator_Module::class, fn() => class_exists( 'Jeero_Product_Configurator' ) || defined( 'JEERO_VERSION' ) ],

			// WooCommerce extensions — advanced stock/shipping/returns.
			[ 'wc_inventory', Modules\WC_Inventory_Module::class, fn() => class_exists( 'WooCommerce' ) ],
			[ 'wc_shipping', Modules\WC_Shipping_Module::class, fn() => class_exists( 'WooCommerce' ) ],
			[ 'wc_returns', Modules\WC_Returns_Module::class, fn() => class_exists( 'WooCommerce' ) ],

			// Invoicing group.
			[ 'sprout_invoices', Modules\Sprout_Invoices_Module::class, fn() => class_exists( 'SI_Invoice' ) ],
			[ 'wp_invoice', Modules\WP_Invoice_Module::class, fn() => class_exists( 'WPI_Invoice' ) ],

			// E-commerce group (secondary).
			[ 'surecart', Modules\SureCart_Module::class, fn() => defined( 'SURECART_VERSION' ) ],
			[ 'ecwid', Modules\Ecwid_Module::class, fn() => defined( 'ECWID_PLUGIN_DIR' ) ],
			[ 'shopwp', Modules\ShopWP_Module::class, fn() => defined( 'SHOPWP_PLUGIN_DIR' ) ],
			[ 'crowdfunding', Modules\Crowdfunding_Module::class, fn() => function_exists( 'wpneo_crowdfunding_init' ) ],

			// Marketplace group.
			[ 'dokan', Modules\Dokan_Module::class, fn() => defined( 'DOKAN_PLUGIN_VERSION' ) ],
			[ 'wcfm', Modules\WCFM_Module::class, fn() => defined( 'WCFM_VERSION' ) ],
			[ 'wc_vendors', Modules\WC_Vendors_Module::class, fn() => class_exists( 'WCV_Vendors' ) || defined( 'WCV_PRO_VERSION' ) ],

			// Helpdesk group.
			[ 'awesome_support', Modules\Awesome_Support_Module::class, fn() => defined( 'WPAS_VERSION' ) ],
			[ 'supportcandy', Modules\SupportCandy_Module::class, fn() => defined( 'WPSC_VERSION' ) ],

			// Affiliate.
			[ 'affiliatewp', Modules\AffiliateWP_Module::class, fn() => function_exists( 'affiliate_wp' ) ],

			// Marketing CRM.
			[ 'fluentcrm', Modules\FluentCRM_Module::class, fn() => defined( 'FLUENTCRM' ) ],
			[ 'mailpoet', Modules\MailPoet_Module::class, fn() => defined( 'MAILPOET_VERSION' ) ],
			[ 'mc4wp', Modules\MC4WP_Module::class, fn() => defined( 'MC4WP_VERSION' ) ],

			// Funnel / Sales pipeline.
			[ 'funnelkit', Modules\FunnelKit_Module::class, fn() => defined( 'WFFN_VERSION' ) ],

			// Gamification / Loyalty.
			[ 'gamipress', Modules\GamiPress_Module::class, fn() => defined( 'GAMIPRESS_VERSION' ) ],
			[ 'mycred', Modules\MyCRED_Module::class, fn() => defined( 'myCRED_VERSION' ) ],

			// Community.
			[ 'buddyboss', Modules\BuddyBoss_Module::class, fn() => defined( 'BP_VERSION' ) ],

			// HR.
			[ 'wperp', Modules\WPERP_Module::class, fn() => defined( 'WPERP_VERSION' ) ],

			// CRM (WP ERP) — separate from the core CRM module.
			[ 'wperp_crm', Modules\WPERP_CRM_Module::class, fn() => defined( 'WPERP_VERSION' ) ],

			// Accounting (WP ERP).
			[ 'wperp_accounting', Modules\WPERP_Accounting_Module::class, fn() => defined( 'WPERP_VERSION' ) ],

			// LMS.
			[ 'learnpress', Modules\LearnPress_Module::class, fn() => defined( 'LP_PLUGIN_FILE' ) ],

			// Knowledge — always registered; detection is Odoo-side.
			[ 'knowledge', Modules\Knowledge_Module::class, null ],

			// Documents — WP Document Revisions / WP Download Manager.
			[ 'documents', Modules\Documents_Module::class, fn() => class_exists( 'Document_Revisions' ) || defined( 'WPDM_VERSION' ) ],

			// Generic CPT mapping.
			[ 'jetengine', Modules\JetEngine_Module::class, fn() => defined( 'JET_ENGINE_VERSION' ) || class_exists( 'Jet_Engine' ) ],

			// Meta-modules (enrich other modules, no own entity types).
			[ 'acf', Modules\ACF_Module::class, fn() => class_exists( 'ACF' ) || defined( 'ACF_MAJOR_VERSION' ) ],
			[ 'jetengine_meta', Modules\JetEngine_Meta_Module::class, fn() => defined( 'JET_ENGINE_VERSION' ) || class_exists( 'Jet_Engine' ) ],
			[ 'wpai', Modules\WP_All_Import_Module::class, fn() => defined( 'PMXI_VERSION' ) || class_exists( 'PMXI_Plugin' ) ],
		];
		// phpcs:enable

		foreach ( $module_defs as [ $id, $class, $detect ] ) {
			if ( null === $detect || $detect() ) {
				if ( $this->settings->is_module_enabled( $id ) ) {
					$this->register( $id, new $class( $client_provider, $entity_map, $settings ) );
				} else {
					$this->deferred[ $id ] = $class;
				}
			}
		}

		// Food Ordering — aggregate detection.
		$food_active = defined( 'FLAVOR_FLAVOR_VERSION' )
			|| defined( 'WPPIZZA_VERSION' );
		if ( $food_active ) {
			if ( $this->settings->is_module_enabled( 'food_ordering' ) ) {
				$this->register( 'food_ordering', new Modules\Food_Ordering_Module( $client_provider, $entity_map, $settings ) );
			} else {
				$this->deferred['food_ordering'] = Modules\Food_Ordering_Module::class;
			}
		}

		// Survey & Quiz — aggregate detection.
		$survey_active = defined( 'QUIZ_MAKER_VERSION' )
			|| defined( 'QSM_PLUGIN_INSTALLED' );
		if ( $survey_active ) {
			if ( $this->settings->is_module_enabled( 'survey_quiz' ) ) {
				$this->register( 'survey_quiz', new Modules\Survey_Quiz_Module( $client_provider, $entity_map, $settings ) );
			} else {
				$this->deferred['survey_quiz'] = Modules\Survey_Quiz_Module::class;
			}
		}

		// Forms module — aggregate detection (any of 8 form plugins).
		$forms_active = class_exists( 'GFAPI' )
			|| function_exists( 'wpforms' )
			|| defined( 'WPCF7_VERSION' )
			|| defined( 'FLUENTFORM' )
			|| class_exists( 'FrmAppHelper' )
			|| class_exists( 'Ninja_Forms' )
			|| defined( 'FORMINATOR_VERSION' )
			|| defined( 'JET_FORM_BUILDER_VERSION' );
		if ( $forms_active ) {
			if ( $this->settings->is_module_enabled( 'forms' ) ) {
				$this->register( 'forms', new Modules\Forms_Module( $client_provider, $entity_map, $settings ) );
			} else {
				$this->deferred['forms'] = Modules\Forms_Module::class;
			}
		}

		// Allow third-party modules (closures and shared entity map available as arguments).
		do_action( 'wp4odoo_register_modules', $this->plugin, $client_provider, $entity_map );
	}

	/**
	 * Register a single module. Boots it if enabled and no other module
	 * in the same exclusive group is already booted.
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

		// Version / dependency check: prevents boot for missing or too-old plugins.
		$dep = $module->get_dependency_status();
		if ( ! $dep['available'] ) {
			return;
		}
		if ( ! empty( $dep['notices'] ) ) {
			$this->version_warnings[ $id ] = $dep['notices'];
		}

		// Required modules check: skip boot if a required module is not booted.
		$required = $module->get_required_modules();
		if ( ! empty( $required ) ) {
			$missing = array_diff( $required, $this->booted );
			if ( ! empty( $missing ) ) {
				$this->version_warnings[ $id ][] = [
					'type'    => 'warning',
					'message' => sprintf(
						/* translators: 1: module name, 2: comma-separated list of required module IDs */
						__( '%1$s requires the following modules to be active: %2$s.', 'wp4odoo' ),
						$module->get_name(),
						implode( ', ', $missing )
					),
				];
				return;
			}
		}

		// Exclusive group check: skip boot if any module in the group is already booted.
		$group = $module->get_exclusive_group();
		if ( '' !== $group && $this->has_booted_in_group( $group ) ) {
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
		if ( isset( $this->modules[ $id ] ) ) {
			return $this->modules[ $id ];
		}
		if ( isset( $this->deferred[ $id ] ) ) {
			$this->materialize( $id );
			return $this->modules[ $id ];
		}
		return null;
	}

	/**
	 * Get all registered modules.
	 *
	 * @return array<string, Module_Base>
	 */
	public function all(): array {
		$this->materialize_all();
		return $this->modules;
	}

	/**
	 * Get the number of booted modules.
	 *
	 * @return int
	 */
	public function get_booted_count(): int {
		return count( $this->booted );
	}

	/**
	 * Get version warnings collected during module registration.
	 *
	 * @return array<string, array<array{type: string, message: string}>>
	 */
	public function get_version_warnings(): array {
		return $this->version_warnings;
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
		$this->materialize_all();
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
	 * Instantiate a single deferred module.
	 *
	 * @since 3.6.0
	 *
	 * @param string $id Module identifier.
	 * @return void
	 */
	private function materialize( string $id ): void {
		if ( null === $this->client_provider || null === $this->entity_map ) {
			return; // @codeCoverageIgnore
		}
		$class                = $this->deferred[ $id ];
		$this->modules[ $id ] = new $class( $this->client_provider, $this->entity_map, $this->settings );
		unset( $this->deferred[ $id ] );
	}

	/**
	 * Instantiate all deferred modules.
	 *
	 * @since 3.6.0
	 *
	 * @return void
	 */
	private function materialize_all(): void {
		foreach ( array_keys( $this->deferred ) as $id ) {
			$this->materialize( $id );
		}
	}

	/**
	 * Check if any module in the given exclusive group is already booted.
	 *
	 * Only one module per exclusive group may boot. The first module to boot
	 * in a group wins — subsequent modules in the same group are blocked
	 * regardless of their priority. Registration order (driven by the
	 * declarative list in register_all()) determines which module boots first.
	 *
	 * @param string $group Exclusive group name.
	 * @return bool True if any module in this group is already booted.
	 */
	private function has_booted_in_group( string $group ): bool {
		foreach ( $this->booted as $booted_id ) {
			$booted_module = $this->modules[ $booted_id ];
			if ( $booted_module->get_exclusive_group() === $group ) {
				return true;
			}
		}
		return false;
	}
}
