<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Odoo_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SureCart Module — bidirectional product/order/subscription sync with Odoo.
 *
 * SureCart is a headless Stripe-native e-commerce platform. This module syncs:
 * - Products → product.template (bidirectional)
 * - Orders → sale.order (push-only, from SureCart checkouts)
 * - Subscriptions ↔ sale.subscription (bidirectional, Enterprise only)
 *
 * Mutually exclusive with WooCommerce, EDD, and other e-commerce modules:
 * only one can be active at a time (all share sale.order + product.template).
 *
 * Domain logic is split into a dedicated handler:
 * - SureCart_Handler — product/order/subscription load, status mapping, line formatting
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class SureCart_Module extends Module_Base {

	use SureCart_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '3.5';

	/**
	 * Exclusive group: e-commerce (shared sale.order + product.template).
	 *
	 * @var string
	 */
	protected string $exclusive_group = 'ecommerce';

	/**
	 * Priority within the ecommerce exclusive group.
	 *
	 * @var int
	 */
	protected int $exclusive_priority = 8;

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'product'      => 'product.template',
		'order'        => 'sale.order',
		'subscription' => 'sale.subscription',
	];

	/**
	 * Default field mappings.
	 *
	 * Order and subscription mappings are identity (pre-formatted by handler).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'product'      => [
			'name'        => 'name',
			'slug'        => 'default_code',
			'description' => 'description_sale',
			'price'       => 'list_price',
		],
		'order'        => [
			'partner_id' => 'partner_id',
			'date_order' => 'date_order',
			'ref'        => 'client_order_ref',
			'order_line' => 'order_line',
		],
		'subscription' => [
			'partner_id'                 => 'partner_id',
			'date_start'                 => 'date_start',
			'recurring_rule_type'        => 'recurring_rule_type',
			'recurring_interval'         => 'recurring_interval',
			'recurring_invoice_line_ids' => 'recurring_invoice_line_ids',
		],
	];

	/**
	 * SureCart data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var SureCart_Handler
	 */
	private SureCart_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'surecart', 'SureCart', $client_provider, $entity_map, $settings );
		$this->handler = new SureCart_Handler( $this->logger );
	}

	/**
	 * Boot the module: register SureCart hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'SURECART_VERSION' ) ) {
			$this->logger->warning( __( 'SureCart module enabled but SureCart is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		// Products.
		if ( ! empty( $settings['sync_products'] ) ) {
			add_action( 'surecart/product_created', $this->safe_callback( [ $this, 'on_product_created' ] ) );
			add_action( 'surecart/product_updated', $this->safe_callback( [ $this, 'on_product_updated' ] ) );
		}

		// Orders.
		if ( ! empty( $settings['sync_orders'] ) ) {
			add_action( 'surecart/checkout_confirmed', $this->safe_callback( [ $this, 'on_order_created' ] ) );
		}

		// Subscriptions.
		if ( ! empty( $settings['sync_subscriptions'] ) ) {
			add_action( 'surecart/subscription_created', $this->safe_callback( [ $this, 'on_subscription_created' ] ) );
			add_action( 'surecart/subscription_updated', $this->safe_callback( [ $this, 'on_subscription_updated' ] ) );
		}
	}

	/**
	 * Get the sync direction for this module.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_products'      => true,
			'sync_orders'        => true,
			'sync_subscriptions' => true,
			'pull_subscriptions' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_products'      => [
				'label'       => __( 'Sync products', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Synchronize SureCart products with Odoo products.', 'wp4odoo' ),
			],
			'sync_orders'        => [
				'label'       => __( 'Sync orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Synchronize SureCart orders with Odoo sale orders.', 'wp4odoo' ),
			],
			'sync_subscriptions' => [
				'label'       => __( 'Sync subscriptions', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push subscriptions to Odoo sale.subscription (Enterprise only).', 'wp4odoo' ),
			],
			'pull_subscriptions' => [
				'label'       => __( 'Pull subscription updates from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull subscription status changes from Odoo back to SureCart.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for SureCart.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'SURECART_VERSION' ), 'SureCart' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'SURECART_VERSION' ) ? SURECART_VERSION : '';
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Products dedup by default_code (slug). Orders and subscriptions dedup by ref.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'product' === $entity_type && ! empty( $odoo_values['default_code'] ) ) {
			return [ [ 'default_code', '=', $odoo_values['default_code'] ] ];
		}

		if ( 'order' === $entity_type && ! empty( $odoo_values['client_order_ref'] ) ) {
			return [ [ 'client_order_ref', '=', $odoo_values['client_order_ref'] ] ];
		}

		if ( 'subscription' === $entity_type && ! empty( $odoo_values['code'] ) ) {
			return [ [ 'code', '=', $odoo_values['code'] ] ];
		}

		return [];
	}

	// ─── Dual-model detection ──────────────────────────────

	/**
	 * Check whether Odoo has the sale.subscription model (Enterprise 14-16).
	 *
	 * Delegates to Module_Helpers::has_odoo_model().
	 *
	 * @return bool
	 */
	public function has_subscription_model(): bool {
		return $this->has_odoo_model( Odoo_Model::SaleSubscription, 'wp4odoo_has_sale_subscription' );
	}

	// ─── Push override ─────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For orders: resolve partner, format order lines as One2many.
	 * For subscriptions: guard on sale.subscription availability, resolve partner + product.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'subscription' === $entity_type && 'delete' !== $action ) {
			if ( ! $this->has_subscription_model() ) {
				$this->logger->info( 'sale.subscription not available — skipping subscription push.', [ 'sub_id' => $wp_id ] );
				return \WP4Odoo\Sync_Result::success();
			}
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Only subscriptions can be pulled (status updates). Products and
	 * orders are push-only (they originate in SureCart).
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'product' === $entity_type || 'order' === $entity_type ) {
			$this->logger->info(
				\sprintf( '%s pull not supported — originates in SureCart.', $entity_type ),
				[ 'odoo_id' => $odoo_id ]
			);
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'subscription' === $entity_type ) {
			$settings = $this->get_settings();
			if ( empty( $settings['pull_subscriptions'] ) ) {
				return \WP4Odoo\Sync_Result::success();
			}
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	// ─── Data Loading ──────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * For orders and subscriptions, partner resolution and line formatting
	 * happen here (delegating to handler for raw data).
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'product'      => $this->handler->load_product( $wp_id ),
			'order'        => $this->load_order_data( $wp_id ),
			'subscription' => $this->load_subscription_data( $wp_id ),
			default        => [],
		};
	}

	/**
	 * Load and resolve an order with Odoo references.
	 *
	 * @param int $order_id SureCart checkout ID.
	 * @return array<string, mixed>
	 */
	private function load_order_data( int $order_id ): array {
		$data = $this->handler->load_order( $order_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve partner.
		$email = $data['email'] ?? '';
		$name  = $data['name'] ?? '';

		if ( empty( $email ) ) {
			$this->logger->warning( 'SureCart order has no email.', [ 'order_id' => $order_id ] );
			return [];
		}

		$partner_id = $this->resolve_partner_from_email( $email, $name );
		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for SureCart order.', [ 'order_id' => $order_id ] );
			return [];
		}

		// Format order lines.
		$order_lines = $this->handler->format_order_lines(
			$data['line_items'] ?? [],
			fn( $sc_product_id ) => $this->get_mapping( 'product', (int) $sc_product_id ) ?? 0
		);

		return [
			'partner_id' => $partner_id,
			'date_order' => substr( $data['created_at'] ?? '', 0, 10 ),
			'ref'        => 'sc-' . $order_id,
			'order_line' => $order_lines,
		];
	}

	/**
	 * Load and resolve a subscription with Odoo references.
	 *
	 * @param int $sub_id SureCart subscription ID.
	 * @return array<string, mixed>
	 */
	private function load_subscription_data( int $sub_id ): array {
		$data = $this->handler->load_subscription( $sub_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve partner.
		$email = $data['email'] ?? '';
		$name  = $data['name'] ?? '';

		if ( empty( $email ) ) {
			$this->logger->warning( 'SureCart subscription has no email.', [ 'sub_id' => $sub_id ] );
			return [];
		}

		$partner_id = $this->resolve_partner_from_email( $email, $name );
		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for SureCart subscription.', [ 'sub_id' => $sub_id ] );
			return [];
		}

		// Resolve product.
		$product_id      = (int) ( $data['product_id'] ?? 0 );
		$product_odoo_id = 0;
		if ( $product_id > 0 ) {
			$this->ensure_entity_synced( 'product', $product_id );
			$product_odoo_id = $this->get_mapping( 'product', $product_id ) ?? 0;
		}

		if ( ! $product_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for SureCart subscription.', [ 'product_id' => $product_id ] );
			return [];
		}

		return [
			'partner_id'                 => $partner_id,
			'date_start'                 => substr( $data['created_at'] ?? '', 0, 10 ),
			'recurring_rule_type'        => $this->handler->map_billing_period( $data['billing_period'] ?? 'monthly' ),
			'recurring_interval'         => (int) ( $data['billing_interval'] ?? 1 ),
			'recurring_invoice_line_ids' => [
				[
					0,
					0,
					[
						'product_id' => $product_odoo_id,
						'quantity'   => 1,
						'price_unit' => (float) ( $data['amount'] ?? 0.0 ),
						'name'       => $data['product_name'] ?: __( 'SureCart Subscription', 'wp4odoo' ),
					],
				],
			],
		];
	}

	// ─── Data Mapping Override ──────────────────────────────

	/**
	 * Map WordPress data to Odoo format.
	 *
	 * For products, sets type='consu' (consumable). For orders and subscriptions,
	 * data is pre-formatted by load methods — identity pass-through.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array Odoo-compatible field values.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		$odoo_values = parent::map_to_odoo( $entity_type, $wp_data );

		if ( 'product' === $entity_type ) {
			$odoo_values['type'] = 'consu';
		}

		return $odoo_values;
	}

	// ─── Save WordPress Data ───────────────────────────────

	/**
	 * Save pulled data to WordPress.
	 *
	 * Only subscription status updates are supported.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'subscription' === $entity_type && $wp_id > 0 ) {
			$status = $data['status'] ?? '';
			if ( ! empty( $status ) ) {
				$success = $this->handler->save_subscription_status( $wp_id, $status );
				return $success ? $wp_id : 0;
			}
			return $wp_id;
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Not supported — SureCart entities cannot be deleted from Odoo side.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return false;
	}
}
