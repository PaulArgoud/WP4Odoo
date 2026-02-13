<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for Odoo webhook endpoints.
 *
 * Registers routes under wp-json/wp4odoo/v1/ for receiving
 * webhook notifications from Odoo and triggering manual syncs.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Webhook_Handler {

	/**
	 * REST API namespace.
	 */
	private const API_NAMESPACE = 'wp4odoo/v1';

	/**
	 * Maximum webhook requests per IP within the rate limit window.
	 */
	private const RATE_LIMIT_MAX = 20;

	/**
	 * Rate limit window in seconds.
	 */
	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Deduplication window in seconds.
	 *
	 * Identical webhook payloads received within this window
	 * are treated as duplicates and not re-enqueued.
	 */
	private const DEDUP_WINDOW = 300;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

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
		$this->logger   = new Logger( 'webhook', $settings );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$this->ensure_webhook_token();

		register_rest_route(
			self::API_NAMESPACE,
			'/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => [ $this, 'validate_webhook_token' ],
				'args'                => [
					'module'      => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'entity_type' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'odoo_id'     => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'action'      => [
						'type'              => 'string',
						'default'           => 'update',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/webhook/test',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_test' ],
				'permission_callback' => [ $this, 'validate_webhook_token' ],
			]
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/(?P<module>[a-zA-Z0-9_-]+)/(?P<entity>[a-zA-Z0-9_-]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_sync_trigger' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'module' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'entity' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Handle incoming webhook from Odoo.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();

		$module      = sanitize_text_field( $body['module'] ?? '' );
		$entity_type = sanitize_text_field( $body['entity_type'] ?? '' );
		$odoo_id     = isset( $body['odoo_id'] ) ? absint( $body['odoo_id'] ) : 0;
		$action      = sanitize_text_field( $body['action'] ?? 'update' );

		if ( empty( $module ) || empty( $entity_type ) || 0 === $odoo_id ) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Missing required fields: module, entity_type, odoo_id.', 'wp4odoo' ),
				],
				400
			);
		}

		// Idempotency: prefer explicit X-Odoo-Idempotency-Key header,
		// fall back to content-based SHA-256 hash deduplication.
		$idempotency_key = $request->get_header( 'X-Odoo-Idempotency-Key' );

		if ( ! empty( $idempotency_key ) ) {
			$dedup_key = 'wp4odoo_wh_' . substr( sanitize_key( $idempotency_key ), 0, 32 );
		} else {
			$hash      = hash( 'sha256', wp_json_encode( $body ) );
			$dedup_key = 'wp4odoo_wh_' . substr( $hash, 0, 32 );
		}

		if ( false !== get_transient( $dedup_key ) ) {
			$this->logger->debug(
				'Webhook deduplicated.',
				[
					'module'      => $module,
					'entity_type' => $entity_type,
					'odoo_id'     => $odoo_id,
				]
			);

			return new \WP_REST_Response(
				[
					'success'      => true,
					'deduplicated' => true,
				],
				200
			);
		}

		set_transient( $dedup_key, 1, self::DEDUP_WINDOW );

		$job_id = Queue_Manager::pull( $module, $entity_type, $action, $odoo_id, null, $body );

		$this->logger->info(
			'Webhook received, job enqueued.',
			[
				'module'      => $module,
				'entity_type' => $entity_type,
				'odoo_id'     => $odoo_id,
				'action'      => $action,
				'job_id'      => $job_id,
			]
		);

		return new \WP_REST_Response(
			[
				'success' => (bool) $job_id,
				'job_id'  => $job_id,
			],
			$job_id ? 202 : 500
		);
	}

	/**
	 * Health check / connectivity test.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_test( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'status'  => 'ok',
				'version' => WP4ODOO_VERSION,
				'time'    => current_time( 'mysql', true ),
			],
			200
		);
	}

	/**
	 * Trigger a manual sync for a module/entity.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_sync_trigger( \WP_REST_Request $request ): \WP_REST_Response {
		$module_id   = $request->get_param( 'module' );
		$entity_type = $request->get_param( 'entity' );
		$body        = $request->get_json_params();
		$direction   = sanitize_text_field( $body['direction'] ?? 'odoo_to_wp' );

		$plugin = \WP4Odoo_Plugin::instance();
		$module = $plugin->get_module( $module_id );

		if ( null === $module ) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'message' => sprintf(
						/* translators: %s: module identifier */
						__( 'Module "%s" not found.', 'wp4odoo' ),
						$module_id
					),
				],
				404
			);
		}

		$odoo_id = isset( $body['odoo_id'] ) ? absint( $body['odoo_id'] ) : 0;
		$wp_id   = isset( $body['wp_id'] ) ? absint( $body['wp_id'] ) : 0;
		$action  = sanitize_text_field( $body['action'] ?? 'update' );

		if ( 'wp_to_odoo' === $direction && $wp_id > 0 ) {
			$job_id = Queue_Manager::push( $module_id, $entity_type, $action, $wp_id, $odoo_id ?: null );
		} elseif ( $odoo_id > 0 ) {
			$job_id = Queue_Manager::pull( $module_id, $entity_type, $action, $odoo_id, $wp_id ?: null );
		} else {
			return new \WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Provide either wp_id (for push) or odoo_id (for pull).', 'wp4odoo' ),
				],
				400
			);
		}

		$this->logger->info(
			'Manual sync triggered.',
			[
				'module'      => $module_id,
				'entity_type' => $entity_type,
				'direction'   => $direction,
				'job_id'      => $job_id,
			]
		);

		return new \WP_REST_Response(
			[
				'success' => (bool) $job_id,
				'job_id'  => $job_id,
			],
			$job_id ? 202 : 500
		);
	}

	/**
	 * Validate the X-Odoo-Token header against stored token.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_webhook_token( \WP_REST_Request $request ): bool|\WP_Error {
		$ip = $this->get_client_ip( $request );

		// Rate limiting (before token check to protect against brute-force).
		$rate_check = $this->check_rate_limit( $ip );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$token  = $request->get_header( 'X-Odoo-Token' );
		$stored = $this->settings->get_webhook_token();

		if ( empty( $stored ) ) {
			return new \WP_Error(
				'wp4odoo_no_token',
				__( 'Webhook token not configured.', 'wp4odoo' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! hash_equals( $stored, (string) $token ) ) {
			$this->logger->warning(
				'Invalid webhook token received.',
				[
					'ip' => $ip,
				]
			);

			return new \WP_Error(
				'wp4odoo_invalid_token',
				__( 'Invalid webhook token.', 'wp4odoo' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Permission check: current user can manage_options.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return bool|\WP_Error
	 */
	public function check_admin_permission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'wp4odoo_forbidden',
			__( 'You do not have permission to perform this action.', 'wp4odoo' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Ensure a webhook token exists, generate one if not.
	 *
	 * @return void
	 */
	private function ensure_webhook_token(): void {
		$token = $this->settings->get_webhook_token();

		if ( empty( $token ) ) {
			$token = wp_generate_password( 48, false );
			$this->settings->save_webhook_token( $token );
		}
	}

	/**
	 * Extract the client IP address from a REST request.
	 *
	 * Parses X-Forwarded-For (takes the first/leftmost IP only)
	 * and validates it before falling back to REMOTE_ADDR.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return string Sanitized client IP address.
	 */
	private function get_client_ip( \WP_REST_Request $request ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$remote_addr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );

		// Only trust X-Forwarded-For when REMOTE_ADDR is a private/reserved IP
		// (i.e. behind a known reverse proxy). Prevents rate-limit bypass via
		// spoofed headers on direct connections.
		$forwarded = $request->get_header( 'X-Forwarded-For' );

		if ( ! empty( $forwarded ) && filter_var( $remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE ) === false ) {
			// X-Forwarded-For may contain: client, proxy1, proxy2. Take the first.
			$parts = explode( ',', $forwarded );
			$ip    = trim( $parts[0] );

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return sanitize_text_field( $ip );
			}
		}

		return $remote_addr;
	}

	/**
	 * Non-persistent cache group for rate limiting.
	 *
	 * Using the object cache instead of transients avoids a DB write
	 * on every webhook request when no persistent object cache is
	 * configured. The counter resets naturally on each PHP process
	 * or when the object cache TTL expires.
	 */
	private const RATE_LIMIT_CACHE_GROUP = 'wp4odoo_rate_limit';

	/**
	 * Check the per-IP rate limit for webhook requests.
	 *
	 * Uses the WP object cache with a non-persistent group and a
	 * 60-second window. Returns WP_Error with 429 status if the
	 * limit is exceeded.
	 *
	 * @param string $ip Client IP address.
	 * @return true|\WP_Error True if under limit, WP_Error if exceeded.
	 */
	private function check_rate_limit( string $ip ): true|\WP_Error {
		$key   = md5( $ip );
		$count = (int) wp_cache_get( $key, self::RATE_LIMIT_CACHE_GROUP );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			$this->logger->warning(
				'Rate limit exceeded for webhook endpoint.',
				[
					'ip'    => $ip,
					'count' => $count,
				]
			);

			return new \WP_Error(
				'wp4odoo_rate_limited',
				__( 'Too many requests. Please try again later.', 'wp4odoo' ),
				[ 'status' => 429 ]
			);
		}

		wp_cache_set( $key, $count + 1, self::RATE_LIMIT_CACHE_GROUP, self::RATE_LIMIT_WINDOW );

		return true;
	}
}
