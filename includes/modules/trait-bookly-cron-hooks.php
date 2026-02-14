<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bookly polling via WP-Cron.
 *
 * Unlike other modules that use WordPress hooks for real-time sync,
 * Bookly has NO WordPress hooks for booking lifecycle events. This trait
 * implements a WP-Cron-based polling approach that scans Bookly tables
 * every 5 minutes and detects changes via SHA-256 hash comparison.
 *
 * Expects the using class to provide:
 * - is_importing(): bool              (from Module_Base)
 * - get_settings(): array             (from Module_Base)
 * - poll_entity_changes(): void       (from Module_Base)
 * - logger: Logger                    (from Module_Base)
 * - handler: Bookly_Handler           (from Bookly_Module)
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
trait Bookly_Cron_Hooks {

	/**
	 * Register the WP-Cron polling event.
	 *
	 * Called by boot(). Schedules wp4odoo_bookly_poll on the existing
	 * wp4odoo_five_minutes interval.
	 *
	 * @return void
	 */
	protected function register_cron(): void {
		if ( ! class_exists( 'Bookly\Lib\Plugin' ) ) {
			$this->logger->warning( __( 'Bookly module enabled but Bookly is not active.', 'wp4odoo' ) );
			return;
		}

		if ( ! wp_next_scheduled( 'wp4odoo_bookly_poll' ) ) {
			wp_schedule_event( time(), 'wp4odoo_five_minutes', 'wp4odoo_bookly_poll' );
		}

		add_action( 'wp4odoo_bookly_poll', [ $this, 'poll' ] );
	}

	/**
	 * Poll Bookly tables for changes.
	 *
	 * Compares current Bookly data against entity_map records using
	 * SHA-256 hashes to detect creates, updates, and deletions.
	 *
	 * @return void
	 */
	public function poll(): void {
		if ( $this->is_importing() ) {
			return;
		}

		global $wpdb;
		$lock_name = 'wp4odoo_bookly_poll';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		if ( '1' !== $acquired ) {
			return;
		}

		try {
			$settings = $this->get_settings();

			if ( ! empty( $settings['sync_services'] ) ) {
				try {
					$this->poll_entity_changes( 'service', $this->handler->get_all_services() );
				} catch ( \Throwable $e ) {
					$this->logger->critical(
						'Bookly service polling crashed (graceful degradation).',
						[
							'exception' => get_class( $e ),
							'message'   => $e->getMessage(),
						]
					);
				}
			}

			if ( ! empty( $settings['sync_bookings'] ) ) {
				try {
					$this->poll_entity_changes( 'booking', $this->handler->get_active_bookings() );
				} catch ( \Throwable $e ) {
					$this->logger->critical(
						'Bookly booking polling crashed (graceful degradation).',
						[
							'exception' => get_class( $e ),
							'message'   => $e->getMessage(),
						]
					);
				}
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}
