<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ShopWP hook callbacks for push operations.
 *
 * Handles ShopWP product sync events via the `save_post_wps_products`
 * hook, which fires when ShopWP creates or updates Shopify product
 * custom post types.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
trait ShopWP_Hooks {

	/**
	 * Handle ShopWP product save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_product_save( int $post_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'wps_products' !== get_post_type( $post_id ) ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_products'] ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'product', $post_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'shopwp', 'product', $action, $post_id, $odoo_id );
	}
}
