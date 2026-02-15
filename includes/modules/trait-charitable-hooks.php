<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

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
		$this->handle_cpt_save( $post_id, 'campaign', 'sync_campaigns', 'campaign' );
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
		if ( 'donation' !== $post->post_type ) {
			return;
		}

		// Only sync completed or refunded donations.
		if ( ! in_array( $new_status, [ 'charitable-completed', 'charitable-refunded' ], true ) ) {
			return;
		}

		$this->push_entity( 'charitable', 'donation', 'sync_donations', $post->ID );
	}
}
