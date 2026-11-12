<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\Dual_Accounting_Module_Base;
use WP4Odoo\Sync_Result;

/**
 * Concrete stub to test the abstract Dual_Accounting_Module_Base.
 *
 * Implements all abstract methods with test-friendly defaults and
 * exposes protected methods via public proxies.
 */
class DualAccountingModuleBaseTestModule extends Dual_Accounting_Module_Base {

	/** @var array<string, mixed> */
	public array $test_parent_data = [];

	/** @var array<string, mixed> */
	public array $test_child_data = [];

	/** @var string */
	public string $test_donor_name = '';

	public function __construct() {
		parent::__construct(
			'dual_acct_test',
			'Dual Accounting Test',
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);

		$this->odoo_models = [
			'form'     => 'product.product',
			'donation' => 'account.move',
		];
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [
			'sync_forms'              => true,
			'sync_donations'          => true,
			'auto_validate_donations' => true,
		];
	}

	protected function get_child_entity_type(): string {
		return 'donation';
	}

	protected function get_parent_entity_type(): string {
		return 'form';
	}

	protected function get_child_cpt(): string {
		return 'give_payment';
	}

	protected function get_email_meta_key(): string {
		return '_give_payment_donor_email';
	}

	protected function get_parent_meta_key(): string {
		return '_give_payment_form_id';
	}

	protected function get_validate_setting_key(): string {
		return 'auto_validate_donations';
	}

	protected function get_validate_status(): ?string {
		return 'publish';
	}

	protected function handler_load_parent( int $wp_id ): array {
		return $this->test_parent_data;
	}

	protected function handler_get_donor_name( int $wp_id ): string {
		return $this->test_donor_name;
	}

	protected function handler_load_child( int $wp_id, int $partner_id, int $parent_odoo_id, bool $use_donation_model ): array {
		return $this->test_child_data;
	}

	/**
	 * Expose load_wp_data() for testing.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	public function test_load_wp_data( string $entity_type, int $wp_id ): array {
		return $this->load_wp_data( $entity_type, $wp_id );
	}

	/**
	 * Expose get_dedup_domain() for testing.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo values.
	 * @return array
	 */
	public function test_get_dedup_domain( string $entity_type, array $odoo_values ): array {
		return $this->get_dedup_domain( $entity_type, $odoo_values );
	}
}

/**
 * Unit tests for the Dual_Accounting_Module_Base abstract class.
 *
 * Tests sync direction, deduplication domains, load_wp_data dispatch
 * (parent, child, unknown), child data resolution pipeline, and
 * map_to_odoo identity pass-through for child entities.
 */
#[CoversClass(\WP4Odoo\Modules\Dual_Accounting_Module_Base::class)]
class DualAccountingModuleBaseTest extends Module_Test_Case {

	private DualAccountingModuleBaseTestModule $module;

	protected function setUp(): void {
		parent::setUp();

		$this->module = new DualAccountingModuleBaseTestModule();
	}

	// ─── Sync direction ───────────────────────────────────────

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── load_wp_data dispatch ────────────────────────────────

	public function test_load_wp_data_dispatches_to_parent(): void {
		$this->module->test_parent_data = [ 'form_name' => 'Test Form', 'list_price' => 0.0 ];

		$result = $this->module->test_load_wp_data( 'form', 1 );

		$this->assertSame( 'Test Form', $result['form_name'] );
	}

	public function test_load_wp_data_returns_empty_for_unknown_entity(): void {
		$result = $this->module->test_load_wp_data( 'unknown', 1 );

		$this->assertSame( [], $result );
	}

	// ─── Child data resolution ────────────────────────────────

	public function test_load_wp_data_child_returns_empty_when_post_not_found(): void {
		$result = $this->module->test_load_wp_data( 'donation', 999 );

		$this->assertSame( [], $result );
	}

	public function test_load_wp_data_child_returns_empty_when_cpt_mismatch(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'         => 10,
			'post_type'  => 'post',
			'post_title' => 'Not a donation',
		];

		$result = $this->module->test_load_wp_data( 'donation', 10 );

		$this->assertSame( [], $result );
	}

	public function test_load_wp_data_child_returns_empty_when_no_email(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'         => 10,
			'post_type'  => 'give_payment',
			'post_title' => 'Donation #10',
		];

		$result = $this->module->test_load_wp_data( 'donation', 10 );

		$this->assertSame( [], $result );
	}

	public function test_load_wp_data_child_returns_empty_when_partner_fails(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'          => 10,
			'post_type'   => 'give_payment',
			'post_title'  => 'Donation #10',
			'post_status' => 'publish',
		];
		$GLOBALS['_wp_post_meta'][10] = [
			'_give_payment_donor_email' => 'donor@example.com',
		];

		$result = $this->module->test_load_wp_data( 'donation', 10 );

		// Partner resolution fails (mock client not connected).
		$this->assertSame( [], $result );
	}

	// ─── map_to_odoo ──────────────────────────────────────────

	public function test_map_to_odoo_child_returns_data_as_is(): void {
		$data = [
			'partner_id' => 42,
			'amount'     => 25.0,
		];

		$result = $this->module->map_to_odoo( 'donation', $data );

		$this->assertSame( $data, $result );
	}

	// ─── Deduplication domains ────────────────────────────────

	public function test_dedup_domain_for_parent_with_name(): void {
		$result = $this->module->test_get_dedup_domain( 'form', [ 'name' => 'Annual Gala' ] );

		$this->assertSame( [ [ 'name', '=', 'Annual Gala' ] ], $result );
	}

	public function test_dedup_domain_for_parent_without_name(): void {
		$result = $this->module->test_get_dedup_domain( 'form', [ 'list_price' => 0.0 ] );

		$this->assertSame( [], $result );
	}

	public function test_dedup_domain_for_child_with_payment_ref(): void {
		$result = $this->module->test_get_dedup_domain( 'donation', [ 'payment_ref' => 'PAY-001' ] );

		$this->assertSame( [ [ 'payment_ref', '=', 'PAY-001' ] ], $result );
	}

	public function test_dedup_domain_for_child_with_ref(): void {
		$result = $this->module->test_get_dedup_domain( 'donation', [ 'ref' => 'INV-001' ] );

		$this->assertSame( [ [ 'ref', '=', 'INV-001' ] ], $result );
	}

	public function test_dedup_domain_for_child_prefers_payment_ref(): void {
		$result = $this->module->test_get_dedup_domain( 'donation', [
			'payment_ref' => 'PAY-002',
			'ref'         => 'INV-002',
		] );

		$this->assertSame( [ [ 'payment_ref', '=', 'PAY-002' ] ], $result );
	}

	public function test_dedup_domain_for_child_without_ref(): void {
		$result = $this->module->test_get_dedup_domain( 'donation', [ 'partner_id' => 5 ] );

		$this->assertSame( [], $result );
	}

	public function test_dedup_domain_for_unknown_entity_is_empty(): void {
		$result = $this->module->test_get_dedup_domain( 'unknown', [ 'name' => 'Test' ] );

		$this->assertSame( [], $result );
	}
}
