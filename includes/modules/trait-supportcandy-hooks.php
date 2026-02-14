<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SupportCandy hooks for ticket sync.
 *
 * Registers WordPress hooks for SupportCandy ticket lifecycle events
 * and enqueues sync jobs when tickets are created or their status changes.
 *
 * Composed into SupportCandy_Module via `use`.
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
trait SupportCandy_Hooks {

	/**
	 * Register SupportCandy hooks.
	 *
	 * @return void
	 */
	protected function register_hooks(): void {
		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_tickets'] ) ) {
			\add_action( 'wpsc_create_new_ticket', $this->safe_callback( [ $this, 'on_ticket_created' ] ), 10, 1 );
			\add_action( 'wpsc_change_ticket_status', $this->safe_callback( [ $this, 'on_ticket_status_changed' ] ), 10, 4 );
		}
	}

	/**
	 * Handle ticket creation.
	 *
	 * @param mixed $ticket Ticket object or array.
	 * @return void
	 */
	public function on_ticket_created( $ticket ): void {
		if ( ! $this->should_sync( 'sync_tickets' ) ) {
			return;
		}

		$ticket_id = $this->extract_ticket_id( $ticket );
		if ( $ticket_id <= 0 ) {
			return;
		}

		$this->enqueue_push( 'ticket', $ticket_id );
	}

	/**
	 * Handle ticket status change.
	 *
	 * SupportCandy fires wpsc_change_ticket_status with 4 args:
	 * ($ticket, $prev_status_id, $new_status_id, $customer_id).
	 *
	 * @param mixed $ticket        Ticket object.
	 * @param int   $prev_status   Previous status ID.
	 * @param int   $new_status    New status ID.
	 * @param int   $customer_id   Customer ID who changed the status.
	 * @return void
	 */
	public function on_ticket_status_changed( $ticket, int $prev_status = 0, int $new_status = 0, int $customer_id = 0 ): void {
		if ( ! $this->should_sync( 'sync_tickets' ) ) {
			return;
		}

		$ticket_id = $this->extract_ticket_id( $ticket );
		if ( $ticket_id <= 0 ) {
			return;
		}

		$this->enqueue_push( 'ticket', $ticket_id );
	}

	/**
	 * Extract a ticket ID from various input types.
	 *
	 * SupportCandy hooks pass ticket data in different formats.
	 *
	 * @param mixed $ticket Ticket data (object, array, or int).
	 * @return int Ticket ID, or 0 if not found.
	 */
	private function extract_ticket_id( $ticket ): int {
		if ( is_int( $ticket ) ) {
			return $ticket;
		}

		if ( is_object( $ticket ) && isset( $ticket->id ) ) {
			return (int) $ticket->id;
		}

		if ( is_array( $ticket ) && isset( $ticket['id'] ) ) {
			return (int) $ticket['id'];
		}

		return 0;
	}
}
