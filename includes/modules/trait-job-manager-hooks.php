<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Job Manager hook callbacks for push operations.
 *
 * Extracted from Job_Manager_Module for single responsibility.
 * Handles job listing save and expiry events.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - handle_cpt_save(): void        (from Module_Base)
 * - enqueue_push(): void           (from Module_Base)
 *
 * @package WP4Odoo
 * @since   2.10.0
 */
trait Job_Manager_Hooks {

	/**
	 * Handle job_listing post save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_job_save( int $post_id ): void {
		$this->handle_cpt_save( $post_id, 'job_listing', 'sync_jobs', 'job' );
	}

	/**
	 * Handle job listing expiry.
	 *
	 * WP Job Manager fires job_listing_expired when a listing expires.
	 * We enqueue a push to update the Odoo state to 'open' (not recruiting).
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_job_expired( int $post_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_jobs'] ) ) {
			return;
		}

		$this->enqueue_push( 'job', $post_id );
	}
}
