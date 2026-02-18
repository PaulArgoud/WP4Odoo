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
	 * Deduplication window in seconds (30 minutes).
	 *
	 * Identical webhook payloads received within this window
	 * are treated as duplicates and not re-enqueued.
	 *
	 * 30 minutes is intentionally generous: Odoo automated actions
	 * and workflow triggers can fire multiple webhooks for the same
	 * record change within seconds/minutes. The window must be long
	 * enough to catch delayed retries but short enough that genuine
	 * rapid updates (e.g. operator editing a record twice) are not
	 * silently dropped.
	 */
	private const DEDUP_WINDOW = 1800;

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
	 * Rate limiter instance.
	 *
	 * @var Rate_Limiter
	 */
	private Rate_Limiter $rate_limiter;

	/**
	 * Module registry for module lookups.
	 *
	 * @var Module_Registry
	 */
	private Module_Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $settings Settings repository.
	 * @param Module_Registry     $registry Module registry for module lookups.
	 */
	public function __construct( Settings_Repository $settings, Module_Registry $registry ) {
		$this->settings     = $settings;
		$this->registry     = $registry;
		$this->logger       = Logger::for_channel( 'webhook', $settings );
		$this->rate_limiter = new Rate_Limiter( 'wp4odoo_rl_', 20, 60, $this->logger );
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
			'/health',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_health' ],
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

		try {
			$job_id = Queue_Manager::pull( $module, $entity_type, $action, $odoo_id, null, $body );
		} catch ( \Throwable $e ) {
			$this->logger->critical(
				'Webhook enqueue failed.',
				[
					'module'      => $module,
					'entity_type' => $entity_type,
					'odoo_id'     => $odoo_id,
					'error'       => $e->getMessage(),
				]
			);

			/**
			 * Fires when a webhook payload could not be enqueued.
			 *
			 * Allows external monitoring or retry mechanisms to react
			 * to enqueue failures (e.g. database unavailable).
			 *
			 * @since 3.1.0
			 *
			 * @param string     $module      Module identifier.
			 * @param string     $entity_type Entity type.
			 * @param int        $odoo_id     Odoo record ID.
			 * @param \Throwable $e           The exception.
			 */
			do_action( 'wp4odoo_webhook_enqueue_failed', $module, $entity_type, $odoo_id, $e );

			$response = new \WP_REST_Response(
				[
					'success' => false,
					'error'   => __( 'Internal enqueue error.', 'wp4odoo' ),
				],
				503
			);
			$response->header( 'Retry-After', '60' );
			return $response;
		}

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
	 * System health endpoint for monitoring.
	 *
	 * Returns queue depth, circuit breaker state, and module counts.
	 * Useful for external monitoring (Nagios, UptimeRobot, etc.).
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_health( \WP_REST_Request $request ): \WP_REST_Response {
		$queue_repo = new Sync_Queue_Repository();
		$stats      = $queue_repo->get_stats();

		$cb_state = get_option( 'wp4odoo_cb_state', [] );
		$registry = $this->registry;

		$cb_open = is_array( $cb_state ) && ! empty( $cb_state['opened_at'] );

		$status = 'healthy';
		if ( $cb_open ) {
			$status = 'degraded';
		}
		if ( $stats['failed'] > 100 ) {
			$status = 'degraded';
		}

		return new \WP_REST_Response(
			[
				'status'          => $status,
				'version'         => WP4ODOO_VERSION,
				'queue_pending'   => $stats['pending'],
				'queue_failed'    => $stats['failed'],
				'circuit_breaker' => $cb_open ? 'open' : 'closed',
				'modules_booted'  => $registry->get_booted_count(),
				'modules_total'   => count( $registry->all() ),
				'timestamp'       => gmdate( 'c' ),
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

		$module = $this->registry->get( $module_id );

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
		$rate_check = $this->rate_limiter->check( $ip );
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

		// Optional HMAC signature verification (backward-compatible).
		// If the X-Odoo-Signature header is present, verify it against
		// HMAC-SHA256(body, token). If absent, token-only auth is used.
		$signature = $request->get_header( 'X-Odoo-Signature' );
		if ( null !== $signature ) {
			$body     = $request->get_body();
			$expected = hash_hmac( 'sha256', $body, $stored );

			if ( ! hash_equals( $expected, (string) $signature ) ) {
				$this->logger->warning(
					'Invalid HMAC signature.',
					[
						'ip' => $ip,
					]
				);

				return new \WP_Error(
					'wp4odoo_invalid_signature',
					__( 'Invalid webhook signature.', 'wp4odoo' ),
					[ 'status' => 403 ]
				);
			}
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
}
