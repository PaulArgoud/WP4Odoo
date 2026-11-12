<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\LMS_Handler_Base;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub for testing the abstract LMS_Handler_Base.
 */
class ConcreteLMSHandler extends LMS_Handler_Base {

	protected function get_course_post_type(): string {
		return 'test-course';
	}

	protected function get_course_price( int $course_id ): float {
		return (float) get_post_meta( $course_id, '_test_price', true );
	}

	protected function get_lms_label(): string {
		return 'TestLMS';
	}

	/**
	 * Expose the protected logger for assertions.
	 *
	 * @return Logger
	 */
	public function get_logger(): Logger {
		return $this->logger;
	}

	/**
	 * Expose build_invoice() for testing.
	 *
	 * @param int    $product_post_id WP post ID.
	 * @param int    $product_odoo_id Odoo product ID.
	 * @param int    $partner_id      Odoo partner ID.
	 * @param float  $amount          Amount.
	 * @param string $date            Date.
	 * @param string $ref             Reference.
	 * @param string $description     Description.
	 * @param bool   $auto_post       Auto-post flag.
	 * @return array<string, mixed>
	 */
	public function test_build_invoice( int $product_post_id, int $product_odoo_id, int $partner_id, float $amount, string $date, string $ref, string $description, bool $auto_post ): array {
		return $this->build_invoice( $product_post_id, $product_odoo_id, $partner_id, $amount, $date, $ref, $description, $auto_post );
	}

	/**
	 * Expose build_sale_order() for testing.
	 *
	 * @param int    $product_odoo_id Odoo product ID.
	 * @param int    $partner_id      Odoo partner ID.
	 * @param string $date            Date.
	 * @param string $line_name       Order line name.
	 * @return array<string, mixed>
	 */
	public function test_build_sale_order( int $product_odoo_id, int $partner_id, string $date, string $line_name ): array {
		return $this->build_sale_order( $product_odoo_id, $partner_id, $date, $line_name );
	}
}

/**
 * Unit tests for LMS_Handler_Base.
 *
 * Verifies constructor injection, invoice building (with and without
 * auto-post), sale order building, and product name resolution.
 */
#[CoversClass(\WP4Odoo\Modules\LMS_Handler_Base::class)]
class LMSHandlerBaseTest extends TestCase {

	private ConcreteLMSHandler $handler;
	private Logger $logger;

	protected function setUp(): void {
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->logger  = new Logger( 'test' );
		$this->handler = new ConcreteLMSHandler( $this->logger );
	}

	// ─── Constructor ──────────────────────────────────────────

	public function test_constructor_injects_logger(): void {
		$this->assertSame( $this->logger, $this->handler->get_logger() );
	}

	public function test_logger_is_accessible_by_subclass(): void {
		$this->assertInstanceOf( Logger::class, $this->handler->get_logger() );
	}

	// ─── build_invoice ────────────────────────────────────────

	public function test_build_invoice_returns_account_move_format(): void {
		$result = $this->handler->test_build_invoice( 0, 7, 42, 99.0, '2026-01-15', 'INV-001', 'Course Purchase', false );

		$this->assertSame( 'out_invoice', $result['move_type'] );
		$this->assertSame( 42, $result['partner_id'] );
		$this->assertSame( '2026-01-15', $result['invoice_date'] );
		$this->assertSame( 'INV-001', $result['ref'] );
		$this->assertIsArray( $result['invoice_line_ids'] );
		$this->assertSame( 7, $result['invoice_line_ids'][0][2]['product_id'] );
		$this->assertSame( 99.0, $result['invoice_line_ids'][0][2]['price_unit'] );
	}

	public function test_build_invoice_uses_post_title_as_line_name(): void {
		$GLOBALS['_wp_posts'][20] = (object) [
			'ID'         => 20,
			'post_type'  => 'sfwd-courses',
			'post_title' => 'PHP Mastery',
		];

		$result = $this->handler->test_build_invoice( 20, 7, 42, 99.0, '2026-01-15', 'INV-002', 'Course', false );

		$this->assertSame( 'PHP Mastery', $result['invoice_line_ids'][0][2]['name'] );
	}

	public function test_build_invoice_uses_fallback_when_post_not_found(): void {
		$result = $this->handler->test_build_invoice( 999, 7, 42, 99.0, '2026-01-15', 'INV-003', 'Fallback Description', false );

		$this->assertSame( 'Fallback Description', $result['invoice_line_ids'][0][2]['name'] );
	}

	public function test_build_invoice_without_auto_post(): void {
		$result = $this->handler->test_build_invoice( 0, 7, 42, 50.0, '2026-01-15', 'INV-004', 'Course', false );

		$this->assertArrayNotHasKey( '_auto_validate', $result );
	}

	public function test_build_invoice_with_auto_post(): void {
		$result = $this->handler->test_build_invoice( 0, 7, 42, 50.0, '2026-01-15', 'INV-005', 'Course', true );

		$this->assertTrue( $result['_auto_validate'] );
	}

	public function test_build_invoice_with_zero_product_post_id_uses_fallback(): void {
		$result = $this->handler->test_build_invoice( 0, 7, 42, 30.0, '2026-02-01', 'INV-006', 'Default Course', false );

		$this->assertSame( 'Default Course', $result['invoice_line_ids'][0][2]['name'] );
	}

	// ─── build_sale_order ─────────────────────────────────────

	public function test_build_sale_order_returns_sale_order_format(): void {
		$result = $this->handler->test_build_sale_order( 7, 42, '2026-01-15', 'PHP Mastery Enrollment' );

		$this->assertSame( 42, $result['partner_id'] );
		$this->assertSame( '2026-01-15', $result['date_order'] );
		$this->assertSame( 'sale', $result['state'] );
		$this->assertIsArray( $result['order_line'] );
	}

	public function test_build_sale_order_order_line_structure(): void {
		$result = $this->handler->test_build_sale_order( 7, 42, '2026-01-15', 'Course Enrollment' );

		$line = $result['order_line'][0];
		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertSame( 7, $line[2]['product_id'] );
		$this->assertSame( 1, $line[2]['quantity'] );
		$this->assertSame( 'Course Enrollment', $line[2]['name'] );
	}
}
