<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FooEvents hook callbacks for push operations.
 *
 * Extracted from FooEvents_Module for single responsibility.
 * Handles product saves (WC products marked as FooEvents events)
 * and ticket creation (event_magic_tickets CPT).
 *
 * Priority 20 for product hooks — after WooCommerce module (priority 10).
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 * - handler: FooEvents_Handler     (from FooEvents_Module)
 *
 * @package WP4Odoo
 * @since   3.8.0
 */
trait FooEvents_Hooks {

	/**
	 * Register FooEvents hooks based on module settings.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_events'] ) ) {
			\add_action( 'save_post_product', $this->safe_callback( [ $this, 'on_event_product_save' ] ), 20, 1 );
		}

		if ( ! empty( $settings['sync_attendees'] ) ) {
			\add_action( 'save_post_event_magic_tickets', $this->safe_callback( [ $this, 'on_ticket_save' ] ), 10, 1 );
		}
	}

	/**
	 * Handle WC product save — only for FooEvents event products.
	 *
	 * Priority 20 to run after WooCommerce module (priority 10).
	 *
	 * @param int $post_id The product post ID.
	 * @return void
	 */
	public function on_event_product_save( int $post_id ): void {
		if ( ! $this->should_sync( 'sync_events' ) ) {
			return;
		}

		if ( \wp_is_post_revision( $post_id ) || \wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( \get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		// Only handle products marked as FooEvents events.
		if ( 'Event' !== \get_post_meta( $post_id, 'WooCommerceEventsEvent', true ) ) {
			return;
		}

		$this->enqueue_push( 'event', $post_id );
	}

	/**
	 * Handle event_magic_tickets post save (attendee/ticket creation).
	 *
	 * @param int $post_id The ticket post ID.
	 * @return void
	 */
	public function on_ticket_save( int $post_id ): void {
		if ( \wp_is_post_revision( $post_id ) || \wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->push_entity( 'attendee', 'sync_attendees', $post_id );
	}
}
