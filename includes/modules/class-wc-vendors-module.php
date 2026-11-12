<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Vendors Marketplace Module — bidirectional multi-vendor marketplace sync.
 *
 * Syncs WC Vendors vendors as Odoo partners (res.partner with supplier_rank),
 * sub-orders as purchase orders (purchase.order), commissions as vendor
 * bills (account.move with move_type=in_invoice), and payouts as account
 * payments (account.payment).
 *
 * Vendors are bidirectional (push + pull status). Sub-orders, commissions,
 * and payouts are push-only (WP → Odoo).
 *
 * Requires WooCommerce + WC Vendors to be active.
 * In the `marketplace` exclusive group (priority 20).
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
class WC_Vendors_Module extends Marketplace_Module_Base {

	use WC_Vendors_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '2.5';

	/**
	 * WC Vendors data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WC_Vendors_Handler
	 */
	private WC_Vendors_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_vendors', 'WC Vendors', $client_provider, $entity_map, $settings );
		$this->handler = new WC_Vendors_Handler( $this->logger );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_marketplace_name(): string {
		return 'WC Vendors';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_marketplace_handler(): Marketplace_Handler_Base {
		return $this->handler;
	}

	/**
	 * Get the typed handler instance.
	 *
	 * @return WC_Vendors_Handler
	 */
	public function get_handler(): WC_Vendors_Handler {
		return $this->handler;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_payout_description(): string {
		return __( 'Push approved vendor payments to Odoo as payments.', 'wp4odoo' );
	}

	/**
	 * Boot the module: register WC Vendors hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WCV_Vendors' ) ) {
			$this->logger->warning( __( 'WC Vendors module enabled but WC Vendors is not active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Whether the third-party plugin(s) this module integrates with are active.
	 *
	 * Single source of truth for module activation, shared by the registry
	 * and the dependency check.
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		return class_exists( 'WCV_Vendors' ) || defined( 'WCV_PRO_VERSION' );
	}

	/**
	 * Get external dependency status for WC Vendors.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( class_exists( 'WCV_Vendors' ), 'WC Vendors' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WCV_PRO_VERSION' ) ? WCV_PRO_VERSION : '';
	}

	// ─── Data loading (plugin-specific) ─────────────────────

	/**
	 * Load commission data with resolved vendor partner ID.
	 *
	 * @param int $order_id WC order ID (commission source).
	 * @return array<string, mixed>
	 */
	protected function load_commission_data( int $order_id ): array {
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

		$commission = $GLOBALS['_wcv_orders'][ $order_id ] ?? null;
		$amount     = $commission ? (float) ( $commission['commission'] ?? 0.0 ) : 0.0;

		return $this->handler->load_commission( $order_id, $partner_id, $amount );
	}

	/**
	 * Load payout data with resolved vendor partner ID.
	 *
	 * @param int $payout_id WC Vendors payout ID.
	 * @return array<string, mixed>
	 */
	protected function load_payout_data( int $payout_id ): array {
		$vendor_id = $this->handler->get_vendor_id_for_payout( $payout_id );
		if ( ! $vendor_id ) {
			$this->logger->warning( 'Cannot resolve vendor for payout.', [ 'payout_id' => $payout_id ] );
			return [];
		}

		$partner_id = $this->get_mapping( 'vendor', $vendor_id );
		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo partner for vendor.',
				[
					'payout_id' => $payout_id,
					'vendor_id' => $vendor_id,
				]
			);
			return [];
		}

		return $this->handler->load_payout( $payout_id, $partner_id );
	}

	/**
	 * Resolve the vendor user ID for a dependent entity.
	 *
	 * @param string $entity_type Entity type (sub_order, commission, payout).
	 * @param int    $wp_id       WordPress entity ID.
	 * @return int Vendor user ID, or 0 if not found.
	 */
	protected function resolve_vendor_id( string $entity_type, int $wp_id ): int {
		return match ( $entity_type ) {
			'sub_order'  => $this->handler->get_vendor_id_for_order( $wp_id ),
			'commission' => $this->handler->get_vendor_id_for_order( $wp_id ),
			'payout'     => $this->handler->get_vendor_id_for_payout( $wp_id ),
			default      => 0,
		};
	}
}
