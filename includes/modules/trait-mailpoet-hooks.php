<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MailPoet hook callbacks for subscriber and list sync.
 *
 * Extracted from MailPoet_Module for single responsibility.
 * Handles subscriber creation/updates/deletes and list creation/updates.
 *
 * Expects the using class to provide:
 * - push_entity(string $type, string $key, int $id): void (from Module_Helpers)
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
trait MailPoet_Hooks {

	/**
	 * Register MailPoet hooks based on current settings.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_subscribers'] ) ) {
			add_action( 'mailpoet_subscriber_created', $this->safe_callback( [ $this, 'on_subscriber_created' ] ), 10, 1 );
			add_action( 'mailpoet_subscriber_updated', $this->safe_callback( [ $this, 'on_subscriber_updated' ] ), 10, 1 );
			add_action( 'mailpoet_subscriber_deleted', $this->safe_callback( [ $this, 'on_subscriber_deleted' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_lists'] ) ) {
			add_action( 'mailpoet_list_added', $this->safe_callback( [ $this, 'on_list_created' ] ), 10, 1 );
			add_action( 'mailpoet_list_updated', $this->safe_callback( [ $this, 'on_list_updated' ] ), 10, 1 );
		}
	}

	/**
	 * Enqueue a subscriber create/update job when a new subscriber is added.
	 *
	 * @param mixed $subscriber_id The MailPoet subscriber ID.
	 * @return void
	 */
	public function on_subscriber_created( $subscriber_id ): void {
		$id = is_numeric( $subscriber_id ) ? (int) $subscriber_id : 0;
		if ( $id > 0 ) {
			$this->push_entity( 'subscriber', 'sync_subscribers', $id );
		}
	}

	/**
	 * Enqueue a subscriber update job when a subscriber is updated.
	 *
	 * @param mixed $subscriber_id The MailPoet subscriber ID.
	 * @return void
	 */
	public function on_subscriber_updated( $subscriber_id ): void {
		$id = is_numeric( $subscriber_id ) ? (int) $subscriber_id : 0;
		if ( $id > 0 ) {
			$this->push_entity( 'subscriber', 'sync_subscribers', $id );
		}
	}

	/**
	 * Handle subscriber deletion.
	 *
	 * @param mixed $subscriber_id The MailPoet subscriber ID.
	 * @return void
	 */
	public function on_subscriber_deleted( $subscriber_id ): void {
		$id = is_numeric( $subscriber_id ) ? (int) $subscriber_id : 0;
		if ( $id > 0 ) {
			$this->push_entity( 'subscriber', 'sync_subscribers', $id );
		}
	}

	/**
	 * Enqueue a list create job when a new list is added.
	 *
	 * @param mixed $list_id The MailPoet list ID.
	 * @return void
	 */
	public function on_list_created( $list_id ): void {
		$id = is_numeric( $list_id ) ? (int) $list_id : 0;
		if ( $id > 0 ) {
			$this->push_entity( 'list', 'sync_lists', $id );
		}
	}

	/**
	 * Enqueue a list update job when a list is updated.
	 *
	 * @param mixed $list_id The MailPoet list ID.
	 * @return void
	 */
	public function on_list_updated( $list_id ): void {
		$id = is_numeric( $list_id ) ? (int) $list_id : 0;
		if ( $id > 0 ) {
			$this->push_entity( 'list', 'sync_lists', $id );
		}
	}
}
