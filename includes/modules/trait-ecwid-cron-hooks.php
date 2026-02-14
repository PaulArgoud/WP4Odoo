<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ecwid polling via WP-Cron.
 *
 * Ecwid is a cloud-hosted e-commerce platform. Product and order data
 * lives on Ecwid servers, not in WordPress. This trait implements
 * WP-Cron-based polling using the Ecwid REST API to detect changes
 * via SHA-256 hash comparison (same pattern as Bookly).
 *
 * Expects the using class to provide:
 * - is_importing(): bool              (from Module_Base)
 * - get_settings(): array             (from Module_Base)
 * - poll_entity_changes(): void       (from Module_Base)
 * - logger: Logger                    (from Module_Base)
 * - get_handler(): Ecwid_Handler      (from Ecwid_Module)
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
trait Ecwid_Cron_Hooks {

	/**
	 * Register the WP-Cron polling event.
	 *
	 * @return void
	 */
	protected function register_cron(): void {
		if ( ! defined( 'ECWID_PLUGIN_DIR' ) ) {
			$this->logger->warning( __( 'Ecwid module enabled but Ecwid is not active.', 'wp4odoo' ) );
			return;
		}

		if ( ! wp_next_scheduled( 'wp4odoo_ecwid_poll' ) ) {
			wp_schedule_event( time(), 'wp4odoo_five_minutes', 'wp4odoo_ecwid_poll' );
		}

		add_action( 'wp4odoo_ecwid_poll', [ $this, 'poll' ] );
	}

	/**
	 * Poll Ecwid API for changes.
	 *
	 * @return void
	 */
	public function poll(): void {
		if ( $this->is_importing() ) {
			return;
		}

		global $wpdb;
		$lock_name = 'wp4odoo_ecwid_poll';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		if ( '1' !== $acquired ) {
			return;
		}

		try {
			$settings = $this->get_settings();
			$store_id = (string) ( $settings['ecwid_store_id'] ?? '' );
			$token    = (string) ( $settings['ecwid_api_token'] ?? '' );

			if ( '' === $store_id || '' === $token ) {
				return;
			}

			if ( ! empty( $settings['sync_products'] ) ) {
				try {
					$this->poll_entity_changes( 'product', $this->get_handler()->fetch_products( $store_id, $token ) );
				} catch ( \Throwable $e ) {
					$this->logger->critical(
						'Ecwid product polling crashed (graceful degradation).',
						[
							'exception' => get_class( $e ),
							'message'   => $e->getMessage(),
						]
					);
				}
			}

			if ( ! empty( $settings['sync_orders'] ) ) {
				try {
					$this->poll_entity_changes( 'order', $this->get_handler()->fetch_orders( $store_id, $token ), 'orderNumber' );
				} catch ( \Throwable $e ) {
					$this->logger->critical(
						'Ecwid order polling crashed (graceful degradation).',
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
