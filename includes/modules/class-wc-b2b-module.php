<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce B2B / Wholesale Suite Module — bidirectional company
 * account sync, push-only for wholesale pricing rules, and pull-only
 * for payment terms.
 *
 * Syncs B2B company accounts as Odoo partners (res.partner with
 * is_company=True), wholesale pricing rules as pricelist items
 * (product.pricelist.item), and pulls payment terms from Odoo
 * (account.payment.term) for admin selection.
 *
 * Supports both Wholesale Suite (WWP) and B2BKing as detection sources.
 * Company accounts are bidirectional (push + pull). Pricelist rules
 * are push-only (they originate in WooCommerce). Payment terms are
 * pull-only (they originate in Odoo).
 *
 * Requires the WooCommerce module to be active.
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
class WC_B2B_Module extends Module_Base {

	use WC_B2B_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '2.12';

	/**
	 * Sync direction: bidirectional.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'company'        => 'res.partner',
		'pricelist'      => 'product.pricelist',
		'pricelist_rule' => 'product.pricelist.item',
		'payment_term'   => 'account.payment.term',
	];

	/**
	 * Default field mappings.
	 *
	 * Company and pricelist_rule mappings rename WP keys to Odoo fields.
	 * Payment term mappings are identity (for pull).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'company'        => [
			'billing_company' => 'name',
			'billing_email'   => 'email',
			'billing_vat'     => 'vat',
			'billing_phone'   => 'phone',
			'is_company'      => 'is_company',
		],
		'pricelist'      => [
			'name'        => 'name',
			'currency_id' => 'currency_id',
		],
		'pricelist_rule' => [
			'pricelist_id'    => 'pricelist_id',
			'product_tmpl_id' => 'product_tmpl_id',
			'fixed_price'     => 'fixed_price',
			'compute_price'   => 'compute_price',
			'applied_on'      => 'applied_on',
		],
		'payment_term'   => [
			'name' => 'name',
			'note' => 'note',
		],
	];

	/**
	 * WC B2B data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WC_B2B_Handler
	 */
	private WC_B2B_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_b2b', 'WooCommerce B2B', $client_provider, $entity_map, $settings );
		$this->handler = new WC_B2B_Handler( $this->logger );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_modules(): array {
		return [ 'woocommerce' ];
	}

	/**
	 * Boot the module: register B2B hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'WWP_PLUGIN_VERSION' ) && ! class_exists( 'B2bking' ) ) {
			$this->logger->warning( __( 'WC B2B module enabled but neither Wholesale Suite nor B2BKing is active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_companies'              => true,
			'sync_pricelist_rules'        => true,
			'sync_pricelists'             => false,
			'assign_pricelist_to_partner' => true,
			'pull_payment_terms'          => false,
			'wholesale_pricelist_id'      => 0,
			'wholesale_category_id'       => 0,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_companies'              => [
				'label'       => __( 'Sync B2B company accounts', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push wholesale company accounts to Odoo as company partners.', 'wp4odoo' ),
			],
			'sync_pricelist_rules'        => [
				'label'       => __( 'Sync wholesale pricing rules', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push wholesale prices to Odoo as pricelist items.', 'wp4odoo' ),
			],
			'sync_pricelists'             => [
				'label'       => __( 'Sync wholesale roles as pricelists', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Wholesale Suite roles to Odoo as product pricelists. Requires Wholesale Suite Premium.', 'wp4odoo' ),
			],
			'assign_pricelist_to_partner' => [
				'label'       => __( 'Assign pricelist to wholesale partners', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Set the matching Odoo pricelist on wholesale customer partners.', 'wp4odoo' ),
			],
			'pull_payment_terms'          => [
				'label'       => __( 'Pull payment terms from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull payment terms from Odoo for B2B order management.', 'wp4odoo' ),
			],
			'wholesale_pricelist_id'      => [
				'label'       => __( 'Odoo Pricelist ID', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'Odoo product.pricelist ID for wholesale pricing rules.', 'wp4odoo' ),
			],
			'wholesale_category_id'       => [
				'label'       => __( 'Odoo Partner Category ID', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'Odoo res.partner.category ID for wholesale customer tagging.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for Wholesale Suite or B2BKing.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency(
			defined( 'WWP_PLUGIN_VERSION' ) || class_exists( 'B2bking' ),
			'Wholesale Suite or B2BKing'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		if ( defined( 'WWP_PLUGIN_VERSION' ) ) {
			return WWP_PLUGIN_VERSION;
		}
		if ( class_exists( 'B2bking' ) && isset( \B2bking::$version ) ) {
			return \B2bking::$version;
		}
		return '';
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Companies dedup by VAT (preferred) or email.
	 * Pricelist rules dedup by pricelist_id + product_tmpl_id composite.
	 * Payment terms dedup by name.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'company' === $entity_type ) {
			if ( ! empty( $odoo_values['vat'] ) ) {
				return [ [ 'vat', '=', $odoo_values['vat'] ] ];
			}
			if ( ! empty( $odoo_values['email'] ) ) {
				return [ [ 'email', '=', $odoo_values['email'] ] ];
			}
		}

		if ( 'pricelist' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		if ( 'pricelist_rule' === $entity_type ) {
			if ( ! empty( $odoo_values['pricelist_id'] ) && ! empty( $odoo_values['product_tmpl_id'] ) ) {
				return [
					[ 'pricelist_id', '=', $odoo_values['pricelist_id'] ],
					[ 'product_tmpl_id', '=', $odoo_values['product_tmpl_id'] ],
				];
			}
		}

		if ( 'payment_term' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Only payment terms and companies can be pulled. Pricelist rules
	 * are push-only (they originate in WooCommerce).
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): Sync_Result {
		if ( 'pricelist_rule' === $entity_type ) {
			$this->logger->info(
				'Pricelist rule pull not supported — originates in WooCommerce.',
				[ 'odoo_id' => $odoo_id ]
			);
			return Sync_Result::success();
		}

		if ( 'pricelist' === $entity_type ) {
			$this->logger->info(
				'Pricelist pull not supported — wholesale roles originate in WordPress.',
				[ 'odoo_id' => $odoo_id ]
			);
			return Sync_Result::success();
		}

		if ( 'payment_term' === $entity_type ) {
			$settings = $this->get_settings();
			if ( empty( $settings['pull_payment_terms'] ) ) {
				return Sync_Result::success();
			}
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * Payment terms are stored in wp_options.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'payment_term' === $entity_type ) {
			return $this->handler->save_payment_term( $data, $wp_id );
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Not supported for B2B entities.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return false;
	}

	// ─── Push override ─────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For companies: force is_company=true, supplier_rank=0, resolve category M2M.
	 * For pricelist rules: resolve product_tmpl_id via WC entity_map, set pricelist_id.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		if ( 'payment_term' === $entity_type ) {
			$this->logger->info( 'Payment term push not supported — originates in Odoo.', [ 'wp_id' => $wp_id ] );
			return Sync_Result::success();
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	// ─── Data access ───────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		if ( 'company' === $entity_type ) {
			return $this->load_company_data( $wp_id );
		}

		if ( 'pricelist' === $entity_type ) {
			return $this->handler->load_pricelist( $wp_id );
		}

		if ( 'pricelist_rule' === $entity_type ) {
			return $this->load_pricelist_rule_data( $wp_id );
		}

		return [];
	}

	/**
	 * Load and format a company for push.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed>
	 */
	private function load_company_data( int $user_id ): array {
		$data = $this->handler->load_company( $user_id );
		if ( empty( $data ) ) {
			return [];
		}

		$settings    = $this->get_settings();
		$category_id = (int) ( $settings['wholesale_category_id'] ?? 0 );

		$formatted = $this->handler->format_company_for_odoo( $data, $category_id );

		// Assign pricelist to partner if enabled and a role-pricelist mapping exists.
		if ( ! empty( $settings['assign_pricelist_to_partner'] ) && ! empty( $data['wholesale_role'] ) ) {
			$pricelist_odoo_id = $this->handler->get_pricelist_odoo_id_for_role( $data['wholesale_role'] );
			if ( $pricelist_odoo_id > 0 ) {
				$formatted['property_product_pricelist'] = $pricelist_odoo_id;
			}
		}

		return $formatted;
	}

	/**
	 * Load and format a pricelist rule for push.
	 *
	 * Resolves the WC product to an Odoo product.template ID
	 * via the WooCommerce module's entity_map.
	 *
	 * @param int $product_id WC product ID.
	 * @return array<string, mixed>
	 */
	private function load_pricelist_rule_data( int $product_id ): array {
		$rule_data = $this->handler->load_pricelist_rule( $product_id );
		if ( empty( $rule_data ) ) {
			return [];
		}

		$settings     = $this->get_settings();
		$pricelist_id = (int) ( $settings['wholesale_pricelist_id'] ?? 0 );

		if ( $pricelist_id <= 0 ) {
			$this->logger->warning( 'Wholesale pricelist ID not configured.', [ 'product_id' => $product_id ] );
			return [];
		}

		// Resolve WC product → Odoo product.template ID.
		$product_tmpl_id = $this->entity_map->get_odoo_id( 'woocommerce', 'product', $product_id ) ?? 0;
		if ( $product_tmpl_id <= 0 ) {
			$this->logger->warning( 'Cannot resolve Odoo product template for pricelist rule.', [ 'product_id' => $product_id ] );
			return [];
		}

		return $this->handler->format_pricelist_rule_for_odoo(
			$rule_data['fixed_price'],
			$pricelist_id,
			$product_tmpl_id
		);
	}
}
