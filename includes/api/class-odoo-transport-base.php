<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared base class for Odoo RPC transports.
 *
 * Holds common properties (URL, database, credentials, logger) and
 * provides the authentication guard and retry-capable HTTP layer.
 *
 * @package WP4Odoo
 * @since   2.4.0
 */
abstract class Odoo_Transport_Base implements Transport {

	use Retryable_Http;

	/**
	 * Odoo server URL (no trailing slash).
	 *
	 * @var string
	 */
	protected string $url;

	/**
	 * Odoo database name.
	 *
	 * @var string
	 */
	protected string $database;

	/**
	 * Authenticated user ID.
	 *
	 * @var int|null
	 */
	protected ?int $uid = null;

	/**
	 * Odoo server version string (e.g. '17.0').
	 *
	 * @var string|null
	 */
	protected ?string $server_version = null;

	/**
	 * API key or password.
	 *
	 * @var string
	 */
	protected string $api_key;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	protected int $timeout;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	protected Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param string $url      Odoo server URL.
	 * @param string $database Database name.
	 * @param string $api_key  API key or password.
	 * @param int    $timeout  Request timeout in seconds.
	 */
	public function __construct( string $url, string $database, string $api_key, int $timeout = 30 ) {
		$this->url      = rtrim( $url, '/' );
		$this->database = $database;
		$this->api_key  = $api_key;
		$this->timeout  = $timeout;
		$this->logger   = new Logger( $this->get_protocol_name() );
	}

	/**
	 * Get the authenticated user ID.
	 *
	 * @return int|null
	 */
	public function get_uid(): ?int {
		return $this->uid;
	}

	/**
	 * Get the Odoo server version string.
	 *
	 * @return string|null e.g. '17.0' or null if not yet known.
	 */
	public function get_server_version(): ?string {
		return $this->server_version;
	}

	/**
	 * Guard: throw if not yet authenticated.
	 *
	 * @return void
	 * @throws \RuntimeException If authenticate() has not been called.
	 */
	protected function ensure_authenticated(): void {
		if ( null === $this->uid ) {
			throw new \RuntimeException(
				__( 'Not authenticated. Call authenticate() first.', 'wp4odoo' )
			);
		}
	}

	/**
	 * Return the protocol name used for logging context.
	 *
	 * @return string
	 */
	abstract protected function get_protocol_name(): string;
}
