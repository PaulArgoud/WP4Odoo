<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Charitable hook callbacks for push operations.
 *
 * Extracted from Charitable_Module for single responsibility.
 * Handles campaign saves and donation status changes.
 *
 * Recurring donations are handled transparently: each recurring
 * payment fires the same transition_post_status hook, so every
 * instalment is pushed to Odoo automatically.
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
trait Charitable_Hooks {

	/**
	 * Handle WP Charitable campaign save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_campaign_save( int $post_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'campaign' !== get_post_type( $post_id ) ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_campaigns'] ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'campaign', $post_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'charitable', 'campaign', $action, $post_id, $odoo_id );
	}

	/**
	 * Handle WP Charitable donation status change.
	 *
	 * Uses the standard transition_post_status hook, filtered for the
	 * 'donation' post type. Only pushes completed or refunded donations.
	 * Covers both one-time and recurring donations.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       The post object.
	 * @return void
	 */
	public function on_donation_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( 'donation' !== $post->post_type ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_donations'] ) ) {
			return;
		}

		// Only sync completed or refunded donations.
		if ( ! in_array( $new_status, [ 'charitable-completed', 'charitable-refunded' ], true ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'donation', $post->ID ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'charitable', 'donation', $action, $post->ID, $odoo_id );
	}
}
