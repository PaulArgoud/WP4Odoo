<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AffiliateWP Module — push affiliate commissions to Odoo as vendor bills.
 *
 * Syncs AffiliateWP affiliates as Odoo partners (res.partner) and
 * referrals as vendor bills (account.move with move_type=in_invoice).
 * Push-only (WP → Odoo).
 *
 * Affiliates are automatically synced before their referrals via
 * ensure_entity_synced(). Vendor bills can be auto-posted when
 * the referral is marked as paid.
 *
 * Requires the AffiliateWP plugin to be active.
 * No exclusive group — coexists with all modules.
 *
 * @package WP4Odoo
 * @since   3.1.0
 */
class AffiliateWP_Module extends \WP4Odoo\Module_Base {

	use AffiliateWP_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.9';
	protected const PLUGIN_TESTED_UP_TO = '2.26';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'affiliate' => 'res.partner',
		'referral'  => 'account.move',
	];

	/**
	 * Default field mappings.
	 *
	 * Affiliate mappings rename WP keys to Odoo partner fields.
	 * Referral mappings are identity (WP key = Odoo key) because
	 * load_referral_data() returns pre-formatted Odoo data via
	 * Odoo_Accounting_Formatter::for_vendor_bill().
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'affiliate' => [
			'name'  => 'name',
			'email' => 'email',
			'phone' => 'phone',
		],
		'referral'  => [
			'move_type'        => 'move_type',
			'partner_id'       => 'partner_id',
			'invoice_date'     => 'invoice_date',
			'ref'              => 'ref',
			'invoice_line_ids' => 'invoice_line_ids',
		],
	];

	/**
	 * AffiliateWP data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var AffiliateWP_Handler
	 */
	private AffiliateWP_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'affiliatewp', 'AffiliateWP', $client_provider, $entity_map, $settings );
		$this->handler = new AffiliateWP_Handler( $this->logger );
	}

	/**
	 * Boot the module: register AffiliateWP hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! function_exists( 'affiliate_wp' ) ) {
			$this->logger->warning( __( 'AffiliateWP module enabled but AffiliateWP is not active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Sync direction: push only (WP → Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_affiliates' => true,
			'sync_referrals'  => true,
			'auto_post_bills' => false,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_affiliates' => [
				'label'       => __( 'Sync affiliates', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push approved affiliates to Odoo as partners.', 'wp4odoo' ),
			],
			'sync_referrals'  => [
				'label'       => __( 'Sync referrals', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push confirmed referrals to Odoo as vendor bills.', 'wp4odoo' ),
			],
			'auto_post_bills' => [
				'label'       => __( 'Auto-post vendor bills', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically confirm vendor bills in Odoo when referral is paid.', 'wp4odoo' ),
			],
		];
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
		return function_exists( 'affiliate_wp' );
	}

	/**
	 * Get external dependency status for AffiliateWP.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'AffiliateWP' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'AFFILIATEWP_VERSION' ) ? AFFILIATEWP_VERSION : '';
	}

	/**
	 * Get the handler instance.
	 *
	 * @return AffiliateWP_Handler
	 */
	public function get_handler(): AffiliateWP_Handler {
		return $this->handler;
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Affiliates dedup by email (res.partner). Referrals dedup by
	 * invoice ref (account.move).
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'affiliate' === $entity_type && ! empty( $odoo_values['email'] ) ) {
			return [ [ 'email', '=', $odoo_values['email'] ] ];
		}

		if ( 'referral' === $entity_type && ! empty( $odoo_values['ref'] ) ) {
			return [ [ 'ref', '=', $odoo_values['ref'] ] ];
		}

		return [];
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push entity to Odoo.
	 *
	 * Override to:
	 * 1. Auto-sync affiliate before referral push.
	 * 2. Auto-post vendor bill when referral is paid.
	 *
	 * @param string $entity_type Entity type (affiliate or referral).
	 * @param string $action      Action (create, update, delete).
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo record ID (0 for create).
	 * @param array  $payload     Additional payload data.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		// Ensure affiliate is synced before referral push.
		if ( 'referral' === $entity_type && 'delete' !== $action ) {
			$affiliate_id = $this->get_affiliate_id_for_referral( $wp_id );
			if ( $affiliate_id > 0 ) {
				$this->ensure_entity_synced( 'affiliate', $affiliate_id );
			}
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		// Auto-post vendor bill when referral is paid.
		if ( $result->succeeded() && 'referral' === $entity_type && 'delete' !== $action ) {
			$referral = affwp_get_referral( $wp_id );
			if ( $referral && 'paid' === $referral->status ) {
				$this->auto_post_invoice( 'auto_post_bills', 'referral', $wp_id );
			}
		}

		return $result;
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
			'affiliate' => $this->load_affiliate_data( $wp_id ),
			'referral'  => $this->load_referral_data( $wp_id ),
			default     => [],
		};
	}

	/**
	 * Load affiliate data as partner fields.
	 *
	 * Uses the affiliate's payment_email (preferred) or the WP user email.
	 *
	 * @param int $affiliate_id Affiliate ID.
	 * @return array<string, mixed> Partner data, or empty if not found.
	 */
	private function load_affiliate_data( int $affiliate_id ): array {
		$affiliate = affwp_get_affiliate( $affiliate_id );
		if ( ! $affiliate ) {
			$this->logger->warning( 'AffiliateWP affiliate not found.', [ 'affiliate_id' => $affiliate_id ] );
			return [];
		}

		$user = get_userdata( $affiliate->user_id );
		if ( ! $user ) {
			$this->logger->warning(
				'WordPress user not found for affiliate.',
				[
					'affiliate_id' => $affiliate_id,
					'user_id'      => $affiliate->user_id,
				]
			);
			return [];
		}

		return [
			'name'  => $user->display_name,
			'email' => $affiliate->payment_email ?: $user->user_email,
			'phone' => (string) get_user_meta( $affiliate->user_id, 'billing_phone', true ),
		];
	}

	/**
	 * Load referral data as vendor bill fields.
	 *
	 * Resolves the affiliate to an Odoo partner via entity_map,
	 * then delegates to the handler for Odoo formatting.
	 *
	 * @param int $referral_id Referral ID.
	 * @return array<string, mixed> Vendor bill data, or empty if not found.
	 */
	private function load_referral_data( int $referral_id ): array {
		$referral = affwp_get_referral( $referral_id );
		if ( ! $referral ) {
			$this->logger->warning( 'AffiliateWP referral not found.', [ 'referral_id' => $referral_id ] );
			return [];
		}

		$partner_id = $this->get_mapping( 'affiliate', $referral->affiliate_id );
		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo partner for affiliate.',
				[
					'referral_id'  => $referral_id,
					'affiliate_id' => $referral->affiliate_id,
				]
			);
			return [];
		}

		return $this->handler->load_referral( $referral_id, $partner_id );
	}

	/**
	 * Get the affiliate ID for a referral.
	 *
	 * @param int $referral_id Referral ID.
	 * @return int Affiliate ID, or 0 if not found.
	 */
	private function get_affiliate_id_for_referral( int $referral_id ): int {
		$referral = affwp_get_referral( $referral_id );
		return $referral ? (int) $referral->affiliate_id : 0;
	}
}
