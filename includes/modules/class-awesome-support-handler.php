<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Awesome Support Handler — data access for Awesome Support tickets.
 *
 * Awesome Support uses the `ticket` CPT with post meta for status
 * (_wpas_status) and priority (_wpas_priority).
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
class Awesome_Support_Handler extends Helpdesk_Handler_Base {

	/**
	 * Load an Awesome Support ticket by ID.
	 *
	 * @param int $ticket_id Ticket post ID.
	 * @return array<string, mixed> Ticket data, or empty if not found.
	 */
	public function load_ticket( int $ticket_id ): array {
		if ( $ticket_id <= 0 ) {
			return [];
		}

		$post = \get_post( $ticket_id );
		if ( ! $post || 'ticket' !== $post->post_type ) {
			$this->logger->warning( 'Awesome Support ticket not found.', [ 'ticket_id' => $ticket_id ] );
			return [];
		}

		$status   = \get_post_meta( $ticket_id, '_wpas_status', true );
		$priority = \get_post_meta( $ticket_id, '_wpas_priority', true );

		return [
			'name'        => $post->post_title,
			'description' => $post->post_content,
			'_user_id'    => (int) $post->post_author,
			'_wp_status'  => ( is_string( $status ) && '' !== $status ) ? $status : 'open',
			'priority'    => $this->map_priority( is_string( $priority ) ? $priority : '' ),
		];
	}

	/**
	 * Save a ticket status from Odoo pull.
	 *
	 * @param int    $ticket_id WordPress ticket ID.
	 * @param string $wp_status Target WP status ('open' or 'closed').
	 * @return bool True on success.
	 */
	public function save_ticket_status( int $ticket_id, string $wp_status ): bool {
		if ( $ticket_id <= 0 ) {
			return false;
		}

		\wpas_update_ticket_status( $ticket_id, $wp_status );

		return true;
	}
}
