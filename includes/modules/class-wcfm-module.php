<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCFM Marketplace Module — bidirectional multi-vendor marketplace sync.
 *
 * Syncs WCFM vendors as Odoo partners (res.partner with supplier_rank),
 * sub-orders as purchase orders (purchase.order), commissions as vendor
 * bills (account.move with move_type=in_invoice), and payouts as account
 * payments (account.payment).
 *
 * Vendors are bidirectional (push + pull status). Sub-orders, commissions,
 * and payouts are push-only (WP → Odoo).
 *
 * Requires WooCommerce + WCFM Marketplace to be active.
 * In the `marketplace` exclusive group (priority 15).
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
class WCFM_Module extends Marketplace_Module_Base {

	use WCFM_Hooks;

	protected const PLUGIN_MIN_VERSION  = '6.5';
	protected const PLUGIN_TESTED_UP_TO = '6.7';

	/**
	 * WCFM data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WCFM_Handler
	 */
	private WCFM_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wcfm', 'WCFM Marketplace', $client_provider, $entity_map, $settings );
		$this->handler = new WCFM_Handler( $this->logger );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_marketplace_name(): string {
		return 'WCFM Marketplace';
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
	 * @return WCFM_Handler
	 */
	public function get_handler(): WCFM_Handler {
		return $this->handler;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_payout_description(): string {
		return __( 'Push approved withdrawals to Odoo as payments.', 'wp4odoo' );
	}

	/**
	 * Boot the module: register WCFM hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'WCFM_VERSION' ) ) {
			$this->logger->warning( __( 'WCFM module enabled but WCFM Marketplace is not active.', 'wp4odoo' ) );
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
		return defined( 'WCFM_VERSION' );
	}

	/**
	 * Get external dependency status for WCFM.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'WCFM Marketplace' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WCFM_VERSION' ) ? WCFM_VERSION : '';
	}

	// ─── Data loading (plugin-specific) ─────────────────────

	/**
	 * Load commission data with resolved vendor partner ID.
	 *
	 * @param int $commission_id WCFM commission ID.
	 * @return array<string, mixed>
	 */
	protected function load_commission_data( int $commission_id ): array {
		$commission = wcfm_get_commission( $commission_id );
		if ( ! $commission ) {
			$this->logger->warning( 'WCFM commission not found.', [ 'commission_id' => $commission_id ] );
			return [];
		}

		$vendor_id = isset( $commission->vendor_id ) ? (int) $commission->vendor_id : 0;
		$order_id  = isset( $commission->order_id ) ? (int) $commission->order_id : 0;
		$amount    = isset( $commission->total_commission ) ? (float) $commission->total_commission : 0.0;

		if ( ! $vendor_id ) {
			$this->logger->warning( 'Cannot resolve vendor for commission.', [ 'commission_id' => $commission_id ] );
			return [];
		}

		$partner_id = $this->get_mapping( 'vendor', $vendor_id );
		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo partner for vendor.',
				[
					'commission_id' => $commission_id,
					'vendor_id'     => $vendor_id,
				]
			);
			return [];
		}

		return $this->handler->load_commission( $commission_id, $order_id, $partner_id, $amount );
	}

	/**
	 * Load payout data with resolved vendor partner ID.
	 *
	 * @param int $withdrawal_id WCFM withdrawal ID.
	 * @return array<string, mixed>
	 */
	protected function load_payout_data( int $withdrawal_id ): array {
		$vendor_id = $this->handler->get_vendor_id_for_withdrawal( $withdrawal_id );
		if ( ! $vendor_id ) {
			$this->logger->warning( 'Cannot resolve vendor for WCFM withdrawal.', [ 'withdrawal_id' => $withdrawal_id ] );
			return [];
		}

		$partner_id = $this->get_mapping( 'vendor', $vendor_id );
		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo partner for vendor.',
				[
					'withdrawal_id' => $withdrawal_id,
					'vendor_id'     => $vendor_id,
				]
			);
			return [];
		}

		return $this->handler->load_payout( $withdrawal_id, $partner_id );
	}

	/**
	 * Resolve the vendor user ID for a dependent entity.
	 *
	 * @param string $entity_type Entity type (sub_order, commission, payout).
	 * @param int    $wp_id       WordPress entity ID.
	 * @return int Vendor user ID, or 0 if not found.
	 */
	protected function resolve_vendor_id( string $entity_type, int $wp_id ): int {
		if ( 'sub_order' === $entity_type ) {
			return $this->handler->get_vendor_id_for_order( $wp_id );
		}

		if ( 'commission' === $entity_type ) {
			$commission = wcfm_get_commission( $wp_id );
			return $commission && isset( $commission->vendor_id ) ? (int) $commission->vendor_id : 0;
		}

		if ( 'payout' === $entity_type ) {
			return $this->handler->get_vendor_id_for_withdrawal( $wp_id );
		}

		return 0;
	}
}
