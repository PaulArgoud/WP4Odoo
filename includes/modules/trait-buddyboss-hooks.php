<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BuddyBoss hook callbacks for profile, group, and group membership sync.
 *
 * Extracted from BuddyBoss_Module for single responsibility.
 * Handles xprofile updates, user activation, user deletion,
 * group save, and group membership changes.
 *
 * Expects the using class to provide:
 * - should_sync(string $key): bool (from Module_Base)
 * - get_mapping(string $type, int $id): ?int (from Module_Base)
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
trait BuddyBoss_Hooks {

	/**
	 * Enqueue a profile sync job when xprofile data is updated.
	 *
	 * @param int   $user_id          User ID.
	 * @param array $posted_field_ids Array of field IDs that were posted.
	 * @param bool  $errors           Whether there were errors.
	 * @param array $old_values       Old field values.
	 * @param array $new_values       New field values.
	 * @return void
	 */
	public function on_profile_updated( int $user_id, array $posted_field_ids = [], bool $errors = false, array $old_values = [], array $new_values = [] ): void {
		if ( ! $this->should_sync( 'sync_profiles' ) ) {
			return;
		}

		if ( $user_id <= 0 ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'profile', $user_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'buddyboss', 'profile', $action, $user_id, $odoo_id );
	}

	/**
	 * Enqueue a profile create job when a user is activated.
	 *
	 * @param int $user_id Activated user ID.
	 * @return void
	 */
	public function on_user_activated( int $user_id ): void {
		if ( ! $this->should_sync( 'sync_profiles' ) ) {
			return;
		}

		if ( $user_id <= 0 ) {
			return;
		}

		Queue_Manager::push( 'buddyboss', 'profile', 'create', $user_id );
	}

	/**
	 * Handle user deletion: push a delete job for the mapped profile.
	 *
	 * @param int $user_id The user ID being deleted.
	 * @return void
	 */
	public function on_user_delete( int $user_id ): void {
		if ( ! $this->should_sync( 'sync_profiles' ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'profile', $user_id );
		if ( ! $odoo_id ) {
			return;
		}

		Queue_Manager::push( 'buddyboss', 'profile', 'delete', $user_id, $odoo_id );
	}

	/**
	 * Enqueue a group sync job when a group is saved.
	 *
	 * @param object $group The BuddyPress group object.
	 * @return void
	 */
	public function on_group_saved( object $group ): void {
		if ( ! $this->should_sync( 'sync_groups' ) ) {
			return;
		}

		$group_id = (int) ( $group->id ?? 0 );
		if ( $group_id <= 0 ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'group', $group_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'buddyboss', 'group', $action, $group_id, $odoo_id );
	}

	/**
	 * Re-push a profile when group membership changes.
	 *
	 * When a user joins or leaves a group, re-sync their profile
	 * to update the category_id Many2many field in Odoo.
	 *
	 * @param int $group_id BuddyPress group ID.
	 * @param int $user_id  WordPress user ID.
	 * @return void
	 */
	public function on_group_member_changed( int $group_id, int $user_id ): void {
		if ( ! $this->should_sync( 'sync_group_members' ) ) {
			return;
		}

		if ( ! $this->should_sync( 'sync_profiles' ) ) {
			return;
		}

		if ( $user_id <= 0 ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'profile', $user_id ) ?? 0;
		if ( $odoo_id <= 0 ) {
			return;
		}

		Queue_Manager::push( 'buddyboss', 'profile', 'update', $user_id, $odoo_id );
	}
}
