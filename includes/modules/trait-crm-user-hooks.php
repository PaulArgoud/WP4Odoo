<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress user hook callbacks for CRM contact sync.
 *
 * Extracted from CRM_Module for single responsibility.
 * Handles user registration, profile updates, and user deletion.
 *
 * Expects the using class to provide:
 * - is_importing(): bool                   (from Module_Base)
 * - get_mapping(): ?int                    (from Module_Base)
 * - get_settings(): array                  (from Module_Base)
 * - contact_manager: Contact_Manager       (private property)
 * - logger: Logger                         (from Module_Base)
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
trait CRM_User_Hooks {

	/**
	 * Enqueue a contact create job when a new user registers.
	 *
	 * @param int   $user_id  The new user ID.
	 * @param array $userdata Data passed to wp_insert_user.
	 * @return void
	 */
	public function on_user_register( int $user_id, array $userdata = [] ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( ! $this->contact_manager->should_sync_user( $user_id ) ) {
			return;
		}

		Queue_Manager::push( 'crm', 'contact', 'create', $user_id );
		$this->logger->info( 'Enqueued contact create.', [ 'wp_id' => $user_id ] );
	}

	/**
	 * Enqueue a contact update job when a user profile is updated.
	 *
	 * @param int      $user_id       The user ID.
	 * @param \WP_User $old_user_data The old user object before update.
	 * @param array    $userdata      The updated user data.
	 * @return void
	 */
	public function on_profile_update( int $user_id, \WP_User $old_user_data, array $userdata = [] ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( ! $this->contact_manager->should_sync_user( $user_id ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'contact', $user_id );
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'crm', 'contact', $action, $user_id, $odoo_id );
		$this->logger->info( "Enqueued contact {$action}.", [ 'wp_id' => $user_id ] );
	}

	/**
	 * Handle user deletion: archive or delete the Odoo contact.
	 *
	 * @param int      $user_id  The user ID being deleted.
	 * @param int|null $reassign ID of user to reassign posts to, or null.
	 * @param \WP_User $user     The user object.
	 * @return void
	 */
	public function on_delete_user( int $user_id, ?int $reassign, \WP_User $user ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'contact', $user_id );
		if ( ! $odoo_id ) {
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['archive_on_delete'] ) ) {
			Queue_Manager::push( 'crm', 'contact', 'update', $user_id, $odoo_id, [ '_archive' => true ] );
			$this->logger->info(
				'Enqueued contact archive.',
				[
					'wp_id'   => $user_id,
					'odoo_id' => $odoo_id,
				]
			);
		} else {
			Queue_Manager::push( 'crm', 'contact', 'delete', $user_id, $odoo_id );
			$this->logger->info(
				'Enqueued contact delete.',
				[
					'wp_id'   => $user_id,
					'odoo_id' => $odoo_id,
				]
			);
		}
	}
}
