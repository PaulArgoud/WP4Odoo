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
 * @since   2.0.0
 */
class Failure_Notifier {

	/**
	 * Failure ratio threshold for considering a batch as "failed".
	 *
	 * Aligned with Circuit_Breaker::FAILURE_RATIO (80%): a batch
	 * with fewer than 80% failures is considered healthy.
	 */
	private const FAILURE_RATIO = 0.8;

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
	 * @param Logger              $logger   Logger instance.
	 * @param Settings_Repository $settings Settings repository.
	 */
	public function __construct( Logger $logger, Settings_Repository $settings ) {
		$this->logger   = $logger;
		$this->settings = $settings;
	}

	/**
	 * Check batch results and send notification if needed.
	 *
	 * Uses the same ratio-based logic as Circuit_Breaker: a batch
	 * counts as a failure only when 80%+ of jobs failed. This prevents
	 * a single lucky success from resetting the counter during an
	 * outage, while avoiding false alarms from occasional individual
	 * failures in an otherwise healthy batch.
	 *
	 * @param int $successes Number of successful jobs in the batch.
	 * @param int $failures  Number of failed jobs in the batch.
	 * @return void
	 */
	public function check( int $successes, int $failures ): void {
		$total = $successes + $failures;
		if ( 0 === $total ) {
			return;
		}

		$consecutive   = $this->settings->get_consecutive_failures();
		$failure_ratio = $failures / $total;

		if ( $failure_ratio < self::FAILURE_RATIO ) {
			// Batch is healthy: reset the consecutive failure counter.
			if ( $consecutive > 0 ) {
				$this->settings->save_consecutive_failures( 0 );
			}
			return;
		}

		// Batch is considered failed (80%+ failures).
		$consecutive += $failures;
		$this->settings->save_consecutive_failures( $consecutive );

		if ( $consecutive < $this->settings->get_failure_threshold() ) {
			return;
		}

		$this->maybe_send( $consecutive );
	}

	/**
	 * Send a circuit breaker open notification email.
	 *
	 * Uses a separate cooldown key (wp4odoo_last_cb_email) so circuit
	 * breaker alerts do not interfere with regular failure emails.
	 *
	 * @param int $failure_count Number of consecutive batch failures that triggered the circuit.
	 * @return void
	 */
	public function notify_circuit_breaker_open( int $failure_count ): void {
		$last_cb_email = (int) get_option( 'wp4odoo_last_cb_email', 0 );
		if ( ( time() - $last_cb_email ) < $this->settings->get_failure_cooldown() ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		if ( empty( $admin_email ) ) {
			return;
		}

		$subject = __( '[WP4Odoo] Circuit breaker opened', 'wp4odoo' );

		$message = sprintf(
			/* translators: 1: number of consecutive batch failures, 2: health admin URL */
			__( "The WP4Odoo circuit breaker has opened after %1\$d consecutive high-failure batches. Queue processing is paused until Odoo connectivity recovers.\n\nCheck the health dashboard at %2\$s", 'wp4odoo' ),
			$failure_count,
			admin_url( 'admin.php?page=wp4odoo&tab=health' )
		);

		update_option( 'wp4odoo_last_cb_email', time(), false );

		if ( ! wp_mail( $admin_email, $subject, $message ) ) {
			$this->logger->error(
				'Failed to send circuit breaker notification email.',
				[ 'email' => $admin_email ]
			);
			return;
		}

		$this->logger->warning(
			'Circuit breaker notification sent to admin.',
			[
				'batch_failures' => $failure_count,
				'email'          => $admin_email,
			]
		);
	}

	/**
	 * Send a notification email if the cooldown has elapsed.
	 *
	 * @param int $consecutive Number of consecutive failures.
	 * @return void
	 */
	private function maybe_send( int $consecutive ): void {
		$last_email = $this->settings->get_last_failure_email();
		if ( ( time() - $last_email ) < $this->settings->get_failure_cooldown() ) {
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

		// Save timestamp BEFORE sending to prevent duplicate emails under
		// concurrency: two processes could both pass the cooldown check and
		// both call wp_mail() before either saves. Saving first narrows the
		// race window. If wp_mail() fails, the next cooldown cycle retries.
		$this->settings->save_last_failure_email( time() );

		if ( ! wp_mail( $admin_email, $subject, $message ) ) {
			$this->logger->error(
				'Failed to send failure notification email.',
				[ 'email' => $admin_email ]
			);
			return;
		}

		$this->logger->warning(
			'Failure notification sent to admin.',
			[
				'consecutive_failures' => $consecutive,
				'email'                => $admin_email,
			]
		);
	}
}
