<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-based rate limiter.
 *
 * Uses WordPress transients for persistent rate limiting across PHP processes.
 * On sites with a persistent object cache (Redis/Memcached), transients are
 * backed by the cache. On sites without one, they fall back to the database.
 *
 * @package WP4Odoo
 * @since   3.3.0
 */
class Rate_Limiter {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Maximum allowed requests within the window.
	 *
	 * @var int
	 */
	private int $max_requests;

	/**
	 * Rate limit window in seconds.
	 *
	 * @var int
	 */
	private int $window;

	/**
	 * Logger instance.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param string      $prefix       Transient key prefix (e.g. 'wp4odoo_rl_').
	 * @param int         $max_requests Maximum requests per window.
	 * @param int         $window       Window duration in seconds.
	 * @param Logger|null $logger       Optional logger for rate limit events.
	 */
	public function __construct( string $prefix, int $max_requests, int $window, ?Logger $logger = null ) {
		$this->prefix       = $prefix;
		$this->max_requests = $max_requests;
		$this->window       = $window;
		$this->logger       = $logger;
	}

	/**
	 * Check whether an identifier is within the rate limit.
	 *
	 * Increments the counter on each call. Returns true if under the limit,
	 * or a WP_Error with status 429 if the limit is exceeded.
	 *
	 * @param string $identifier Unique identifier to rate-limit (e.g. IP address).
	 * @return true|\WP_Error True if under limit, WP_Error if exceeded.
	 */
	public function check( string $identifier ): true|\WP_Error {
		$key   = $this->prefix . md5( $identifier );
		$count = (int) get_transient( $key );

		if ( $count >= $this->max_requests ) {
			if ( null !== $this->logger ) {
				$this->logger->warning(
					'Rate limit exceeded.',
					[
						'identifier' => $identifier,
						'count'      => $count,
					]
				);
			}

			return new \WP_Error(
				'wp4odoo_rate_limited',
				__( 'Too many requests. Please try again later.', 'wp4odoo' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, $count + 1, $this->window );

		return true;
	}
}
