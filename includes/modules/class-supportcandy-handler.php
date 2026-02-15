<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SupportCandy Handler — data access for SupportCandy custom tables.
 *
 * SupportCandy stores tickets in its own tables ({prefix}wpsc_ticket,
 * {prefix}wpsc_ticketmeta). This handler queries them via $wpdb.
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
class SupportCandy_Handler extends Helpdesk_Handler_Base {

	/**
	 * Load a SupportCandy ticket by ID.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return array<string, mixed> Ticket data, or empty if not found.
	 */
	public function load_ticket( int $ticket_id ): array {
		if ( $ticket_id <= 0 ) {
			return [];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wpsc_ticket';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $ticket_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->logger->warning( 'SupportCandy ticket not found.', [ 'ticket_id' => $ticket_id ] );
			return [];
		}

		$priority = $this->get_ticket_meta( $ticket_id, 'priority' );

		return [
			'name'        => $row['subject'] ?? '',
			'description' => $row['description'] ?? '',
			'_user_id'    => (int) ( $row['customer'] ?? 0 ),
			'_wp_status'  => $row['status'] ?? 'open',
			'priority'    => $this->map_priority( $priority ),
		];
	}

	/**
	 * Save a ticket status from Odoo pull.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $wp_status Target status.
	 * @return bool True on success.
	 */
	public function save_ticket_status( int $ticket_id, string $wp_status ): bool {
		if ( $ticket_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wpsc_ticket';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			$table,
			[ 'status' => $wp_status ],
			[ 'id' => $ticket_id ],
			[ '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Get a ticket meta value.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $key       Meta key.
	 * @return string Meta value, or empty string.
	 */
	private function get_ticket_meta( int $ticket_id, string $key ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'wpsc_ticketmeta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"SELECT meta_value FROM {$table} WHERE ticket_id = %d AND meta_key = %s",
				$ticket_id,
				$key
			)
		);

		return is_string( $value ) ? $value : '';
	}
}
