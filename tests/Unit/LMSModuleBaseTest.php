<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\LMS_Module_Base;

/**
 * Concrete stub to test the abstract LMS_Module_Base.
 *
 * Exposes the protected load_enrollment_from_synthetic() method
 * via a public proxy for unit testing.
 */
class LMSModuleBaseTestModule extends LMS_Module_Base {

	public function __construct() {
		parent::__construct(
			'lms_test',
			'LMS Test',
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);

		$this->odoo_models = [
			'course'     => 'product.product',
			'enrollment' => 'sale.order',
		];
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [
			'sync_courses'     => true,
			'sync_enrollments' => true,
		];
	}

	/**
	 * Expose load_enrollment_from_synthetic() for testing.
	 *
	 * @param int      $synthetic_id Synthetic enrollment ID.
	 * @param callable $load_fn      Handler load function.
	 * @param callable $format_fn    Handler format function.
	 * @return array<string, mixed>
	 */
	public function test_load_enrollment( int $synthetic_id, callable $load_fn, callable $format_fn ): array {
		return $this->load_enrollment_from_synthetic( $synthetic_id, $load_fn, $format_fn );
	}
}

/**
 * Unit tests for the LMS_Module_Base abstract class.
 *
 * Tests the shared enrollment loading pipeline: decode synthetic ID,
 * load enrollment data, resolve partner, resolve course product,
 * and format sale order.
 */
class LMSModuleBaseTest extends Module_Test_Case {

	private LMSModuleBaseTestModule $module;

	protected function setUp(): void {
		parent::setUp();

		$this->module = new LMSModuleBaseTestModule();
	}

	// ─── Empty load_fn ────────────────────────────────────

	public function test_returns_empty_when_load_fn_returns_empty(): void {
		$synthetic_id = 5 * 1_000_000 + 10; // user=5, course=10

		$load_fn   = fn( int $user_id, int $course_id ) => [];
		$format_fn = fn( int $product_odoo_id, int $partner_id, string $date, string $name ) => [
			'partner_id' => $partner_id,
		];

		$result = $this->module->test_load_enrollment( $synthetic_id, $load_fn, $format_fn );

		$this->assertSame( [], $result );
	}

	// ─── Partner resolution failure ───────────────────────

	public function test_returns_empty_when_partner_resolution_fails(): void {
		$synthetic_id = 5 * 1_000_000 + 10; // user=5, course=10

		$load_fn = fn( int $user_id, int $course_id ) => [
			'user_email' => 'student@example.com',
			'user_name'  => 'Test Student',
			'date'       => '2026-01-15',
		];

		$format_fn = fn( int $product_odoo_id, int $partner_id, string $date, string $name ) => [
			'partner_id' => $partner_id,
		];

		// The mock client is not connected, so resolve_partner_from_email
		// returns null (falsy), and the method returns [].
		$result = $this->module->test_load_enrollment( $synthetic_id, $load_fn, $format_fn );

		$this->assertSame( [], $result );
	}
}
