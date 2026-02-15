<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\SimplePay_Handler;
use WP4Odoo\Logger;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * @covers \WP4Odoo\Modules\SimplePay_Handler
 */
class SimplePayHandlerTest extends Module_Test_Case {

	private SimplePay_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new SimplePay_Handler( new Logger( 'test' ) );
	}

	// ─── Helpers ────────────────────────────────────────────

	private function create_form( int $id, string $title = 'Checkout Form' ): void {
		$post              = new \stdClass();
		$post->ID          = $id;
		$post->post_title  = $title;
		$post->post_type   = 'simple-pay';
		$post->post_date   = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][ $id ] = $post;
	}

	private function create_tracking_post( int $id, string $pi_id = 'pi_test123', float $amount = 50.0, int $form_id = 10 ): void {
		$post              = new \stdClass();
		$post->ID          = $id;
		$post->post_title  = 'Payment ' . $pi_id;
		$post->post_type   = 'wp4odoo_spay';
		$post->post_status = 'publish';
		$post->post_date   = '2026-02-10 14:30:00';

		$GLOBALS['_wp_posts'][ $id ] = $post;

		$GLOBALS['_wp_post_meta'][ $id ] = [
			'_spay_stripe_pi_id' => $pi_id,
			'_spay_amount'       => (string) $amount,
			'_spay_currency'     => 'USD',
			'_spay_email'        => 'payer@example.com',
			'_spay_name'         => 'John Payer',
			'_spay_date'         => '2026-02-10',
			'_spay_form_id'      => (string) $form_id,
			'_spay_form_title'   => 'Checkout Form',
			'_spay_type'         => 'one_time',
		];
	}

	/**
	 * Build a mock Stripe PaymentIntent object.
	 *
	 * @return object
	 */
	private function make_payment_intent( string $pi_id = 'pi_test123', int $amount = 5000, string $currency = 'usd' ): object {
		$billing              = new \stdClass();
		$billing->email       = 'billing@example.com';
		$billing->name        = 'Jane Billing';

		$charge                 = new \stdClass();
		$charge->billing_details = $billing;

		$charges       = new \stdClass();
		$charges->data = [ $charge ];

		$metadata                  = new \stdClass();
		$metadata->simpay_form_id  = '10';

		$pi                = new \stdClass();
		$pi->id            = $pi_id;
		$pi->amount        = $amount;
		$pi->currency      = $currency;
		$pi->receipt_email = 'receipt@example.com';
		$pi->charges       = $charges;
		$pi->metadata      = $metadata;
		$pi->created       = 1707523200; // 2024-02-10 00:00:00 UTC

		return $pi;
	}

	/**
	 * Build a mock Stripe Invoice object.
	 *
	 * @return object
	 */
	private function make_invoice( string $pi_id = 'pi_inv456', int $amount_paid = 2500, string $currency = 'eur' ): object {
		$sub_meta                  = new \stdClass();
		$sub_meta->simpay_form_id  = '10';

		$sub_details           = new \stdClass();
		$sub_details->metadata = $sub_meta;

		$invoice                       = new \stdClass();
		$invoice->payment_intent       = $pi_id;
		$invoice->amount_paid          = $amount_paid;
		$invoice->currency             = $currency;
		$invoice->customer_email       = 'subscriber@example.com';
		$invoice->customer_name        = 'Sub Customer';
		$invoice->subscription_details = $sub_details;
		$invoice->created              = 1707523200;

		return $invoice;
	}

	// ─── load_form ──────────────────────────────────────────

	public function test_load_form_returns_data(): void {
		$this->create_form( 10, 'Checkout Form' );
		$data = $this->handler->load_form( 10 );

		$this->assertSame( 'Checkout Form', $data['form_name'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_form_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_form( 999 ) );
	}

	public function test_load_form_empty_for_wrong_post_type(): void {
		$post            = new \stdClass();
		$post->ID        = 10;
		$post->post_title = 'Not a form';
		$post->post_type = 'post';

		$GLOBALS['_wp_posts'][10] = $post;

		$this->assertSame( [], $this->handler->load_form( 10 ) );
	}

	public function test_load_form_includes_zero_price(): void {
		$this->create_form( 10 );
		$data = $this->handler->load_form( 10 );

		$this->assertSame( 0.0, $data['list_price'] );
	}

	// ─── extract_from_payment_intent ────────────────────────

	public function test_extract_from_payment_intent_basic(): void {
		$pi   = $this->make_payment_intent();
		$data = $this->handler->extract_from_payment_intent( $pi );

		$this->assertSame( 'pi_test123', $data['stripe_pi_id'] );
		$this->assertSame( 50.0, $data['amount'] );
		$this->assertSame( 'USD', $data['currency'] );
		$this->assertSame( 'receipt@example.com', $data['email'] );
	}

	public function test_extract_from_payment_intent_uses_billing_email_fallback(): void {
		$pi                = $this->make_payment_intent();
		$pi->receipt_email = '';

		$data = $this->handler->extract_from_payment_intent( $pi );
		$this->assertSame( 'billing@example.com', $data['email'] );
	}

	public function test_extract_from_payment_intent_billing_name(): void {
		$pi   = $this->make_payment_intent();
		$data = $this->handler->extract_from_payment_intent( $pi );

		$this->assertSame( 'Jane Billing', $data['name'] );
	}

	public function test_extract_from_payment_intent_form_id_from_metadata(): void {
		$this->create_form( 10, 'My Form' );
		$pi   = $this->make_payment_intent();
		$data = $this->handler->extract_from_payment_intent( $pi );

		$this->assertSame( 10, $data['form_id'] );
		$this->assertSame( 'My Form', $data['form_title'] );
	}

	public function test_extract_from_payment_intent_type_is_one_time(): void {
		$pi   = $this->make_payment_intent();
		$data = $this->handler->extract_from_payment_intent( $pi );

		$this->assertSame( 'one_time', $data['type'] );
	}

	public function test_extract_from_payment_intent_empty_when_no_id(): void {
		$pi     = new \stdClass();
		$pi->id = '';

		$this->assertSame( [], $this->handler->extract_from_payment_intent( $pi ) );
	}

	public function test_extract_from_payment_intent_converts_cents_to_major(): void {
		$pi   = $this->make_payment_intent( 'pi_cents', 12345 );
		$data = $this->handler->extract_from_payment_intent( $pi );

		$this->assertSame( 123.45, $data['amount'] );
	}

	// ─── extract_from_invoice ───────────────────────────────

	public function test_extract_from_invoice_basic(): void {
		$invoice = $this->make_invoice();
		$data    = $this->handler->extract_from_invoice( $invoice );

		$this->assertSame( 'pi_inv456', $data['stripe_pi_id'] );
		$this->assertSame( 25.0, $data['amount'] );
		$this->assertSame( 'EUR', $data['currency'] );
		$this->assertSame( 'subscriber@example.com', $data['email'] );
	}

	public function test_extract_from_invoice_type_is_recurring(): void {
		$invoice = $this->make_invoice();
		$data    = $this->handler->extract_from_invoice( $invoice );

		$this->assertSame( 'recurring', $data['type'] );
	}

	public function test_extract_from_invoice_form_id_from_subscription_metadata(): void {
		$this->create_form( 10, 'Sub Form' );
		$invoice = $this->make_invoice();
		$data    = $this->handler->extract_from_invoice( $invoice );

		$this->assertSame( 10, $data['form_id'] );
		$this->assertSame( 'Sub Form', $data['form_title'] );
	}

	public function test_extract_from_invoice_empty_when_no_payment_intent(): void {
		$invoice                  = new \stdClass();
		$invoice->payment_intent  = '';

		$this->assertSame( [], $this->handler->extract_from_invoice( $invoice ) );
	}

	public function test_extract_from_invoice_customer_name(): void {
		$invoice = $this->make_invoice();
		$data    = $this->handler->extract_from_invoice( $invoice );

		$this->assertSame( 'Sub Customer', $data['name'] );
	}

	// ─── find_existing_payment ──────────────────────────────

	public function test_find_existing_payment_returns_zero_when_not_found(): void {
		$this->assertSame( 0, $this->handler->find_existing_payment( 'pi_nonexistent' ) );
	}

	// ─── create_tracking_post ───────────────────────────────

	public function test_create_tracking_post_returns_post_id(): void {
		$data = [
			'stripe_pi_id' => 'pi_new123',
			'amount'       => 100.0,
			'currency'     => 'USD',
			'email'        => 'test@example.com',
			'name'         => 'Test User',
			'date'         => '2026-02-10',
			'form_id'      => 10,
			'form_title'   => 'Checkout',
			'type'         => 'one_time',
		];

		$post_id = $this->handler->create_tracking_post( $data );
		$this->assertGreaterThan( 0, $post_id );
	}

	public function test_create_tracking_post_stores_meta(): void {
		$data = [
			'stripe_pi_id' => 'pi_meta456',
			'amount'       => 75.0,
			'currency'     => 'EUR',
			'email'        => 'meta@example.com',
			'name'         => 'Meta User',
			'date'         => '2026-02-10',
			'form_id'      => 20,
			'form_title'   => 'Donate',
			'type'         => 'recurring',
		];

		$post_id = $this->handler->create_tracking_post( $data );
		$this->assertGreaterThan( 0, $post_id );

		// Verify meta was stored.
		$stored_pi = get_post_meta( $post_id, '_spay_stripe_pi_id', true );
		$this->assertSame( 'pi_meta456', $stored_pi );

		$stored_amount = get_post_meta( $post_id, '_spay_amount', true );
		$this->assertSame( 75.0, $stored_amount );
	}

	// ─── load_payment (account.move) ────────────────────────

	public function test_load_payment_account_move_returns_data(): void {
		$this->create_tracking_post( 100 );
		$data = $this->handler->load_payment( 100, 42, 7, false );

		$this->assertSame( 'out_invoice', $data['move_type'] );
		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2026-02-10', $data['invoice_date'] );
	}

	public function test_load_payment_account_move_includes_invoice_line_ids(): void {
		$this->create_tracking_post( 100, 'pi_test123', 75.0 );
		$data = $this->handler->load_payment( 100, 42, 7, false );

		$this->assertArrayHasKey( 'invoice_line_ids', $data );
		$line = $data['invoice_line_ids'][0];
		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertSame( 7, $line[2]['product_id'] );
		$this->assertSame( 1, $line[2]['quantity'] );
		$this->assertSame( 75.0, $line[2]['price_unit'] );
	}

	public function test_load_payment_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_payment( 999, 42, 7, false ) );
	}

	public function test_load_payment_includes_ref(): void {
		$this->create_tracking_post( 100, 'pi_ref789' );
		$data = $this->handler->load_payment( 100, 42, 7, false );

		$this->assertSame( 'spay-pi_ref789', $data['ref'] );
	}

	public function test_load_payment_empty_for_wrong_post_type(): void {
		$post            = new \stdClass();
		$post->ID        = 100;
		$post->post_title = 'Not a payment';
		$post->post_type = 'post';
		$post->post_date = '2026-02-10 00:00:00';

		$GLOBALS['_wp_posts'][100] = $post;

		$this->assertSame( [], $this->handler->load_payment( 100, 42, 7, false ) );
	}

	// ─── load_payment (donation.donation — OCA) ─────────────

	public function test_load_payment_oca_returns_data(): void {
		$this->create_tracking_post( 100 );
		$data = $this->handler->load_payment( 100, 42, 7, true );

		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2026-02-10', $data['donation_date'] );
		$this->assertSame( 'spay-pi_test123', $data['payment_ref'] );
	}

	public function test_load_payment_oca_includes_line_ids(): void {
		$this->create_tracking_post( 100, 'pi_test123', 25.0 );
		$data = $this->handler->load_payment( 100, 42, 7, true );

		$this->assertArrayHasKey( 'line_ids', $data );
		$line = $data['line_ids'][0];
		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertSame( 7, $line[2]['product_id'] );
		$this->assertSame( 25.0, $line[2]['unit_price'] );
	}

	public function test_load_payment_oca_has_no_move_type(): void {
		$this->create_tracking_post( 100 );
		$data = $this->handler->load_payment( 100, 42, 7, true );

		$this->assertArrayNotHasKey( 'move_type', $data );
	}

	public function test_load_payment_oca_date_field_is_donation_date(): void {
		$this->create_tracking_post( 100 );
		$data = $this->handler->load_payment( 100, 42, 7, true );

		$this->assertArrayHasKey( 'donation_date', $data );
		$this->assertArrayNotHasKey( 'invoice_date', $data );
	}

	public function test_load_payment_oca_uses_unit_price(): void {
		$this->create_tracking_post( 100, 'pi_test123', 30.0 );
		$data = $this->handler->load_payment( 100, 42, 7, true );

		$line = $data['line_ids'][0][2];
		$this->assertArrayHasKey( 'unit_price', $line );
		$this->assertArrayNotHasKey( 'price_unit', $line );
	}

	// ─── Edge case ──────────────────────────────────────────

	public function test_load_payment_with_missing_form_title_uses_fallback(): void {
		$post              = new \stdClass();
		$post->ID          = 100;
		$post->post_title  = 'Payment pi_test';
		$post->post_type   = 'wp4odoo_spay';
		$post->post_status = 'publish';
		$post->post_date   = '2026-02-10 14:30:00';

		$GLOBALS['_wp_posts'][100] = $post;

		$GLOBALS['_wp_post_meta'][100] = [
			'_spay_stripe_pi_id' => 'pi_test',
			'_spay_amount'       => '50.0',
			'_spay_currency'     => 'USD',
			'_spay_email'        => 'payer@example.com',
			'_spay_name'         => 'John Payer',
			'_spay_date'         => '2026-02-10',
			'_spay_form_id'      => '0',
			'_spay_form_title'   => '',
			'_spay_type'         => 'one_time',
		];

		$data = $this->handler->load_payment( 100, 42, 7, false );

		$line_name = $data['invoice_line_ids'][0][2]['name'];
		$this->assertSame( 'Payment', $line_name );
	}
}
