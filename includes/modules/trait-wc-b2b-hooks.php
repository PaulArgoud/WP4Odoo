<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce B2B hook callbacks for push operations.
 *
 * Extracted from WC_B2B_Module for single responsibility.
 * Handles user registration/update for wholesale company accounts
 * and product saves for wholesale pricing rules.
 *
 * Expects the using class to provide:
 * - should_sync(): bool             (from Module_Base)
 * - push_entity(): void             (from Sync_Helpers)
 * - safe_callback(): \Closure       (from Module_Base)
 * - logger: Logger                  (from Module_Base)
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
trait WC_B2B_Hooks {

	/**
	 * Register WordPress hooks for B2B sync.
	 *
	 * @return void
	 */
	protected function register_hooks(): void {
		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_companies'] ) ) {
			\add_action( 'user_register', $this->safe_callback( [ $this, 'on_user_registered' ] ), 10, 1 );
			\add_action( 'profile_update', $this->safe_callback( [ $this, 'on_profile_updated' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_pricelist_rules'] ) ) {
			\add_action( 'save_post_product', $this->safe_callback( [ $this, 'on_product_wholesale_price_updated' ] ), 20, 1 );
		}

		// Wholesale Suite Premium role hooks (pricelist sync).
		if ( ! empty( $settings['sync_pricelists'] ) ) {
			\add_action( 'wwp_wholesale_role_created', $this->safe_callback( [ $this, 'on_wholesale_role_changed' ] ), 10, 1 );
			\add_action( 'wwp_wholesale_role_updated', $this->safe_callback( [ $this, 'on_wholesale_role_changed' ] ), 10, 1 );
		}
	}

	/**
	 * Handle new user registration — sync if wholesale role.
	 *
	 * @param int $user_id The new user ID.
	 * @return void
	 */
	public function on_user_registered( int $user_id ): void {
		if ( ! $this->handler->is_wholesale_user( $user_id ) ) {
			return;
		}

		$this->push_entity( 'company', 'sync_companies', $user_id );
	}

	/**
	 * Handle user profile update — sync if wholesale role.
	 *
	 * @param int $user_id The updated user ID.
	 * @return void
	 */
	public function on_profile_updated( int $user_id ): void {
		if ( ! $this->handler->is_wholesale_user( $user_id ) ) {
			return;
		}

		$this->push_entity( 'company', 'sync_companies', $user_id );
	}

	/**
	 * Handle product save — sync wholesale price if set.
	 *
	 * Checks whether the product has a wholesale price meta value
	 * and enqueues a pricelist rule push if so.
	 *
	 * @param int $post_id The product post ID.
	 * @return void
	 */
	/**
	 * Handle wholesale role create/update (Wholesale Suite Premium).
	 *
	 * Resolves the role slug to its 1-based index in the wholesale roles
	 * list (used as a synthetic WP ID for the pricelist entity).
	 *
	 * @param string $role_slug The wholesale role slug.
	 * @return void
	 */
	public function on_wholesale_role_changed( string $role_slug ): void {
		$roles = $this->handler->get_all_wholesale_roles();
		$index = 0;

		foreach ( array_keys( $roles ) as $slug ) {
			++$index;
			if ( $slug === $role_slug ) {
				$this->push_entity( 'pricelist', 'sync_pricelists', $index );
				return;
			}
		}
	}

	public function on_product_wholesale_price_updated( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$rule_data = $this->handler->load_pricelist_rule( $post_id );
		if ( empty( $rule_data ) ) {
			return;
		}

		$this->push_entity( 'pricelist_rule', 'sync_pricelist_rules', $post_id );
	}
}
