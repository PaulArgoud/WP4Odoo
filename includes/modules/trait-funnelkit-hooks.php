<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FunnelKit hook callbacks for contact and step sync.
 *
 * Extracted from FunnelKit_Module for single responsibility.
 * Handles contact creation/updates and step saves.
 *
 * Expects the using class to provide:
 * - should_sync(string $key): bool (from Module_Base)
 * - get_mapping(string $type, int $id): ?int (from Module_Base)
 * - enqueue_push(string $type, int $id): void (from Module_Base)
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
trait FunnelKit_Hooks {

	/**
	 * Enqueue a contact create job when a contact is created.
	 *
	 * Fired on bwfan_contact_created.
	 *
	 * @param int $contact_id The FunnelKit contact ID.
	 * @return void
	 */
	public function on_contact_created( int $contact_id ): void {
		if ( ! $this->should_sync( 'sync_contacts' ) ) {
			return;
		}

		if ( $contact_id <= 0 ) {
			return;
		}

		Queue_Manager::push( 'funnelkit', 'contact', 'create', $contact_id );
	}

	/**
	 * Enqueue a contact update job when a contact is updated.
	 *
	 * Fired on bwfan_contact_updated.
	 *
	 * @param int $contact_id The FunnelKit contact ID.
	 * @return void
	 */
	public function on_contact_updated( int $contact_id ): void {
		if ( ! $this->should_sync( 'sync_contacts' ) ) {
			return;
		}

		if ( $contact_id <= 0 ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'contact', $contact_id ) ?? 0;
		Queue_Manager::push( 'funnelkit', 'contact', 'update', $contact_id, $odoo_id );
	}

	/**
	 * Enqueue a step sync job when a funnel step is saved.
	 *
	 * Fired on save_post_wffn_step.
	 *
	 * @param int $step_id The step (post) ID.
	 * @return void
	 */
	public function on_step_saved( int $step_id ): void {
		if ( ! $this->should_sync( 'sync_steps' ) ) {
			return;
		}

		if ( $step_id <= 0 ) {
			return;
		}

		$this->enqueue_push( 'step', $step_id );
	}
}
