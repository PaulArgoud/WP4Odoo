<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Recipe Maker hook callbacks for push operations.
 *
 * Extracted from WPRM_Module for single responsibility.
 * Handles recipe save events.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
trait WPRM_Hooks {

	/**
	 * Handle WP Recipe Maker recipe save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_recipe_save( int $post_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'wprm_recipe' !== get_post_type( $post_id ) ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_recipes'] ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'recipe', $post_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'wprm', 'recipe', $action, $post_id, $odoo_id );
	}
}
