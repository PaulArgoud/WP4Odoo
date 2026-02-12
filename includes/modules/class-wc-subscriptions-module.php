<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Subscriptions Module — bidirectional subscription sync,
 * push-only for products and renewals.
 *
 * Syncs WC subscription products as Odoo service products (product.product),
 * subscriptions as sale.subscription (Odoo Enterprise), and renewal orders
 * as invoices (account.move).
 *
 * Subscriptions are bidirectional (push + pull status updates).
 * Products and renewals are push-only (they originate in WooCommerce).
 *
 * Dual-model: probes Odoo for sale.subscription model at runtime.
 * If available (Odoo 14-16 Enterprise), subscriptions are pushed there.
 * If not (Community / Odoo 17+), renewal invoices become the primary sync.
 *
 * Requires WooCommerce + WooCommerce Subscriptions to be active.
 * Independent module — coexists with the WooCommerce module.
 *
 * @package WP4Odoo
 * @since   2.6.5
 */
class WC_Subscriptions_Module extends Module_Base {

	use WC_Subscriptions_Hooks;

	/**
	 * Sync direction: bidirectional for subscriptions, push-only for products/renewals.
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
		'product'      => 'product.product',
		'subscription' => 'sale.subscription',
		'renewal'      => 'account.move',
	];

	/**
	 * Default field mappings.
	 *
	 * Subscription and renewal mappings are identity (pre-formatted by handler).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'product'      => [
			'product_name' => 'name',
			'list_price'   => 'list_price',
			'type'         => 'type',
		],
		'subscription' => [
			'partner_id'                 => 'partner_id',
			'date_start'                 => 'date_start',
			'recurring_next_date'        => 'recurring_next_date',
			'recurring_rule_type'        => 'recurring_rule_type',
			'recurring_interval'         => 'recurring_interval',
			'recurring_invoice_line_ids' => 'recurring_invoice_line_ids',
		],
		'renewal'      => [
			'move_type'        => 'move_type',
			'partner_id'       => 'partner_id',
			'invoice_date'     => 'invoice_date',
			'ref'              => 'ref',
			'invoice_line_ids' => 'invoice_line_ids',
		],
	];

	/**
	 * WC Subscriptions data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WC_Subscriptions_Handler
	 */
	private WC_Subscriptions_Handler $handler;

	/**
	 * Cached sale.subscription model detection result.
	 *
	 * @var bool|null
	 */
	private ?bool $subscription_model_detected = null;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_subscriptions', 'WooCommerce Subscriptions', $client_provider, $entity_map, $settings );
		$this->handler = new WC_Subscriptions_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WC Subscriptions hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			$this->logger->warning( __( 'WC Subscriptions module enabled but WooCommerce Subscriptions is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_products'] ) ) {
			add_action( 'save_post_product', [ $this, 'on_product_save' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_subscriptions'] ) ) {
			add_action( 'woocommerce_subscription_status_updated', [ $this, 'on_subscription_status_updated' ], 10, 3 );
		}

		if ( ! empty( $settings['sync_renewals'] ) ) {
			add_action( 'woocommerce_subscription_renewal_payment_complete', [ $this, 'on_renewal_payment_complete' ], 10, 2 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_products'      => true,
			'sync_subscriptions' => true,
			'sync_renewals'      => true,
			'auto_post_invoices' => true,
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
				'label'       => __( 'Sync subscription products', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WC subscription products to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_subscriptions' => [
				'label'       => __( 'Sync subscriptions', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push subscriptions to Odoo sale.subscription (Enterprise only).', 'wp4odoo' ),
			],
			'sync_renewals'      => [
				'label'       => __( 'Sync renewal orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push renewal payments to Odoo as invoices.', 'wp4odoo' ),
			],
			'auto_post_invoices' => [
				'label'       => __( 'Auto-post invoices', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm renewal invoices in Odoo.', 'wp4odoo' ),
			],
			'pull_subscriptions' => [
				'label'       => __( 'Pull subscription updates from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull subscription status changes from Odoo back to WooCommerce.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for WooCommerce Subscriptions.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'WC_Subscriptions' ), 'WooCommerce Subscriptions' );
	}

	// ─── Dual-model detection ──────────────────────────────

	/**
	 * Check whether Odoo has the sale.subscription model (Enterprise 14-16).
	 *
	 * Probes ir.model. Result cached in transient (1 hour) and in-memory.
	 *
	 * @return bool
	 */
	private function has_subscription_model(): bool {
		if ( null !== $this->subscription_model_detected ) {
			return $this->subscription_model_detected;
		}

		$cached = get_transient( 'wp4odoo_has_sale_subscription' );
		if ( false !== $cached ) {
			$this->subscription_model_detected = (bool) $cached;
			return $this->subscription_model_detected;
		}

		try {
			$count  = $this->client()->search_count(
				'ir.model',
				[ [ 'model', '=', 'sale.subscription' ] ]
			);
			$result = $count > 0;
		} catch ( \Exception $e ) {
			$result = false;
		}

		set_transient( 'wp4odoo_has_sale_subscription', $result ? 1 : 0, HOUR_IN_SECONDS );
		$this->subscription_model_detected = $result;

		return $result;
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Only subscriptions can be pulled (status updates). Products and
	 * renewals are push-only (they originate in WooCommerce).
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'product' === $entity_type || 'renewal' === $entity_type ) {
			$this->logger->info(
				\sprintf( '%s pull not supported — originates in WooCommerce.', $entity_type ),
				[ 'odoo_id' => $odoo_id ]
			);
			return \WP4Odoo\Sync_Result::success( 0 );
		}

		if ( 'subscription' === $entity_type ) {
			$settings = $this->get_settings();
			if ( empty( $settings['pull_subscriptions'] ) ) {
				return \WP4Odoo\Sync_Result::success( 0 );
			}
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * Subscriptions use the handler's parse method for reverse mapping.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'subscription' === $entity_type ) {
			return $this->handler->parse_subscription_from_odoo( $odoo_data );
		}

		return parent::map_from_odoo( $entity_type, $odoo_data );
	}

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
		if ( 'subscription' === $entity_type ) {
			return $this->handler->save_subscription( $data, $wp_id );
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Subscriptions cannot be deleted from Odoo side.
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
	 * For subscriptions: skip if sale.subscription not available, ensure product synced.
	 * For renewals: ensure product synced, auto-post invoice on success.
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
				return \WP4Odoo\Sync_Result::success( 0 );
			}
			$this->ensure_product_synced( $wp_id, 'subscription' );
		}

		if ( 'renewal' === $entity_type && 'delete' !== $action ) {
			$this->ensure_product_synced( $wp_id, 'renewal' );
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		if ( $result->succeeded() && 'renewal' === $entity_type && 'create' === $action ) {
			$this->auto_post_invoice( 'auto_post_invoices', 'renewal', $wp_id );
		}

		return $result;
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
		return match ( $entity_type ) {
			'product'      => $this->handler->load_product( $wp_id ),
			'subscription' => $this->load_subscription_data( $wp_id ),
			'renewal'      => $this->load_renewal_data( $wp_id ),
			default        => [],
		};
	}

	/**
	 * Load and resolve a subscription with Odoo references.
	 *
	 * @param int $sub_id WC Subscription ID.
	 * @return array<string, mixed>
	 */
	private function load_subscription_data( int $sub_id ): array {
		$data = $this->handler->load_subscription( $sub_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve user → partner.
		$user_id = $data['user_id'] ?? 0;
		if ( $user_id <= 0 ) {
			$this->logger->warning( 'WC Subscription has no user.', [ 'sub_id' => $sub_id ] );
			return [];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning(
				'Cannot find user for WC Subscription.',
				[
					'sub_id'  => $sub_id,
					'user_id' => $user_id,
				]
			);
			return [];
		}

		$partner_id = $this->partner_service()->get_or_create(
			$user->user_email,
			[ 'name' => $user->display_name ],
			$user_id
		);

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for WC Subscription.', [ 'sub_id' => $sub_id ] );
			return [];
		}

		// Resolve product → Odoo product ID.
		$product_id      = $data['product_id'] ?? 0;
		$product_odoo_id = 0;
		if ( $product_id > 0 ) {
			$product_odoo_id = $this->get_mapping( 'product', $product_id ) ?? 0;
		}

		if ( ! $product_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for WC Subscription.', [ 'product_id' => $product_id ] );
			return [];
		}

		return $this->handler->format_subscription( $data, $product_odoo_id, $partner_id );
	}

	/**
	 * Load and resolve a renewal order with Odoo references.
	 *
	 * @param int $order_id WC renewal order ID.
	 * @return array<string, mixed>
	 */
	private function load_renewal_data( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->logger->warning( 'WC renewal order not found.', [ 'order_id' => $order_id ] );
			return [];
		}

		// Resolve customer → partner.
		$email   = $order->get_billing_email();
		$name    = $order->get_formatted_billing_full_name();
		$user_id = $order->get_customer_id();

		if ( empty( $email ) ) {
			$this->logger->warning( 'WC renewal order has no billing email.', [ 'order_id' => $order_id ] );
			return [];
		}

		$partner_id = $this->partner_service()->get_or_create(
			$email,
			[ 'name' => $name ],
			$user_id
		);

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for renewal order.', [ 'order_id' => $order_id ] );
			return [];
		}

		// Resolve product → Odoo product ID.
		$product_id      = $this->handler->get_product_id_for_renewal( $order_id );
		$product_odoo_id = 0;
		if ( $product_id > 0 ) {
			$product_odoo_id = $this->get_mapping( 'product', $product_id ) ?? 0;
		}

		if ( ! $product_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for renewal order.', [ 'product_id' => $product_id ] );
			return [];
		}

		$date_created = $order->get_date_created();

		$data = [
			'total'        => (float) $order->get_total(),
			'date'         => $date_created ? $date_created->format( 'Y-m-d' ) : '',
			'ref'          => 'WCS-' . $order_id,
			'product_name' => '',
		];

		$items = $order->get_items();
		if ( ! empty( $items ) ) {
			$first_item           = reset( $items );
			$data['product_name'] = $first_item['name'] ?? '';
		}

		return $this->handler->format_renewal_invoice( $data, $product_odoo_id, $partner_id );
	}

	// ─── Product sync ──────────────────────────────────────

	/**
	 * Ensure the subscription product is synced before a dependent entity.
	 *
	 * @param int    $wp_id       Entity ID.
	 * @param string $entity_type 'subscription' or 'renewal'.
	 * @return void
	 */
	private function ensure_product_synced( int $wp_id, string $entity_type ): void {
		$product_id = 0;

		if ( 'subscription' === $entity_type ) {
			$subscription = wcs_get_subscription( $wp_id );
			if ( $subscription ) {
				$items = $subscription->get_items();
				if ( ! empty( $items ) ) {
					$first_item = reset( $items );
					$product_id = (int) ( $first_item['product_id'] ?? 0 );
				}
			}
		} elseif ( 'renewal' === $entity_type ) {
			$product_id = $this->handler->get_product_id_for_renewal( $wp_id );
		}

		$this->ensure_entity_synced( 'product', $product_id );
	}
}
