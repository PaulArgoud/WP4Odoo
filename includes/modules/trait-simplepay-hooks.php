<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Simple Pay hook callbacks for push operations.
 *
 * Extracted from SimplePay_Module for single responsibility.
 * Handles payment form saves and Stripe webhook events.
 *
 * Unlike GiveWP/Charitable, WP Simple Pay does not store payments in
 * WordPress — all payment data lives in Stripe. This trait listens for
 * Stripe webhook actions fired by WP Simple Pay, extracts payment data,
 * creates tracking posts (wp4odoo_spay), and enqueues sync jobs.
 *
 * Expects the using class to provide:
 * - should_sync(): bool             (from Module_Base)
 * - logger: Logger                  (from Module_Base)
 * - handler: SimplePay_Handler      (from SimplePay_Module)
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
trait SimplePay_Hooks {

	/**
	 * Handle WP Simple Pay form save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_form_save( int $post_id ): void {
		$this->handle_cpt_save( $post_id, 'simple-pay', 'sync_forms', 'form' );
	}

	/**
	 * Handle Stripe payment_intent.succeeded webhook.
	 *
	 * Extracts payment data from the Stripe PaymentIntent, deduplicates
	 * by PaymentIntent ID, creates a tracking post, and enqueues a push.
	 *
	 * @param object $event           Stripe Event object.
	 * @param object $payment_intent  Stripe PaymentIntent object.
	 * @return void
	 */
	public function on_payment_succeeded( object $event, object $payment_intent ): void {
		$this->process_stripe_payment( $this->handler->extract_from_payment_intent( $payment_intent ) );
	}

	/**
	 * Handle Stripe invoice.payment_succeeded webhook (recurring).
	 *
	 * Extracts payment data from the Stripe Invoice, deduplicates by
	 * PaymentIntent ID, creates a tracking post, and enqueues a push.
	 *
	 * @param object $event   Stripe Event object.
	 * @param object $invoice Stripe Invoice object.
	 * @return void
	 */
	public function on_invoice_payment_succeeded( object $event, object $invoice ): void {
		$this->process_stripe_payment( $this->handler->extract_from_invoice( $invoice ) );
	}

	/**
	 * Process a Stripe payment: dedup, create tracking post, enqueue.
	 *
	 * Shared logic for both payment_intent.succeeded and
	 * invoice.payment_succeeded webhooks.
	 *
	 * @param array<string, mixed> $data Extracted payment data from handler.
	 * @return void
	 */
	private function process_stripe_payment( array $data ): void {
		if ( ! $this->should_sync( 'sync_payments' ) ) {
			return;
		}

		if ( empty( $data['stripe_pi_id'] ) ) {
			return;
		}

		// Deduplicate by Stripe PaymentIntent ID.
		$existing = $this->handler->find_existing_payment( $data['stripe_pi_id'] );
		if ( $existing > 0 ) {
			return;
		}

		$post_id = $this->handler->create_tracking_post( $data );
		if ( $post_id <= 0 ) {
			return;
		}

		Queue_Manager::push( 'simplepay', 'payment', 'create', $post_id );
	}
}
