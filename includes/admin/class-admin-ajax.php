<?php
declare( strict_types=1 );

namespace WP4Odoo\Admin;

use WP4Odoo\API\Odoo_Auth;
use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Logger;
use WP4Odoo\Query_Service;
use WP4Odoo\Queue_Manager;
use WP4Odoo\Sync_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handlers for admin operations.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Admin_Ajax {

	/**
	 * Constructor — registers all AJAX hooks.
	 */
	public function __construct() {
		$actions = [
			'wp4odoo_test_connection',
			'wp4odoo_retry_failed',
			'wp4odoo_cleanup_queue',
			'wp4odoo_cancel_job',
			'wp4odoo_purge_logs',
			'wp4odoo_fetch_logs',
			'wp4odoo_queue_stats',
			'wp4odoo_toggle_module',
			'wp4odoo_save_module_settings',
			'wp4odoo_bulk_import_products',
			'wp4odoo_bulk_export_products',
		];

		foreach ( $actions as $action ) {
			$method = str_replace( 'wp4odoo_', '', $action );
			add_action( 'wp_ajax_' . $action, [ $this, $method ] );
		}
	}

	/**
	 * Verify nonce and capability. Dies on failure.
	 *
	 * @return void
	 */
	private function verify_request(): void {
		check_ajax_referer( 'wp4odoo_admin' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => __( 'Permission denied.', 'wp4odoo' ),
			], 403 );
		}
	}

	/**
	 * Sanitize and return a single POST field.
	 *
	 * @param string $key  The $_POST key.
	 * @param string $type Sanitization type: 'text', 'url', 'key', 'int', 'bool'.
	 * @return string|int|bool Sanitized value.
	 */
	private function get_post_field( string $key, string $type = 'text' ): string|int|bool {
		if ( ! isset( $_POST[ $key ] ) ) {
			return match ( $type ) {
				'int'  => 0,
				'bool' => false,
				default => '',
			};
		}

		$value = wp_unslash( $_POST[ $key ] );

		return match ( $type ) {
			'url'  => esc_url_raw( $value ),
			'key'  => sanitize_key( $value ),
			'int'  => absint( $value ),
			'bool' => ! empty( $value ),
			default => sanitize_text_field( $value ),
		};
	}

	// ─── Handlers ───────────────────────────────────────────

	/**
	 * Test Odoo connection with provided credentials.
	 *
	 * @return void
	 */
	public function test_connection(): void {
		$this->verify_request();

		$url      = $this->get_post_field( 'url', 'url' ) ?: null;
		$database = $this->get_post_field( 'database' ) ?: null;
		$username = $this->get_post_field( 'username' ) ?: null;
		$api_key  = $this->get_post_field( 'api_key' ) ?: null;
		$protocol = $this->get_post_field( 'protocol' ) ?: 'jsonrpc';

		// If api_key is empty, use the stored one.
		if ( empty( $api_key ) ) {
			$stored  = Odoo_Auth::get_credentials();
			$api_key = $stored['api_key'] ?: null;
		}

		$result = Odoo_Auth::test_connection( $url, $database, $username, $api_key, $protocol );

		wp_send_json_success( $result );
	}

	/**
	 * Retry all failed queue jobs.
	 *
	 * @return void
	 */
	public function retry_failed(): void {
		$this->verify_request();

		$count = Sync_Engine::retry_failed();

		wp_send_json_success( [
			'count'   => $count,
			'message' => sprintf(
				/* translators: %d: number of jobs */
				__( '%d job(s) retried.', 'wp4odoo' ),
				$count
			),
		] );
	}

	/**
	 * Clean up old completed/failed queue jobs.
	 *
	 * @return void
	 */
	public function cleanup_queue(): void {
		$this->verify_request();

		$days    = $this->get_post_field( 'days', 'int' ) ?: 7;
		$deleted = Sync_Engine::cleanup( $days );

		wp_send_json_success( [
			'deleted' => $deleted,
			'message' => sprintf(
				/* translators: %d: number of deleted jobs */
				__( '%d job(s) deleted.', 'wp4odoo' ),
				$deleted
			),
		] );
	}

	/**
	 * Cancel a single pending queue job.
	 *
	 * @return void
	 */
	public function cancel_job(): void {
		$this->verify_request();

		$job_id  = $this->get_post_field( 'job_id', 'int' );
		$success = Queue_Manager::cancel( $job_id );

		if ( $success ) {
			wp_send_json_success( [
				'message' => __( 'Job cancelled.', 'wp4odoo' ),
			] );
		} else {
			wp_send_json_error( [
				'message' => __( 'Unable to cancel this job.', 'wp4odoo' ),
			] );
		}
	}

	/**
	 * Purge old log entries.
	 *
	 * @return void
	 */
	public function purge_logs(): void {
		$this->verify_request();

		$logger  = new Logger();
		$deleted = $logger->cleanup();

		wp_send_json_success( [
			'deleted' => $deleted,
			'message' => sprintf(
				/* translators: %d: number of deleted log entries */
				__( '%d log entry(ies) deleted.', 'wp4odoo' ),
				$deleted
			),
		] );
	}

	/**
	 * Fetch log entries (for AJAX filtering/pagination).
	 *
	 * @return void
	 */
	public function fetch_logs(): void {
		$this->verify_request();

		$filters = [
			'level'     => $this->get_post_field( 'level' ),
			'module'    => $this->get_post_field( 'module' ),
			'date_from' => $this->get_post_field( 'date_from' ),
			'date_to'   => $this->get_post_field( 'date_to' ),
		];

		$page     = max( 1, $this->get_post_field( 'page', 'int' ) ) ?: 1;
		$per_page = $this->get_post_field( 'per_page', 'int' );
		$per_page = ( $per_page > 0 ) ? min( 100, $per_page ) : 50;

		$data = Query_Service::get_log_entries( $filters, $page, $per_page );

		// Serialize items for JSON transport.
		$items = [];
		foreach ( $data['items'] as $row ) {
			$items[] = [
				'id'         => (int) $row->id,
				'level'      => $row->level,
				'module'     => $row->module,
				'message'    => $row->message,
				'context'    => $row->context ?? '',
				'created_at' => $row->created_at,
			];
		}

		wp_send_json_success( [
			'items' => $items,
			'total' => $data['total'],
			'page'  => $data['page'],
			'pages' => $data['pages'],
		] );
	}

	/**
	 * Fetch queue statistics.
	 *
	 * @return void
	 */
	public function queue_stats(): void {
		$this->verify_request();

		wp_send_json_success( Sync_Engine::get_stats() );
	}

	/**
	 * Toggle a module's enabled state.
	 *
	 * @return void
	 */
	public function toggle_module(): void {
		$this->verify_request();

		$module_id = $this->get_post_field( 'module_id', 'key' );
		$enabled   = $this->get_post_field( 'enabled', 'bool' );

		if ( empty( $module_id ) ) {
			wp_send_json_error( [
				'message' => __( 'Missing module identifier.', 'wp4odoo' ),
			] );
		}

		update_option( 'wp4odoo_module_' . $module_id . '_enabled', $enabled );

		wp_send_json_success( [
			'module_id' => $module_id,
			'enabled'   => $enabled,
			'message'   => $enabled
				? sprintf(
					/* translators: %s: module identifier */
					__( 'Module "%s" enabled.', 'wp4odoo' ),
					$module_id
				)
				: sprintf(
					/* translators: %s: module identifier */
					__( 'Module "%s" disabled.', 'wp4odoo' ),
					$module_id
				),
		] );
	}

	/**
	 * Save a module's settings.
	 *
	 * @return void
	 */
	public function save_module_settings(): void {
		$this->verify_request();

		$module_id = $this->get_post_field( 'module_id', 'key' );
		$settings  = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : [];

		if ( empty( $module_id ) ) {
			wp_send_json_error( [
				'message' => __( 'Missing module identifier.', 'wp4odoo' ),
			] );
		}

		// Validate module exists.
		$modules = \WP4Odoo_Plugin::instance()->get_modules();
		if ( ! isset( $modules[ $module_id ] ) ) {
			wp_send_json_error( [
				'message' => __( 'Unknown module.', 'wp4odoo' ),
			] );
		}

		$module   = $modules[ $module_id ];
		$fields   = $module->get_settings_fields();
		$defaults = $module->get_default_settings();
		$clean    = [];

		foreach ( $fields as $key => $field ) {
			if ( isset( $settings[ $key ] ) ) {
				switch ( $field['type'] ) {
					case 'checkbox':
						$clean[ $key ] = ! empty( $settings[ $key ] );
						break;
					case 'number':
						$clean[ $key ] = absint( $settings[ $key ] );
						break;
					case 'select':
						$allowed = array_keys( $field['options'] ?? [] );
						$val     = sanitize_text_field( $settings[ $key ] );
						$clean[ $key ] = in_array( $val, $allowed, true ) ? $val : ( $defaults[ $key ] ?? '' );
						break;
					default:
						$clean[ $key ] = sanitize_text_field( $settings[ $key ] );
						break;
				}
			} else {
				// Checkbox not sent = unchecked.
				if ( 'checkbox' === $field['type'] ) {
					$clean[ $key ] = false;
				}
			}
		}

		update_option( 'wp4odoo_module_' . $module_id . '_settings', $clean );

		wp_send_json_success( [
			'message' => __( 'Settings saved.', 'wp4odoo' ),
		] );
	}

	// ─── Bulk Operations ────────────────────────────────────

	/**
	 * Bulk import all products from Odoo into WooCommerce.
	 *
	 * Searches all Odoo product.template IDs and enqueues pull jobs
	 * through the queue system (no synchronous API calls).
	 *
	 * @return void
	 */
	public function bulk_import_products(): void {
		$this->verify_request();

		$plugin = \WP4Odoo_Plugin::instance();
		$module = $plugin->get_module( 'woocommerce' );

		if ( null === $module ) {
			wp_send_json_error( [
				'message' => __( 'WooCommerce module is not registered.', 'wp4odoo' ),
			] );
		}

		$client = $plugin->client();
		$odoo_ids = $client->search( 'product.template', [] );

		if ( empty( $odoo_ids ) ) {
			wp_send_json_success( [
				'enqueued' => 0,
				'message'  => __( 'No products found in Odoo.', 'wp4odoo' ),
			] );
		}

		$enqueued = 0;
		foreach ( $odoo_ids as $odoo_id ) {
			$odoo_id = (int) $odoo_id;
			$wp_id   = Entity_Map_Repository::get_wp_id( 'woocommerce', 'product', $odoo_id );
			$action  = $wp_id ? 'update' : 'create';

			Queue_Manager::pull( 'woocommerce', 'product', $action, $odoo_id, $wp_id, [], 5 );
			++$enqueued;
		}

		wp_send_json_success( [
			'enqueued' => $enqueued,
			'message'  => sprintf(
				/* translators: %d: number of products enqueued */
				__( '%d product(s) enqueued for import.', 'wp4odoo' ),
				$enqueued
			),
		] );
	}

	/**
	 * Bulk export all WooCommerce products to Odoo.
	 *
	 * Iterates all WC product IDs and enqueues push jobs
	 * through the queue system.
	 *
	 * @return void
	 */
	public function bulk_export_products(): void {
		$this->verify_request();

		$plugin = \WP4Odoo_Plugin::instance();
		$module = $plugin->get_module( 'woocommerce' );

		if ( null === $module ) {
			wp_send_json_error( [
				'message' => __( 'WooCommerce module is not registered.', 'wp4odoo' ),
			] );
		}

		$product_ids = wc_get_products( [ 'limit' => -1, 'return' => 'ids' ] );

		if ( empty( $product_ids ) ) {
			wp_send_json_success( [
				'enqueued' => 0,
				'message'  => __( 'No WooCommerce products found.', 'wp4odoo' ),
			] );
		}

		$enqueued = 0;
		foreach ( $product_ids as $wp_id ) {
			$wp_id   = (int) $wp_id;
			$odoo_id = Entity_Map_Repository::get_odoo_id( 'woocommerce', 'product', $wp_id );
			$action  = $odoo_id ? 'update' : 'create';

			Queue_Manager::push( 'woocommerce', 'product', $action, $wp_id, $odoo_id ?? 0 );
			++$enqueued;
		}

		wp_send_json_success( [
			'enqueued' => $enqueued,
			'message'  => sprintf(
				/* translators: %d: number of products enqueued */
				__( '%d product(s) enqueued for export.', 'wp4odoo' ),
				$enqueued
			),
		] );
	}
}
