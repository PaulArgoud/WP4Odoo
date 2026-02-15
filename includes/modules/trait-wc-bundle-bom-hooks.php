<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Bundle BOM hook callbacks for push operations.
 *
 * Extracted from WC_Bundle_BOM_Module for single responsibility.
 * Handles product save events, filtering for bundle/composite products.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - get_handler(): WC_Bundle_BOM_Handler  (from WC_Bundle_BOM_Module)
 *
 * @package WP4Odoo
 * @since   3.0.5
 */
trait WC_Bundle_BOM_Hooks {

	/**
	 * Handle WC product save — filter for bundle/composite products.
	 *
	 * @param int $post_id The product post ID.
	 * @return void
	 */
	public function on_bundle_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( ! $this->get_handler()->is_bundle_or_composite( $post_id ) ) {
			return;
		}

		$this->push_entity( 'wc_bundle_bom', 'bom', 'sync_bundles', $post_id );
	}
}
