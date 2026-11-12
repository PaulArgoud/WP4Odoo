<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Donation_Handler_Base;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub for testing the abstract Donation_Handler_Base.
 */
class ConcreteDonationHandler extends Donation_Handler_Base {

	/**
	 * Expose the protected logger for assertions.
	 *
	 * @return Logger
	 */
	public function get_logger(): Logger {
		return $this->logger;
	}

	/**
	 * Expose load_form_by_cpt() for testing.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $post_type    Expected post type.
	 * @param string $entity_label Label for log warning.
	 * @return array<string, mixed>
	 */
	public function test_load_form_by_cpt( int $post_id, string $post_type, string $entity_label ): array {
		return $this->load_form_by_cpt( $post_id, $post_type, $entity_label );
	}

	/**
	 * Expose format_donation() for testing.
	 *
	 * @param int    $partner_id         Partner ID.
	 * @param int    $product_odoo_id    Product ID.
	 * @param float  $amount             Amount.
	 * @param string $date               Date.
	 * @param string $ref                Reference.
	 * @param string $product_name       Product name.
	 * @param string $fallback_name      Fallback name.
	 * @param bool   $use_donation_model Whether to use OCA donation model.
	 * @return array<string, mixed>
	 */
	public function test_format_donation( int $partner_id, int $product_odoo_id, float $amount, string $date, string $ref, string $product_name, string $fallback_name, bool $use_donation_model ): array {
		return $this->format_donation( $partner_id, $product_odoo_id, $amount, $date, $ref, $product_name, $fallback_name, $use_donation_model );
	}
}

/**
 * Unit tests for Donation_Handler_Base.
 *
 * Verifies constructor injection, CPT-based form loading, and
 * dual-model donation formatting (OCA donation vs account.move).
 */
#[CoversClass(\WP4Odoo\Modules\Donation_Handler_Base::class)]
class DonationHandlerBaseTest extends TestCase {

	private ConcreteDonationHandler $handler;
	private Logger $logger;

	protected function setUp(): void {
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->logger  = new Logger( 'test' );
		$this->handler = new ConcreteDonationHandler( $this->logger );
	}

	// ─── Constructor ──────────────────────────────────────────

	public function test_constructor_injects_logger(): void {
		$this->assertSame( $this->logger, $this->handler->get_logger() );
	}

	public function test_logger_is_accessible_by_subclass(): void {
		$this->assertInstanceOf( Logger::class, $this->handler->get_logger() );
	}

	// ─── load_form_by_cpt ─────────────────────────────────────

	public function test_load_form_by_cpt_returns_empty_when_post_not_found(): void {
		$result = $this->handler->test_load_form_by_cpt( 999, 'give_forms', 'GiveWP form' );

		$this->assertSame( [], $result );
	}

	public function test_load_form_by_cpt_returns_empty_when_post_type_mismatch(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'         => 10,
			'post_type'  => 'post',
			'post_title' => 'Not a form',
		];

		$result = $this->handler->test_load_form_by_cpt( 10, 'give_forms', 'GiveWP form' );

		$this->assertSame( [], $result );
	}

	public function test_load_form_by_cpt_returns_data_when_post_type_matches(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'         => 10,
			'post_type'  => 'give_forms',
			'post_title' => 'Test Donation Form',
		];

		$result = $this->handler->test_load_form_by_cpt( 10, 'give_forms', 'GiveWP form' );

		$this->assertSame( 'Test Donation Form', $result['form_name'] );
		$this->assertSame( 0.0, $result['list_price'] );
		$this->assertSame( 'service', $result['type'] );
	}

	// ─── format_donation — OCA donation model ─────────────────

	public function test_format_donation_for_donation_model(): void {
		$result = $this->handler->test_format_donation(
			42,
			7,
			25.0,
			'2026-01-15',
			'PAY-001',
			'Test Form',
			'Donation',
			true
		);

		$this->assertSame( 42, $result['partner_id'] );
		$this->assertSame( '2026-01-15', $result['donation_date'] );
		$this->assertSame( 'PAY-001', $result['payment_ref'] );
		$this->assertIsArray( $result['line_ids'] );
		$this->assertSame( 7, $result['line_ids'][0][2]['product_id'] );
		$this->assertSame( 25.0, $result['line_ids'][0][2]['unit_price'] );
	}

	// ─── format_donation — account.move fallback ──────────────

	public function test_format_donation_for_account_move(): void {
		$result = $this->handler->test_format_donation(
			42,
			7,
			50.0,
			'2026-02-01',
			'REF-002',
			'Campaign Title',
			'Donation',
			false
		);

		$this->assertSame( 'out_invoice', $result['move_type'] );
		$this->assertSame( 42, $result['partner_id'] );
		$this->assertSame( '2026-02-01', $result['invoice_date'] );
		$this->assertSame( 'REF-002', $result['ref'] );
		$this->assertIsArray( $result['invoice_line_ids'] );
		$this->assertSame( 7, $result['invoice_line_ids'][0][2]['product_id'] );
		$this->assertSame( 50.0, $result['invoice_line_ids'][0][2]['price_unit'] );
		$this->assertSame( 'Campaign Title', $result['invoice_line_ids'][0][2]['name'] );
	}

	public function test_format_donation_for_account_move_uses_fallback_name(): void {
		$result = $this->handler->test_format_donation(
			42,
			7,
			10.0,
			'2026-02-01',
			'REF-003',
			'',
			'Default Donation',
			false
		);

		$this->assertSame( 'Default Donation', $result['invoice_line_ids'][0][2]['name'] );
	}
}
