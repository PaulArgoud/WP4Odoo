<?php
declare( strict_types=1 );

namespace WP4Odoo;

use WP4Odoo\Admin\Bulk_Handler;
use WP4Odoo\API\Odoo_Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI configuration commands: Odoo connection, webhook token, bulk product sync.
 *
 * Lets a site be provisioned and operated without the admin UI.
 *
 * @package WP4Odoo
 * @since   3.10.0
 */
trait CLI_Config_Commands {

	// ─── connection ───────────────────────────────────────

	/**
	 * Show or change the Odoo connection settings.
	 *
	 * @param array<int, string>   $args       Positional args: show|set.
	 * @param array<string, mixed> $assoc_args Associative args (odoo-url, database, username, api-key, protocol, timeout, company-id).
	 * @return void
	 */
	private function connection_run( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'show';

		if ( 'show' === $sub ) {
			$credentials = Odoo_Auth::get_credentials();
			$rows        = [
				[
					'setting' => 'url',
					'value'   => (string) $credentials['url'],
				],
				[
					'setting' => 'database',
					'value'   => (string) $credentials['database'],
				],
				[
					'setting' => 'username',
					'value'   => (string) $credentials['username'],
				],
				[
					'setting' => 'api_key',
					'value'   => '' !== (string) $credentials['api_key'] ? __( '(set)', 'wp4odoo' ) : __( '(not set)', 'wp4odoo' ),
				],
				[
					'setting' => 'protocol',
					'value'   => (string) $credentials['protocol'],
				],
				[
					'setting' => 'timeout',
					'value'   => (string) $credentials['timeout'],
				],
				[
					'setting' => 'company_id',
					'value'   => (string) $credentials['company_id'],
				],
			];
			\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, [ 'setting', 'value' ] );
			return;
		}

		if ( 'set' !== $sub ) {
			/* translators: %s: subcommand name */
			\WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Available: show, set', 'wp4odoo' ), $sub ) );
			return;
		}

		$options = [ 'odoo-url', 'database', 'username', 'api-key', 'protocol', 'timeout', 'company-id' ];
		if ( ! array_intersect( $options, array_keys( $assoc_args ) ) ) {
			\WP_CLI::error( __( 'Nothing to change. Pass at least one of: --odoo-url, --database, --username, --api-key, --protocol, --timeout, --company-id.', 'wp4odoo' ) );
			return;
		}

		$current = Odoo_Auth::get_credentials();

		if ( isset( $assoc_args['odoo-url'] ) ) {
			$url = esc_url_raw( (string) $assoc_args['odoo-url'] );
			if ( '' === $url ) {
				\WP_CLI::error( __( 'Invalid URL.', 'wp4odoo' ) );
				return;
			}
			if ( ! isset( $assoc_args['allow-private-url'] ) && ! Settings_Validator::is_safe_url( $url ) ) {
				\WP_CLI::error( __( 'The Odoo URL must point to a public address (private IPs and localhost are refused). Pass --allow-private-url to override, e.g. for a local development server.', 'wp4odoo' ) );
				return;
			}
			$current['url'] = $url;
		}

		if ( isset( $assoc_args['protocol'] ) ) {
			if ( ! in_array( $assoc_args['protocol'], [ 'jsonrpc', 'xmlrpc', 'json2' ], true ) ) {
				\WP_CLI::error( __( 'Invalid protocol. Use jsonrpc, xmlrpc or json2.', 'wp4odoo' ) );
				return;
			}
			$current['protocol'] = $assoc_args['protocol'];
		}

		if ( isset( $assoc_args['database'] ) ) {
			$current['database'] = sanitize_text_field( (string) $assoc_args['database'] );
		}
		if ( isset( $assoc_args['username'] ) ) {
			$current['username'] = sanitize_text_field( (string) $assoc_args['username'] );
		}
		if ( isset( $assoc_args['api-key'] ) ) {
			$current['api_key'] = (string) $assoc_args['api-key'];
		}
		if ( isset( $assoc_args['timeout'] ) ) {
			$current['timeout'] = max( 5, min( 120, absint( $assoc_args['timeout'] ) ) );
		}
		if ( isset( $assoc_args['company-id'] ) ) {
			$current['company_id'] = absint( $assoc_args['company-id'] );
		}

		// get_credentials() returns the decrypted key; save_credentials() encrypts it again.
		Odoo_Auth::save_credentials( $current );
		\WP_CLI::success( __( 'Connection settings saved.', 'wp4odoo' ) );

		if ( isset( $assoc_args['test'] ) ) {
			$this->test();
		} else {
			\WP_CLI::line( __( 'Run "wp wp4odoo test" to check the connection.', 'wp4odoo' ) );
		}
	}

	// ─── webhook ──────────────────────────────────────────

	/**
	 * Show or rotate the webhook token.
	 *
	 * @param array<int, string>   $args       Positional args: show|rotate.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	private function webhook_run( array $args, array $assoc_args ): void {
		$sub      = $args[0] ?? 'show';
		$settings = \WP4Odoo_Plugin::instance()->settings();

		if ( 'show' === $sub ) {
			$token = $settings->get_webhook_token();
			\WP_CLI::line( sprintf( /* translators: %s: webhook URL */ __( 'Webhook URL: %s', 'wp4odoo' ), rest_url( 'wp4odoo/v1/webhook' ) ) );
			\WP_CLI::line( '' === $token ? __( 'Token: (none yet — it is generated when the REST routes are first registered, or run "wp wp4odoo webhook rotate")', 'wp4odoo' ) : sprintf( /* translators: %s: webhook token */ __( 'Token: %s', 'wp4odoo' ), $token ) );
			return;
		}

		if ( 'rotate' === $sub ) {
			\WP_CLI::confirm( __( 'Generate a new webhook token? Odoo automated actions must be updated with it.', 'wp4odoo' ), $assoc_args );
			$token = wp_generate_password( 48, false );
			$settings->save_webhook_token( $token );
			/* translators: %s: new webhook token */
			\WP_CLI::success( sprintf( __( 'New webhook token: %s', 'wp4odoo' ), $token ) );
			return;
		}

		/* translators: %s: subcommand name */
		\WP_CLI::error( sprintf( __( 'Unknown subcommand: %s. Available: show, rotate', 'wp4odoo' ), $sub ) );
	}

	// ─── bulk product sync ────────────────────────────────

	/**
	 * Queue all WooCommerce products for export to Odoo, or all Odoo products for import.
	 *
	 * @param string               $direction  'export' or 'import'.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	private function bulk_products( string $direction, array $assoc_args ): void {
		$plugin = \WP4Odoo_Plugin::instance();
		if ( null === $plugin->get_module( 'woocommerce' ) ) {
			\WP_CLI::error( __( 'The WooCommerce module is not registered (is WooCommerce active and the module enabled?).', 'wp4odoo' ) );
			return;
		}

		if ( 'export' === $direction ) {
			\WP_CLI::confirm( __( 'Queue every WooCommerce product for export to Odoo?', 'wp4odoo' ), $assoc_args );
		} else {
			\WP_CLI::confirm( __( 'Queue every Odoo product for import into WooCommerce?', 'wp4odoo' ), $assoc_args );
		}

		$handler = new Bulk_Handler( $plugin->client(), new Entity_Map_Repository() );
		$result  = 'export' === $direction ? $handler->export_products() : $handler->import_products();

		if ( 0 === (int) $result['enqueued'] ) {
			\WP_CLI::warning( $result['message'] );
			return;
		}

		\WP_CLI::success( $result['message'] );
		\WP_CLI::line( __( 'Process the queue with "wp wp4odoo sync run" (or wait for the next cron run).', 'wp4odoo' ) );
	}
}
