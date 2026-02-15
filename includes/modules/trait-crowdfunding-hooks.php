<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Crowdfunding hook callbacks for push operations.
 *
 * Extracted from Crowdfunding_Module for single responsibility.
 * Handles campaign (WC product) save events, filtering for crowdfunding products.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - handler: Crowdfunding_Handler  (from Crowdfunding_Module)
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
trait Crowdfunding_Hooks {

	/**
	 * Handle WC product save — filter for crowdfunding campaigns.
	 *
	 * @param int $post_id The product post ID.
	 * @return void
	 */
	public function on_campaign_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		// Only sync products with crowdfunding meta.
		if ( ! $this->get_handler()->is_crowdfunding( $post_id ) ) {
			return;
		}

		$this->push_entity( 'crowdfunding', 'campaign', 'sync_campaigns', $post_id );
	}
}
