<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Simple Pay Handler — data access for payment forms and Stripe payments.
 *
 * Manages the hidden wp4odoo_spay CPT used to track Stripe payments locally,
 * extracts data from Stripe webhook objects, and delegates Odoo formatting to
 * Odoo_Accounting_Formatter (shared with GiveWP and Charitable).
 *
 * Called by SimplePay_Module via its load_wp_data dispatch and hook callbacks.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class SimplePay_Handler {

	/**
	 * Meta field mapping: data key => meta key.
	 *
	 * @var array<string, string>
	 */
	private const PAYMENT_META = [
		'stripe_pi_id' => '_spay_stripe_pi_id',
		'amount'       => '_spay_amount',
		'currency'     => '_spay_currency',
		'email'        => '_spay_email',
		'name'         => '_spay_name',
		'date'         => '_spay_date',
		'form_id'      => '_spay_form_id',
		'form_title'   => '_spay_form_title',
		'type'         => '_spay_type',
	];

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

	// ─── Donor name extraction ────────────────────────────

	/**
	 * Extract the payer display name for a payment.
	 *
	 * @param int $wp_id Tracking post ID (wp4odoo_spay).
	 * @return string Payer name, or empty string.
	 */
	public function get_donor_name( int $wp_id ): string {
		return (string) get_post_meta( $wp_id, '_spay_name', true );
	}

	// ─── CPT registration ─────────────────────────────────

	/**
	 * Register the hidden wp4odoo_spay CPT for payment tracking.
	 *
	 * @return void
	 */
	public static function register_cpt(): void {
		register_post_type(
			'wp4odoo_spay',
			[
				'labels'          => [
					'name'          => 'WP4Odoo Payments',
					'singular_name' => 'WP4Odoo Payment',
				],
				'public'          => false,
				'show_ui'         => false,
				'show_in_menu'    => false,
				'supports'        => [ 'title' ],
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			]
		);
	}

	// ─── Stripe data extraction ───────────────────────────

	/**
	 * Extract payment data from a Stripe PaymentIntent object.
	 *
	 * @param object $pi Stripe PaymentIntent object.
	 * @return array<string, mixed> Extracted payment data.
	 */
	public function extract_from_payment_intent( object $pi ): array {
		$pi_id = $pi->id ?? '';
		if ( empty( $pi_id ) ) {
			$this->logger->warning( 'PaymentIntent has no ID.' );
			return [];
		}

		// Email: try receipt_email first, then billing_details.
		$email = $pi->receipt_email ?? '';
		if ( empty( $email ) && isset( $pi->charges->data[0]->billing_details->email ) ) {
			$email = $pi->charges->data[0]->billing_details->email;
		}

		// Name: from billing_details.
		$name = '';
		if ( isset( $pi->charges->data[0]->billing_details->name ) ) {
			$name = $pi->charges->data[0]->billing_details->name;
		}

		// Form ID from metadata.
		$form_id    = 0;
		$form_title = '';
		if ( isset( $pi->metadata->simpay_form_id ) ) {
			$form_id = (int) $pi->metadata->simpay_form_id;
			$post    = get_post( $form_id );
			if ( $post && 'simple-pay' === $post->post_type ) {
				$form_title = $post->post_title;
			}
		}

		return [
			'stripe_pi_id' => (string) $pi_id,
			'amount'       => (float) ( ( (int) ( $pi->amount ?? 0 ) ) / 100 ),
			'currency'     => strtoupper( (string) ( $pi->currency ?? '' ) ),
			'email'        => (string) $email,
			'name'         => (string) $name,
			'date'         => gmdate( 'Y-m-d', (int) ( $pi->created ?? time() ) ),
			'form_id'      => $form_id,
			'form_title'   => $form_title,
			'type'         => 'one_time',
		];
	}

	/**
	 * Extract payment data from a Stripe Invoice object (recurring).
	 *
	 * @param object $invoice Stripe Invoice object.
	 * @return array<string, mixed> Extracted payment data.
	 */
	public function extract_from_invoice( object $invoice ): array {
		$pi_id = $invoice->payment_intent ?? '';
		if ( empty( $pi_id ) ) {
			$this->logger->warning( 'Invoice has no payment_intent.' );
			return [];
		}

		$email = $invoice->customer_email ?? '';
		$name  = $invoice->customer_name ?? '';

		// Form ID from subscription metadata.
		$form_id    = 0;
		$form_title = '';
		if ( isset( $invoice->subscription_details->metadata->simpay_form_id ) ) {
			$form_id = (int) $invoice->subscription_details->metadata->simpay_form_id;
		} elseif ( isset( $invoice->lines->data[0]->metadata->simpay_form_id ) ) {
			$form_id = (int) $invoice->lines->data[0]->metadata->simpay_form_id;
		}

		if ( $form_id > 0 ) {
			$post = get_post( $form_id );
			if ( $post && 'simple-pay' === $post->post_type ) {
				$form_title = $post->post_title;
			}
		}

		return [
			'stripe_pi_id' => (string) $pi_id,
			'amount'       => (float) ( ( (int) ( $invoice->amount_paid ?? 0 ) ) / 100 ),
			'currency'     => strtoupper( (string) ( $invoice->currency ?? '' ) ),
			'email'        => (string) $email,
			'name'         => (string) $name,
			'date'         => gmdate( 'Y-m-d', (int) ( $invoice->created ?? time() ) ),
			'form_id'      => $form_id,
			'form_title'   => $form_title,
			'type'         => 'recurring',
		];
	}

	// ─── Tracking posts ───────────────────────────────────

	/**
	 * Find an existing tracking post by Stripe PaymentIntent ID.
	 *
	 * @param string $stripe_pi_id Stripe PaymentIntent ID.
	 * @return int Post ID if found, 0 otherwise.
	 */
	public function find_existing_payment( string $stripe_pi_id ): int {
		$posts = \get_posts(
			[
				'post_type'      => 'wp4odoo_spay',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => '_spay_stripe_pi_id',
				'meta_value'     => $stripe_pi_id,
				'fields'         => 'ids',
			]
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	/**
	 * Create a tracking post from extracted Stripe data.
	 *
	 * @param array<string, mixed> $data Extracted payment data.
	 * @return int Post ID or 0 on failure.
	 */
	public function create_tracking_post( array $data ): int {
		$pi_id = $data['stripe_pi_id'] ?? '';

		$post_id = wp_insert_post(
			[
				'post_type'   => 'wp4odoo_spay',
				'post_title'  => 'Payment ' . $pi_id,
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$this->logger->error(
				'Failed to create tracking post.',
				[ 'error' => $post_id->get_error_message() ]
			);
			return 0;
		}

		$post_id = (int) $post_id;

		foreach ( self::PAYMENT_META as $key => $meta_key ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $post_id, $meta_key, $data[ $key ] );
			}
		}

		$this->logger->info(
			'Created payment tracking post.',
			[
				'post_id'      => $post_id,
				'stripe_pi_id' => $pi_id,
				'type'         => $data['type'] ?? 'unknown',
			]
		);

		return $post_id;
	}

	// ─── Load form ────────────────────────────────────────

	/**
	 * Load a WP Simple Pay form as a service product.
	 *
	 * @param int $post_id WP Simple Pay form post ID.
	 * @return array<string, mixed> Form data for field mapping, or empty if not found.
	 */
	public function load_form( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'simple-pay' !== $post->post_type ) {
			$this->logger->warning( 'WP Simple Pay form not found.', [ 'post_id' => $post_id ] );
			return [];
		}

		return [
			'form_name'  => $post->post_title,
			'list_price' => 0.0,
			'type'       => 'service',
		];
	}

	// ─── Load payment ─────────────────────────────────────

	/**
	 * Load a tracked payment, formatted for the target Odoo model.
	 *
	 * When $use_donation_model is true, returns data for OCA donation.donation.
	 * Otherwise returns data for core account.move (invoice).
	 *
	 * @param int  $wp_id              Tracking post ID (wp4odoo_spay).
	 * @param int  $partner_id         Resolved Odoo partner ID.
	 * @param int  $form_odoo_id       Resolved Odoo product.product ID for the form.
	 * @param bool $use_donation_model True for OCA donation.donation, false for account.move.
	 * @return array<string, mixed> Payment data, or empty if not found.
	 */
	public function load_payment( int $wp_id, int $partner_id, int $form_odoo_id, bool $use_donation_model ): array {
		$post = get_post( $wp_id );
		if ( ! $post || 'wp4odoo_spay' !== $post->post_type ) {
			$this->logger->warning( 'Payment tracking post not found.', [ 'wp_id' => $wp_id ] );
			return [];
		}

		$amount     = (float) get_post_meta( $wp_id, '_spay_amount', true );
		$form_title = (string) get_post_meta( $wp_id, '_spay_form_title', true );
		$pi_id      = (string) get_post_meta( $wp_id, '_spay_stripe_pi_id', true );
		$date       = (string) get_post_meta( $wp_id, '_spay_date', true );
		$ref        = 'spay-' . $pi_id;

		if ( empty( $date ) ) {
			$date = substr( $post->post_date, 0, 10 );
		}

		if ( $use_donation_model ) {
			return Odoo_Accounting_Formatter::for_donation_model( $partner_id, $form_odoo_id, $amount, $date, $ref );
		}

		return Odoo_Accounting_Formatter::for_account_move( $partner_id, $form_odoo_id, $amount, $date, $ref, $form_title, __( 'Payment', 'wp4odoo' ) );
	}
}
