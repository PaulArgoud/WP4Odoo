<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin email notifications for persistent sync failures.
 *
 * Tracks consecutive batch failures via wp_options and sends
 * a notification email when the threshold is exceeded, with
 * a cooldown period between emails.
 *
 * Extracted from Sync_Engine for single responsibility.
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
class Failure_Notifier {

	/**
	 * Number of consecutive batch failures before sending a notification.
	 */
	private const THRESHOLD = 5;

	/**
	 * Minimum interval between notification emails (seconds).
	 */
	private const COOLDOWN = 3600;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Check batch results and send notification if needed.
	 *
	 * Call this after processing a batch. If there were any successes,
	 * the consecutive failure counter is reset. If only failures occurred,
	 * the counter is incremented and a notification may be sent.
	 *
	 * @param int $successes Number of successful jobs in the batch.
	 * @param int $failures  Number of failed jobs in the batch.
	 * @return void
	 */
	public function check( int $successes, int $failures ): void {
		$consecutive = (int) get_option( 'wp4odoo_consecutive_failures', 0 );

		if ( $successes > 0 ) {
			if ( $consecutive > 0 ) {
				update_option( 'wp4odoo_consecutive_failures', 0 );
			}
			return;
		}

		if ( 0 === $failures ) {
			return;
		}

		$consecutive += $failures;
		update_option( 'wp4odoo_consecutive_failures', $consecutive );

		if ( $consecutive < self::THRESHOLD ) {
			return;
		}

		$this->maybe_send( $consecutive );
	}

	/**
	 * Send a notification email if the cooldown has elapsed.
	 *
	 * @param int $consecutive Number of consecutive failures.
	 * @return void
	 */
	private function maybe_send( int $consecutive ): void {
		$last_email = (int) get_option( 'wp4odoo_last_failure_email', 0 );
		if ( ( time() - $last_email ) < self::COOLDOWN ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		if ( empty( $admin_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %d: number of consecutive failures */
			__( '[WP4Odoo] %d consecutive sync failures', 'wp4odoo' ),
			$consecutive
		);

		$message = sprintf(
			/* translators: 1: number of failures, 2: queue admin URL */
			__( "The WordPress For Odoo sync queue has encountered %1\$d consecutive failures.\n\nPlease check the sync queue at %2\$s", 'wp4odoo' ),
			$consecutive,
			admin_url( 'admin.php?page=wp4odoo-settings&tab=queue' )
		);

		wp_mail( $admin_email, $subject, $message );
		update_option( 'wp4odoo_last_failure_email', time() );

		$this->logger->warning(
			'Failure notification sent to admin.',
			[
				'consecutive_failures' => $consecutive,
				'email'                => $admin_email,
			]
		);
	}
}
