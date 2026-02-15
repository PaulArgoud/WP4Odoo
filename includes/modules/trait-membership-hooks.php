<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Membership hook callbacks for push operations.
 *
 * Extracted from Memberships_Module for single responsibility.
 * Handles membership creation, status changes, and metadata saves.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
trait Membership_Hooks {

	/**
	 * Handle new user membership creation.
	 *
	 * @param \WC_Memberships_Membership_Plan $plan Membership plan.
	 * @param array                           $args Includes 'user_membership_id'.
	 * @return void
	 */
	public function on_membership_created( $plan, array $args ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$membership_id = (int) ( $args['user_membership_id'] ?? 0 );
		if ( ! $membership_id ) {
			return;
		}

		// Auto-push the plan if not yet mapped.
		if ( $this->should_sync( 'sync_plans' ) ) {
			$plan_id      = $plan->get_id();
			$odoo_plan_id = $this->get_mapping( 'plan', $plan_id );
			if ( ! $odoo_plan_id ) {
				Queue_Manager::push( 'memberships', 'plan', 'create', $plan_id, 0, [], 3 );
			}
		}

		// Push the membership line.
		if ( $this->should_sync( 'sync_memberships' ) ) {
			Queue_Manager::push( 'memberships', 'membership', 'create', $membership_id );
		}
	}

	/**
	 * Handle user membership status change.
	 *
	 * @param \WC_Memberships_User_Membership $user_membership User membership.
	 * @param string                          $old_status      Old status.
	 * @param string                          $new_status      New status.
	 * @return void
	 */
	public function on_membership_status_changed( $user_membership, string $old_status, string $new_status ): void {
		if ( ! $this->should_sync( 'sync_memberships' ) ) {
			return;
		}

		$membership_id = $user_membership->get_id();
		$odoo_id       = $this->get_mapping( 'membership', $membership_id ) ?? 0;

		Queue_Manager::push( 'memberships', 'membership', 'update', $membership_id, $odoo_id );
	}

	/**
	 * Handle user membership saved (catch-all for meta changes).
	 *
	 * @param \WC_Memberships_Membership_Plan $plan Membership plan.
	 * @param array                           $args Includes 'user_membership_id'.
	 * @return void
	 */
	public function on_membership_saved( $plan, array $args ): void {
		$membership_id = (int) ( $args['user_membership_id'] ?? 0 );
		if ( ! $membership_id ) {
			return;
		}

		$this->push_entity( 'memberships', 'membership', 'sync_memberships', $membership_id );
	}
}
