<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dokan Marketplace Module — bidirectional multi-vendor marketplace sync.
 *
 * Syncs Dokan vendors as Odoo partners (res.partner with supplier_rank),
 * sub-orders as purchase orders (purchase.order), commissions as vendor
 * bills (account.move with move_type=in_invoice), and payouts as account
 * payments (account.payment).
 *
 * Vendors are bidirectional (push + pull status). Sub-orders, commissions,
 * and payouts are push-only (WP → Odoo).
 *
 * Requires WooCommerce + Dokan to be active.
 * In the `marketplace` exclusive group (priority 10).
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class Dokan_Module extends \WP4Odoo\Module_Base {

	use Dokan_Hooks;

	protected const PLUGIN_MIN_VERSION  = '3.7';
	protected const PLUGIN_TESTED_UP_TO = '4.2';

	/**
	 * Exclusive group: marketplace (only one marketplace module active).
	 *
	 * @var string
	 */
	protected string $exclusive_group = 'marketplace';

	/**
	 * Priority within the marketplace group.
	 *
	 * @var int
	 */
	protected int $exclusive_priority = 10;

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'vendor'     => 'res.partner',
		'sub_order'  => 'purchase.order',
		'commission' => 'account.move',
		'payout'     => 'account.payment',
	];

	/**
	 * Default field mappings.
	 *
	 * Vendor mappings rename WP keys to Odoo partner fields.
	 * Sub-order, commission, and payout mappings are identity
	 * (pre-formatted by handler).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'vendor'     => [
			'name'          => 'name',
			'email'         => 'email',
			'phone'         => 'phone',
			'street'        => 'street',
			'city'          => 'city',
			'zip'           => 'zip',
			'supplier_rank' => 'supplier_rank',
			'is_company'    => 'is_company',
		],
		'sub_order'  => [
			'partner_id' => 'partner_id',
			'date_order' => 'date_order',
			'origin'     => 'origin',
			'order_line' => 'order_line',
		],
		'commission' => [
			'move_type'        => 'move_type',
			'partner_id'       => 'partner_id',
			'invoice_date'     => 'invoice_date',
			'ref'              => 'ref',
			'invoice_line_ids' => 'invoice_line_ids',
		],
		'payout'     => [
			'partner_id'   => 'partner_id',
			'amount'       => 'amount',
			'date'         => 'date',
			'ref'          => 'ref',
			'payment_type' => 'payment_type',
			'partner_type' => 'partner_type',
		],
	];

	/**
	 * Dokan data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Dokan_Handler
	 */
	private Dokan_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'dokan', 'Dokan', $client_provider, $entity_map, $settings );
		$this->handler = new Dokan_Handler( $this->logger );
	}

	/**
	 * Required modules: WooCommerce must be active.
	 *
	 * @return string[]
	 */
	public function get_required_modules(): array {
		return [ 'woocommerce' ];
	}

	/**
	 * Boot the module: register Dokan hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! function_exists( 'dokan' ) ) {
			$this->logger->warning( __( 'Dokan module enabled but Dokan is not active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Sync direction: bidirectional (vendors pull status from Odoo).
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
			'sync_vendors'     => true,
			'sync_sub_orders'  => true,
			'sync_commissions' => true,
			'sync_payouts'     => false,
			'auto_post_bills'  => false,
			'pull_vendors'     => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_vendors'     => [
				'label'       => __( 'Sync vendors', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Dokan vendors to Odoo as supplier partners.', 'wp4odoo' ),
			],
			'sync_sub_orders'  => [
				'label'       => __( 'Sync sub-orders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Dokan sub-orders to Odoo as purchase orders.', 'wp4odoo' ),
			],
			'sync_commissions' => [
				'label'       => __( 'Sync commissions', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push vendor commissions to Odoo as vendor bills.', 'wp4odoo' ),
			],
			'sync_payouts'     => [
				'label'       => __( 'Sync payouts', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push approved withdrawal requests to Odoo as payments.', 'wp4odoo' ),
			],
			'auto_post_bills'  => [
				'label'       => __( 'Auto-post vendor bills', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm commission vendor bills in Odoo.', 'wp4odoo' ),
			],
			'pull_vendors'     => [
				'label'       => __( 'Pull vendor updates from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull vendor status changes from Odoo back to Dokan.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for Dokan.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( function_exists( 'dokan' ), 'Dokan' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'DOKAN_PLUGIN_VERSION' ) ? DOKAN_PLUGIN_VERSION : '';
	}

	/**
	 * Get the handler instance.
	 *
	 * @return Dokan_Handler
	 */
	public function get_handler(): Dokan_Handler {
		return $this->handler;
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Vendors dedup by email (res.partner). Sub-orders, commissions,
	 * and payouts dedup by ref/origin.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'vendor' === $entity_type && ! empty( $odoo_values['email'] ) ) {
			return [ [ 'email', '=', $odoo_values['email'] ] ];
		}

		if ( 'sub_order' === $entity_type && ! empty( $odoo_values['origin'] ) ) {
			return [ [ 'origin', '=', $odoo_values['origin'] ] ];
		}

		if ( 'commission' === $entity_type && ! empty( $odoo_values['ref'] ) ) {
			return [ [ 'ref', '=', $odoo_values['ref'] ] ];
		}

		if ( 'payout' === $entity_type && ! empty( $odoo_values['ref'] ) ) {
			return [ [ 'ref', '=', $odoo_values['ref'] ] ];
		}

		return [];
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push entity to Odoo.
	 *
	 * Override to:
	 * 1. Ensure vendor is synced before sub_order/commission/payout.
	 * 2. Force supplier_rank on vendor create.
	 * 3. Auto-post vendor bill when setting enabled.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      Action (create, update, delete).
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo record ID (0 for create).
	 * @param array  $payload     Additional payload data.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		// Ensure vendor is synced before dependent entity push.
		if ( in_array( $entity_type, [ 'sub_order', 'commission', 'payout' ], true ) && 'delete' !== $action ) {
			$vendor_id = $this->resolve_vendor_id( $entity_type, $wp_id );
			if ( $vendor_id > 0 ) {
				$this->ensure_entity_synced( 'vendor', $vendor_id );
			}
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		// Auto-post vendor bill for commissions.
		if ( $result->succeeded() && 'commission' === $entity_type && 'delete' !== $action ) {
			$this->auto_post_invoice( 'auto_post_bills', 'commission', $wp_id );
		}

		return $result;
	}

	// ─── Pull override ─────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Only vendor status can be pulled. Sub-orders, commissions, and
	 * payouts originate in WooCommerce/Dokan — pull not supported.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): Sync_Result {
		if ( in_array( $entity_type, [ 'sub_order', 'commission', 'payout' ], true ) ) {
			$this->logger->info(
				\sprintf( '%s pull not supported — originates in WooCommerce/Dokan.', $entity_type ),
				[ 'odoo_id' => $odoo_id ]
			);
			return Sync_Result::success();
		}

		if ( 'vendor' === $entity_type ) {
			$settings = $this->get_settings();
			if ( empty( $settings['pull_vendors'] ) ) {
				return Sync_Result::success();
			}
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'vendor' === $entity_type ) {
			return [
				'status' => ! empty( $odoo_data['active'] ) ? 'active' : 'inactive',
			];
		}

		return parent::map_from_odoo( $entity_type, $odoo_data );
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * Only vendor status updates are supported.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'vendor' === $entity_type && $wp_id > 0 ) {
			$status = $data['status'] ?? 'active';
			return $this->handler->save_vendor_status( $wp_id, $status ) ? $wp_id : 0;
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * Vendors, sub-orders, commissions, and payouts cannot be deleted from Odoo.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return false;
	}

	// ─── Data loading ───────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'vendor'     => $this->handler->load_vendor( $wp_id ),
			'sub_order'  => $this->load_sub_order_data( $wp_id ),
			'commission' => $this->load_commission_data( $wp_id ),
			'payout'     => $this->load_payout_data( $wp_id ),
			default      => [],
		};
	}

	/**
	 * Load sub-order data with resolved vendor partner ID.
	 *
	 * @param int $order_id WC sub-order ID.
	 * @return array<string, mixed>
	 */
	private function load_sub_order_data( int $order_id ): array {
		$vendor_id = $this->handler->get_vendor_id_for_order( $order_id );
		if ( ! $vendor_id ) {
			$this->logger->warning( 'Cannot resolve vendor for Dokan sub-order.', [ 'order_id' => $order_id ] );
			return [];
		}

		$partner_id = $this->get_mapping( 'vendor', $vendor_id );
		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo partner for vendor.',
				[
					'order_id'  => $order_id,
					'vendor_id' => $vendor_id,
				]
			);
			return [];
		}

		return $this->handler->load_sub_order( $order_id, $partner_id );
	}

	/**
	 * Load commission data with resolved vendor partner ID.
	 *
	 * @param int $order_id WC order ID (commission source).
	 * @return array<string, mixed>
	 */
	private function load_commission_data( int $order_id ): array {
		$vendor_id = $this->handler->get_vendor_id_for_order( $order_id );
		if ( ! $vendor_id ) {
			$this->logger->warning( 'Cannot resolve vendor for commission.', [ 'order_id' => $order_id ] );
			return [];
		}

		$partner_id = $this->get_mapping( 'vendor', $vendor_id );
		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo partner for vendor.',
				[
					'order_id'  => $order_id,
					'vendor_id' => $vendor_id,
				]
			);
			return [];
		}

		$amount = (float) dokan_get_earning_by_order( $order_id, 'admin' );

		return $this->handler->load_commission( $order_id, $partner_id, $amount );
	}

	/**
	 * Load payout data with resolved vendor partner ID.
	 *
	 * @param int $withdraw_id Dokan withdraw ID.
	 * @return array<string, mixed>
	 */
	private function load_payout_data( int $withdraw_id ): array {
		$vendor_id = $this->handler->get_vendor_id_for_withdraw( $withdraw_id );
		if ( ! $vendor_id ) {
			$this->logger->warning( 'Cannot resolve vendor for payout.', [ 'withdraw_id' => $withdraw_id ] );
			return [];
		}

		$partner_id = $this->get_mapping( 'vendor', $vendor_id );
		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo partner for vendor.',
				[
					'withdraw_id' => $withdraw_id,
					'vendor_id'   => $vendor_id,
				]
			);
			return [];
		}

		return $this->handler->load_payout( $withdraw_id, $partner_id );
	}

	/**
	 * Resolve the vendor user ID for a dependent entity.
	 *
	 * @param string $entity_type Entity type (sub_order, commission, payout).
	 * @param int    $wp_id       WordPress entity ID.
	 * @return int Vendor user ID, or 0 if not found.
	 */
	private function resolve_vendor_id( string $entity_type, int $wp_id ): int {
		return match ( $entity_type ) {
			'sub_order'  => $this->handler->get_vendor_id_for_order( $wp_id ),
			'commission' => $this->handler->get_vendor_id_for_order( $wp_id ),
			'payout'     => $this->handler->get_vendor_id_for_withdraw( $wp_id ),
			default      => 0,
		};
	}
}
