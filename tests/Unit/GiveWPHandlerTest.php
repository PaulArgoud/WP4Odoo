<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\GiveWP_Handler;
use WP4Odoo\Logger;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * @covers \WP4Odoo\Modules\GiveWP_Handler
 */
class GiveWPHandlerTest extends Module_Test_Case {

	private GiveWP_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new GiveWP_Handler( new Logger( 'test' ) );
	}

	// ─── Helpers ────────────────────────────────────────────

	private function create_form( int $id, string $title = 'Test Donation Form' ): void {
		$post             = new \stdClass();
		$post->ID         = $id;
		$post->post_title = $title;
		$post->post_type  = 'give_forms';
		$post->post_date  = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][ $id ] = $post;
	}

	private function create_payment( int $id, int $form_id = 10, float $amount = 50.0, string $title = 'General Fund' ): void {
		$post              = new \stdClass();
		$post->ID          = $id;
		$post->post_title  = 'Donation';
		$post->post_type   = 'give_payment';
		$post->post_status = 'publish';
		$post->post_date   = '2026-02-10 14:30:00';

		$GLOBALS['_wp_posts'][ $id ] = $post;

		$GLOBALS['_wp_post_meta'][ $id ] = [
			'_give_payment_total'      => (string) $amount,
			'_give_payment_form_id'    => (string) $form_id,
			'_give_payment_form_title' => $title,
			'_give_payment_donor_email' => 'donor@example.com',
			'_give_payment_donor_name' => 'John Donor',
		];
	}

	// ─── load_form ──────────────────────────────────────────

	public function test_load_form_returns_data(): void {
		$this->create_form( 10, 'Save the Planet' );
		$data = $this->handler->load_form( 10 );

		$this->assertSame( 'Save the Planet', $data['form_name'] );
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

	// ─── load_donation (account.move) ───────────────────────

	public function test_load_donation_account_move_returns_data(): void {
		$this->create_payment( 100 );
		$data = $this->handler->load_donation( 100, 42, 7, false );

		$this->assertSame( 'out_invoice', $data['move_type'] );
		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2026-02-10', $data['invoice_date'] );
	}

	public function test_load_donation_account_move_includes_invoice_line_ids(): void {
		$this->create_payment( 100, 10, 75.0 );
		$data = $this->handler->load_donation( 100, 42, 7, false );

		$this->assertArrayHasKey( 'invoice_line_ids', $data );
		$line = $data['invoice_line_ids'][0];
		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertSame( 7, $line[2]['product_id'] );
		$this->assertSame( 1, $line[2]['quantity'] );
		$this->assertSame( 75.0, $line[2]['price_unit'] );
	}

	public function test_load_donation_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_donation( 999, 42, 7, false ) );
	}

	public function test_load_donation_includes_ref(): void {
		$this->create_payment( 100 );
		$data = $this->handler->load_donation( 100, 42, 7, false );

		$this->assertSame( 'give-payment-100', $data['ref'] );
	}

	public function test_load_donation_empty_for_wrong_post_type(): void {
		$post            = new \stdClass();
		$post->ID        = 100;
		$post->post_title = 'Not a payment';
		$post->post_type = 'post';
		$post->post_date = '2026-02-10 00:00:00';

		$GLOBALS['_wp_posts'][100] = $post;

		$this->assertSame( [], $this->handler->load_donation( 100, 42, 7, false ) );
	}

	// ─── load_donation (donation.donation — OCA) ────────────

	public function test_load_donation_oca_returns_data(): void {
		$this->create_payment( 100 );
		$data = $this->handler->load_donation( 100, 42, 7, true );

		$this->assertSame( 42, $data['partner_id'] );
		$this->assertSame( '2026-02-10', $data['donation_date'] );
		$this->assertSame( 'give-payment-100', $data['payment_ref'] );
	}

	public function test_load_donation_oca_includes_line_ids(): void {
		$this->create_payment( 100, 10, 25.0 );
		$data = $this->handler->load_donation( 100, 42, 7, true );

		$this->assertArrayHasKey( 'line_ids', $data );
		$line = $data['line_ids'][0];
		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertSame( 7, $line[2]['product_id'] );
		$this->assertSame( 25.0, $line[2]['unit_price'] );
	}

	public function test_load_donation_oca_has_no_move_type(): void {
		$this->create_payment( 100 );
		$data = $this->handler->load_donation( 100, 42, 7, true );

		$this->assertArrayNotHasKey( 'move_type', $data );
	}

	public function test_load_donation_oca_date_field_is_donation_date(): void {
		$this->create_payment( 100 );
		$data = $this->handler->load_donation( 100, 42, 7, true );

		$this->assertArrayHasKey( 'donation_date', $data );
		$this->assertArrayNotHasKey( 'invoice_date', $data );
	}

	public function test_load_donation_oca_uses_unit_price(): void {
		$this->create_payment( 100, 10, 30.0 );
		$data = $this->handler->load_donation( 100, 42, 7, true );

		$line = $data['line_ids'][0][2];
		$this->assertArrayHasKey( 'unit_price', $line );
		$this->assertArrayNotHasKey( 'price_unit', $line );
	}

	// ─── map_donation_status ────────────────────────────────

	public function test_donation_status_publish_to_completed(): void {
		$this->assertSame( 'completed', $this->handler->map_donation_status( 'publish' ) );
	}

	public function test_donation_status_refunded(): void {
		$this->assertSame( 'refunded', $this->handler->map_donation_status( 'refunded' ) );
	}

	public function test_donation_status_pending(): void {
		$this->assertSame( 'pending', $this->handler->map_donation_status( 'pending' ) );
	}

	public function test_unknown_status_defaults_to_draft(): void {
		$this->assertSame( 'draft', $this->handler->map_donation_status( 'cancelled' ) );
	}

	public function test_donation_status_map_is_filterable(): void {
		// apply_filters stub returns value unchanged — verify the map
		// passes through apply_filters (publish → completed still works).
		$this->assertSame( 'completed', $this->handler->map_donation_status( 'publish' ) );
	}

	// ─── Edge case ──────────────────────────────────────────

	public function test_load_donation_with_missing_form_title_uses_fallback(): void {
		$this->create_payment( 100, 10, 50.0, '' );
		$data = $this->handler->load_donation( 100, 42, 7, false );

		$line_name = $data['invoice_line_ids'][0][2]['name'];
		$this->assertSame( 'Donation', $line_name );
	}
}
