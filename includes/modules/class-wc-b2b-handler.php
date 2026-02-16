<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce B2B Handler — data access for B2B company accounts,
 * wholesale pricing rules, and payment terms.
 *
 * Loads WP user data for company accounts, wholesale price meta for
 * pricelist items, and stores pulled payment terms as wp_options.
 * Supports both Wholesale Suite (WWP) and B2BKing role/group detection.
 *
 * Called by WC_B2B_Module via its load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class WC_B2B_Handler {

	/**
	 * B2BKing group meta key.
	 *
	 * @var string
	 */
	private const B2BKING_GROUP_META = 'b2bking_customergroup';

	/**
	 * Option key for stored payment terms.
	 *
	 * @var string
	 */
	private const PAYMENT_TERMS_OPTION = 'wp4odoo_b2b_payment_terms';

	/**
	 * Wholesale Suite wholesale price meta keys.
	 *
	 * @var array<string>
	 */
	private const WWP_PRICE_META_KEYS = [
		'wholesale_customer_wholesale_price',
	];

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	// ─── Load company ─────────────────────────────────────

	/**
	 * Load a B2B company account from a WordPress user.
	 *
	 * Extracts billing company, email, VAT, phone, and wholesale role.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed> Company data, or empty array if not found/not wholesale.
	 */
	public function load_company( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning( 'B2B company user not found.', [ 'user_id' => $user_id ] );
			return [];
		}

		if ( ! $this->is_wholesale_user( $user_id ) ) {
			$this->logger->info( 'User is not a wholesale customer.', [ 'user_id' => $user_id ] );
			return [];
		}

		$billing_company = get_user_meta( $user_id, 'billing_company', true );
		$billing_email   = get_user_meta( $user_id, 'billing_email', true );
		$billing_vat     = get_user_meta( $user_id, 'billing_vat', true );
		$billing_phone   = get_user_meta( $user_id, 'billing_phone', true );

		if ( '' === $billing_company && '' === $billing_email ) {
			$billing_email = $user->user_email;
		}

		return [
			'billing_company' => $billing_company ?: $user->display_name,
			'billing_email'   => $billing_email ?: $user->user_email,
			'billing_vat'     => $billing_vat ?: '',
			'billing_phone'   => $billing_phone ?: '',
			'is_company'      => true,
			'wholesale_role'  => $this->get_wholesale_role( $user_id ),
		];
	}

	// ─── Load pricelist rule ──────────────────────────────

	/**
	 * Load wholesale pricing data for a product.
	 *
	 * Reads the wholesale price meta from a WC product.
	 *
	 * @param int $product_id WC product ID.
	 * @return array<string, mixed> Pricelist rule data, or empty array if no wholesale price.
	 */
	public function load_pricelist_rule( int $product_id ): array {
		$wholesale_price = $this->get_wholesale_price( $product_id );

		if ( null === $wholesale_price ) {
			return [];
		}

		return [
			'product_id'    => $product_id,
			'fixed_price'   => $wholesale_price,
			'compute_price' => 'fixed',
			'applied_on'    => '1_product',
		];
	}

	// ─── Save payment term ────────────────────────────────

	/**
	 * Save a pulled Odoo payment term to WordPress options.
	 *
	 * Stores payment terms as a JSON array in wp_options for admin selection.
	 *
	 * @param array<string, mixed> $data  Payment term data.
	 * @param int                  $wp_id WordPress ID (Odoo ID used as key).
	 * @return int The payment term ID (uses Odoo ID as identifier).
	 */
	public function save_payment_term( array $data, int $wp_id = 0 ): int {
		$terms = get_option( self::PAYMENT_TERMS_OPTION, [] );
		if ( ! is_array( $terms ) ) {
			$terms = [];
		}

		$term_id = $wp_id > 0 ? $wp_id : ( $data['odoo_id'] ?? 0 );
		if ( $term_id <= 0 ) {
			$this->logger->warning( 'Cannot save payment term without ID.', [ 'data' => $data ] );
			return 0;
		}

		$terms[ $term_id ] = [
			'name' => $data['name'] ?? '',
			'note' => $data['note'] ?? '',
		];

		update_option( self::PAYMENT_TERMS_OPTION, $terms );

		return $term_id;
	}

	// ─── Format methods (push to Odoo) ───────────────────

	/**
	 * Format company data for Odoo res.partner with is_company=true.
	 *
	 * Optionally adds partner category M2M ([6, 0, [ids]]) for wholesale
	 * customer grouping in Odoo.
	 *
	 * @param array<string, mixed> $data        Company data from load_company().
	 * @param int                  $category_id Odoo res.partner.category ID (0 to skip).
	 * @return array<string, mixed> Odoo-compatible field values.
	 */
	public function format_company_for_odoo( array $data, int $category_id = 0 ): array {
		$values = [
			'name'          => $data['billing_company'] ?? '',
			'email'         => $data['billing_email'] ?? '',
			'vat'           => $data['billing_vat'] ?? '',
			'phone'         => $data['billing_phone'] ?? '',
			'is_company'    => true,
			'customer_rank' => 1,
			'supplier_rank' => 0,
		];

		if ( $category_id > 0 ) {
			$values['category_id'] = [ [ 6, 0, [ $category_id ] ] ];
		}

		return $values;
	}

	/**
	 * Format a wholesale price as an Odoo product.pricelist.item record.
	 *
	 * @param float $price           Wholesale fixed price.
	 * @param int   $pricelist_id    Odoo product.pricelist ID.
	 * @param int   $product_tmpl_id Odoo product.template ID.
	 * @return array<string, mixed> Odoo-compatible pricelist item values.
	 */
	public function format_pricelist_rule_for_odoo( float $price, int $pricelist_id, int $product_tmpl_id ): array {
		return [
			'pricelist_id'    => $pricelist_id,
			'product_tmpl_id' => $product_tmpl_id,
			'fixed_price'     => $price,
			'compute_price'   => 'fixed',
			'applied_on'      => '1_product',
		];
	}

	// ─── Wholesale role detection ─────────────────────────

	/**
	 * Check if a user has a wholesale role (WWP or B2BKing).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True if the user is a wholesale customer.
	 */
	public function is_wholesale_user( int $user_id ): bool {
		// Check Wholesale Suite role.
		if ( function_exists( 'wwp_get_wholesale_role_for_user' ) ) {
			$role = wwp_get_wholesale_role_for_user( $user_id );
			if ( '' !== $role ) {
				return true;
			}
		}

		// Check B2BKing customer group.
		$b2bking_group = get_user_meta( $user_id, self::B2BKING_GROUP_META, true );
		if ( '' !== $b2bking_group && '0' !== $b2bking_group ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the wholesale role slug for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Wholesale role slug, or empty string.
	 */
	private function get_wholesale_role( int $user_id ): string {
		if ( function_exists( 'wwp_get_wholesale_role_for_user' ) ) {
			$role = wwp_get_wholesale_role_for_user( $user_id );
			if ( '' !== $role ) {
				return $role;
			}
		}

		$b2bking_group = get_user_meta( $user_id, self::B2BKING_GROUP_META, true );
		if ( '' !== $b2bking_group && '0' !== $b2bking_group ) {
			return 'b2bking_group_' . $b2bking_group;
		}

		return '';
	}

	/**
	 * Get the wholesale price for a product.
	 *
	 * Checks Wholesale Suite meta keys first, then B2BKing meta.
	 *
	 * @param int $product_id WC product ID.
	 * @return float|null Wholesale price, or null if not set.
	 */
	private function get_wholesale_price( int $product_id ): ?float {
		// Check Wholesale Suite function.
		if ( function_exists( 'wwp_get_product_wholesale_price' ) ) {
			$price = wwp_get_product_wholesale_price( $product_id, 'wholesale_customer' );
			if ( false !== $price && is_numeric( $price ) ) {
				return (float) $price;
			}
		}

		// Check meta keys directly.
		foreach ( self::WWP_PRICE_META_KEYS as $meta_key ) {
			$meta_value = get_post_meta( $product_id, $meta_key, true );
			if ( '' !== $meta_value && is_numeric( $meta_value ) ) {
				return (float) $meta_value;
			}
		}

		// Check B2BKing price meta.
		$b2bking_price = get_post_meta( $product_id, 'b2bking_regular_product_price_group_0', true );
		if ( '' !== $b2bking_price && is_numeric( $b2bking_price ) ) {
			return (float) $b2bking_price;
		}

		return null;
	}
}
