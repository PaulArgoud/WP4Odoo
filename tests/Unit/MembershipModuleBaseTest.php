<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\Membership_Module_Base;

/**
 * Concrete stub to test the abstract Membership_Module_Base.
 *
 * Implements all abstract methods with test-friendly defaults and
 * exposes protected methods via public proxies.
 */
class MembershipModuleBaseTestModule extends Membership_Module_Base {

	/** @var array<string, mixed> */
	public array $test_level_data = [];

	/** @var array<string, mixed> */
	public array $test_payment_data = [];

	/** @var array<string, mixed> */
	public array $test_membership_data = [];

	/** @var array{int, int} */
	public array $test_payment_user_and_level = [ 0, 0 ];

	/** @var int */
	public int $test_level_id_for_entity = 0;

	/** @var bool */
	public bool $test_is_payment_complete = false;

	/** @var float */
	public float $test_member_price = 0.0;

	public function __construct() {
		parent::__construct(
			'membership_test',
			'Membership Test',
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);

		$this->odoo_models = [
			'level'      => 'product.product',
			'payment'    => 'account.move',
			'membership' => 'membership.membership_line',
		];
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [
			'sync_levels'      => true,
			'sync_payments'    => true,
			'sync_memberships' => true,
		];
	}

	protected function get_level_entity_type(): string {
		return 'level';
	}

	protected function get_payment_entity_type(): string {
		return 'payment';
	}

	protected function get_membership_entity_type(): string {
		return 'membership';
	}

	protected function handler_load_level( int $wp_id ): array {
		return $this->test_level_data;
	}

	protected function handler_load_payment( int $wp_id, int $partner_id, int $level_odoo_id ): array {
		return $this->test_payment_data;
	}

	protected function handler_load_membership( int $wp_id ): array {
		return $this->test_membership_data;
	}

	protected function get_payment_user_and_level( int $wp_id ): array {
		return $this->test_payment_user_and_level;
	}

	protected function get_level_id_for_entity( int $wp_id, string $entity_type ): int {
		return $this->test_level_id_for_entity;
	}

	protected function is_payment_complete( int $wp_id ): bool {
		return $this->test_is_payment_complete;
	}

	protected function resolve_member_price( int $level_id ): float {
		return $this->test_member_price;
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
 * Unit tests for the Membership_Module_Base abstract class.
 *
 * Tests sync direction, exclusive group, deduplication domains,
 * load_wp_data dispatch (level, payment, membership, unknown),
 * and the payment/membership resolution pipelines.
 */
#[CoversClass(\WP4Odoo\Modules\Membership_Module_Base::class)]
class MembershipModuleBaseTest extends Module_Test_Case {

	private MembershipModuleBaseTestModule $module;

	protected function setUp(): void {
		parent::setUp();

		$this->module = new MembershipModuleBaseTestModule();
	}

	// ─── Sync direction ───────────────────────────────────────

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Exclusive group ──────────────────────────────────────

	public function test_exclusive_group_is_memberships(): void {
		$this->assertSame( 'memberships', $this->module->get_exclusive_group() );
	}

	// ─── load_wp_data dispatch ────────────────────────────────

	public function test_load_wp_data_dispatches_to_level(): void {
		$this->module->test_level_data = [ 'name' => 'Gold Plan', 'list_price' => 29.99 ];

		$result = $this->module->test_load_wp_data( 'level', 1 );

		$this->assertSame( 'Gold Plan', $result['name'] );
		$this->assertSame( 29.99, $result['list_price'] );
	}

	public function test_load_wp_data_returns_empty_for_unknown_entity(): void {
		$result = $this->module->test_load_wp_data( 'unknown', 1 );

		$this->assertSame( [], $result );
	}

	// ─── Payment resolution pipeline ──────────────────────────

	public function test_load_wp_data_payment_returns_empty_when_partner_fails(): void {
		// User 5 has no WP user entry, so resolve_partner_from_user returns 0.
		$this->module->test_payment_user_and_level = [ 5, 10 ];

		$result = $this->module->test_load_wp_data( 'payment', 1 );

		$this->assertSame( [], $result );
	}

	// ─── Membership resolution pipeline ───────────────────────

	public function test_load_wp_data_membership_returns_empty_when_handler_empty(): void {
		$this->module->test_membership_data = [];

		$result = $this->module->test_load_wp_data( 'membership', 1 );

		$this->assertSame( [], $result );
	}

	public function test_load_wp_data_membership_returns_empty_when_partner_fails(): void {
		$this->module->test_membership_data = [
			'user_id'  => 99,
			'level_id' => 5,
		];

		$result = $this->module->test_load_wp_data( 'membership', 1 );

		$this->assertSame( [], $result );
	}

	// ─── Deduplication domains ────────────────────────────────

	public function test_dedup_domain_for_level_with_name(): void {
		$result = $this->module->test_get_dedup_domain( 'level', [ 'name' => 'Gold Plan' ] );

		$this->assertSame( [ [ 'name', '=', 'Gold Plan' ] ], $result );
	}

	public function test_dedup_domain_for_level_without_name(): void {
		$result = $this->module->test_get_dedup_domain( 'level', [ 'list_price' => 10.0 ] );

		$this->assertSame( [], $result );
	}

	public function test_dedup_domain_for_payment_with_ref(): void {
		$result = $this->module->test_get_dedup_domain( 'payment', [ 'ref' => 'INV-001' ] );

		$this->assertSame( [ [ 'ref', '=', 'INV-001' ] ], $result );
	}

	public function test_dedup_domain_for_payment_without_ref(): void {
		$result = $this->module->test_get_dedup_domain( 'payment', [ 'partner_id' => 5 ] );

		$this->assertSame( [], $result );
	}

	public function test_dedup_domain_for_membership_is_empty(): void {
		$result = $this->module->test_get_dedup_domain( 'membership', [ 'partner_id' => 5 ] );

		$this->assertSame( [], $result );
	}

	public function test_dedup_domain_for_unknown_entity_is_empty(): void {
		$result = $this->module->test_get_dedup_domain( 'unknown', [ 'name' => 'Test' ] );

		$this->assertSame( [], $result );
	}
}
