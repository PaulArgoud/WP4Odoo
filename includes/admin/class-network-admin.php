<?php
declare( strict_types=1 );

namespace WP4Odoo\Admin;

use WP4Odoo\API\Odoo_Auth;
use WP4Odoo\Settings_Repository;
use WP4Odoo\Settings_Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network admin page for WordPress multisite.
 *
 * Provides a centralized settings page in the network admin for:
 * - Shared Odoo connection (URL, DB, user, API key)
 * - Site → Company ID mapping
 *
 * Individual sites inherit the network connection by default but can
 * override with their own connection in Settings > Odoo Connector.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class Network_Admin {

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $settings Settings repository.
	 */
	public function __construct( Settings_Repository $settings ) {
		$this->settings = $settings;

		add_action( 'network_admin_menu', [ $this, 'register_menu' ] );
		add_action( 'network_admin_edit_wp4odoo_network', [ $this, 'handle_save' ] );
	}

	/**
	 * Register the network admin menu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Odoo Connector', 'wp4odoo' ),
			__( 'Odoo Connector', 'wp4odoo' ),
			'manage_network_options',
			'wp4odoo-network',
			[ $this, 'render_page' ],
			'dashicons-update',
			80
		);
	}

	/**
	 * Render the network settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp4odoo' ) );
		}

		$connection     = $this->settings->get_network_connection();
		$site_companies = $this->settings->get_network_site_companies();
		$sites          = get_sites( [ 'number' => 0 ] );

		require WP4ODOO_PLUGIN_DIR . 'admin/views/network-settings.php';
	}

	/**
	 * Handle the form submission.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		check_admin_referer( 'wp4odoo_network_settings' );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp4odoo' ) );
		}

		// Save shared connection.
		$url = esc_url_raw( wp_unslash( $_POST['wp4odoo_network_url'] ?? '' ) );

		// SSRF protection: reject private/internal IP addresses.
		if ( '' !== $url && ! Settings_Validator::is_safe_url( $url ) ) {
			$url = '';
		}

		$connection = [
			'url'      => $url,
			'database' => sanitize_text_field( wp_unslash( $_POST['wp4odoo_network_database'] ?? '' ) ),
			'username' => sanitize_text_field( wp_unslash( $_POST['wp4odoo_network_username'] ?? '' ) ),
			'protocol' => in_array( $_POST['wp4odoo_network_protocol'] ?? '', [ 'jsonrpc', 'xmlrpc', 'json2' ], true )
				? sanitize_key( $_POST['wp4odoo_network_protocol'] )
				: 'jsonrpc',
			'timeout'  => max( 5, min( 120, absint( $_POST['wp4odoo_network_timeout'] ?? 30 ) ) ),
		];

		// Only update API key if a new one was provided.
		$api_key = sanitize_text_field( wp_unslash( $_POST['wp4odoo_network_api_key'] ?? '' ) );
		if ( '' !== $api_key ) {
			$connection['api_key'] = Odoo_Auth::encrypt( $api_key );
		} else {
			// Preserve existing encrypted key.
			$existing              = $this->settings->get_network_connection();
			$connection['api_key'] = $existing['api_key'] ?? '';
		}

		$this->settings->save_network_connection( $connection );

		// Flush credential caches for all sites.
		Odoo_Auth::flush_credentials_cache();

		// Save site → company_id mapping.
		$raw_companies  = $_POST['wp4odoo_site_company'] ?? []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$site_companies = [];
		if ( is_array( $raw_companies ) ) {
			foreach ( $raw_companies as $blog_id => $company_id ) {
				$blog_id    = absint( $blog_id );
				$company_id = absint( $company_id );
				if ( $blog_id > 0 ) {
					$site_companies[ $blog_id ] = $company_id;
				}
			}
		}
		$this->settings->save_network_site_companies( $site_companies );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => 'wp4odoo-network',
					'updated' => 'true',
				],
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
