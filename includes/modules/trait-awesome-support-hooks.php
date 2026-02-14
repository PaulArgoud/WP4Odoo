<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Awesome Support hooks for ticket sync.
 *
 * Registers WordPress hooks for Awesome Support ticket lifecycle events
 * and enqueues sync jobs when tickets are created or their status changes.
 *
 * Composed into Awesome_Support_Module via `use`.
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
trait Awesome_Support_Hooks {

	/**
	 * Register Awesome Support hooks.
	 *
	 * @return void
	 */
	protected function register_hooks(): void {
		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_tickets'] ) ) {
			\add_action( 'wpas_open_ticket_after', $this->safe_callback( [ $this, 'on_ticket_created' ] ), 10, 2 );
			\add_action( 'wpas_after_close_ticket', $this->safe_callback( [ $this, 'on_ticket_status_updated' ] ), 10, 1 );
			\add_action( 'wpas_after_reopen_ticket', $this->safe_callback( [ $this, 'on_ticket_status_updated' ] ), 10, 1 );
		}
	}

	/**
	 * Handle ticket creation.
	 *
	 * @param int   $ticket_id Ticket post ID.
	 * @param array $data      Ticket data.
	 * @return void
	 */
	public function on_ticket_created( int $ticket_id, array $data = [] ): void {
		if ( ! $this->should_sync( 'sync_tickets' ) ) {
			return;
		}

		if ( $ticket_id <= 0 ) {
			return;
		}

		$this->enqueue_push( 'ticket', $ticket_id );
	}

	/**
	 * Handle ticket status change (close or reopen).
	 *
	 * @param int $ticket_id Ticket post ID.
	 * @return void
	 */
	public function on_ticket_status_updated( int $ticket_id ): void {
		if ( ! $this->should_sync( 'sync_tickets' ) ) {
			return;
		}

		if ( $ticket_id <= 0 ) {
			return;
		}

		$this->enqueue_push( 'ticket', $ticket_id );
	}
}
