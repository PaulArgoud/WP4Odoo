<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\Booking_Module_Base;
use WP4Odoo\Sync_Result;

/**
 * Concrete stub to test the abstract Booking_Module_Base.
 *
 * Implements all abstract methods with test-friendly defaults and
 * exposes protected methods via public proxies.
 */
class BookingModuleBaseTestModule extends Booking_Module_Base {

	/** @var array<string, mixed> */
	public array $test_service_data = [];

	/** @var array<string, mixed> */
	public array $test_booking_fields = [];

	/** @var int */
	public int $test_service_id = 0;

	/** @var array<string, mixed> */
	public array $test_parsed_service = [];

	/** @var int */
	public int $test_saved_service_id = 0;

	/** @var bool */
	public bool $test_delete_result = true;

	public function __construct() {
		parent::__construct(
			'booking_test',
			'Booking Test',
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);

		$this->odoo_models = [
			'service'     => 'product.product',
			'appointment' => 'calendar.event',
		];
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [
			'sync_services'     => true,
			'sync_appointments' => true,
			'pull_services'     => true,
		];
	}

	protected function get_booking_entity_type(): string {
		return 'appointment';
	}

	protected function get_fallback_label(): string {
		return 'Appointment';
	}

	protected function handler_load_service( int $service_id ): array {
		return $this->test_service_data;
	}

	protected function handler_extract_booking_fields( int $booking_id ): array {
		return $this->test_booking_fields;
	}

	protected function handler_get_service_id( int $booking_id ): int {
		return $this->test_service_id;
	}

	protected function handler_parse_service_from_odoo( array $odoo_data ): array {
		return $this->test_parsed_service;
	}

	protected function handler_save_service( array $data, int $wp_id ): int {
		return $this->test_saved_service_id;
	}

	protected function handler_delete_service( int $service_id ): bool {
		return $this->test_delete_result;
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

	/**
	 * Expose save_wp_data() for testing.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Data.
	 * @param int    $wp_id       WP ID.
	 * @return int
	 */
	public function test_save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		return $this->save_wp_data( $entity_type, $data, $wp_id );
	}

	/**
	 * Expose delete_wp_data() for testing.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WP ID.
	 * @return bool
	 */
	public function test_delete_wp_data( string $entity_type, int $wp_id ): bool {
		return $this->delete_wp_data( $entity_type, $wp_id );
	}
}

/**
 * Unit tests for the Booking_Module_Base abstract class.
 *
 * Tests sync direction, deduplication domains, load_wp_data dispatch
 * (service, booking, unknown), booking data resolution pipeline,
 * map_to_odoo overrides, pull gating, save/delete delegation, and
 * map_from_odoo routing.
 */
#[CoversClass(\WP4Odoo\Modules\Booking_Module_Base::class)]
class BookingModuleBaseTest extends Module_Test_Case {

	private BookingModuleBaseTestModule $module;

	protected function setUp(): void {
		parent::setUp();

		$this->module = new BookingModuleBaseTestModule();
	}

	// ─── Sync direction ───────────────────────────────────────

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── load_wp_data dispatch ────────────────────────────────

	public function test_load_wp_data_dispatches_to_service(): void {
		$this->module->test_service_data = [ 'name' => 'Massage', 'list_price' => 50.0 ];

		$result = $this->module->test_load_wp_data( 'service', 1 );

		$this->assertSame( 'Massage', $result['name'] );
		$this->assertSame( 50.0, $result['list_price'] );
	}

	public function test_load_wp_data_returns_empty_for_unknown_entity(): void {
		$result = $this->module->test_load_wp_data( 'unknown', 1 );

		$this->assertSame( [], $result );
	}

	// ─── Booking data resolution ──────────────────────────────

	public function test_load_wp_data_booking_returns_empty_when_fields_empty(): void {
		$this->module->test_booking_fields = [];

		$result = $this->module->test_load_wp_data( 'appointment', 1 );

		$this->assertSame( [], $result );
	}

	public function test_load_wp_data_booking_uses_fallback_label(): void {
		$this->module->test_booking_fields = [
			'service_name'   => '',
			'customer_email' => '',
			'customer_name'  => '',
			'start'          => '2026-01-15 10:00:00',
			'stop'           => '2026-01-15 11:00:00',
			'description'    => 'Notes here',
		];

		$result = $this->module->test_load_wp_data( 'appointment', 1 );

		// Fallback label is 'Appointment' (no customer name, so just the label).
		$this->assertSame( 'Appointment', $result['name'] );
	}

	public function test_load_wp_data_booking_includes_start_and_stop(): void {
		$this->module->test_booking_fields = [
			'service_name'   => 'Haircut',
			'customer_email' => '',
			'customer_name'  => '',
			'start'          => '2026-02-01 09:00:00',
			'stop'           => '2026-02-01 10:00:00',
			'description'    => '',
		];

		$result = $this->module->test_load_wp_data( 'appointment', 1 );

		$this->assertSame( '2026-02-01 09:00:00', $result['start'] );
		$this->assertSame( '2026-02-01 10:00:00', $result['stop'] );
	}

	public function test_load_wp_data_booking_partner_ids_empty_without_email(): void {
		$this->module->test_booking_fields = [
			'service_name'   => 'Haircut',
			'customer_email' => '',
			'customer_name'  => 'John',
			'start'          => '2026-02-01 09:00:00',
			'stop'           => '2026-02-01 10:00:00',
			'description'    => '',
		];

		$result = $this->module->test_load_wp_data( 'appointment', 1 );

		$this->assertSame( [], $result['partner_ids'] );
	}

	public function test_load_wp_data_booking_includes_description(): void {
		$this->module->test_booking_fields = [
			'service_name'   => 'Massage',
			'customer_email' => '',
			'customer_name'  => '',
			'start'          => '2026-02-01 14:00:00',
			'stop'           => '2026-02-01 15:00:00',
			'description'    => 'Deep tissue massage',
		];

		$result = $this->module->test_load_wp_data( 'appointment', 1 );

		$this->assertSame( 'Deep tissue massage', $result['description'] );
	}

	// ─── map_to_odoo ──────────────────────────────────────────

	public function test_map_to_odoo_booking_returns_data_as_is(): void {
		$data = [
			'name'        => 'Massage - John',
			'start'       => '2026-01-15 10:00:00',
			'partner_ids' => [ [ 4, 42, 0 ] ],
		];

		$result = $this->module->map_to_odoo( 'appointment', $data );

		$this->assertSame( $data, $result );
	}

	public function test_map_to_odoo_service_adds_service_type(): void {
		$result = $this->module->map_to_odoo( 'service', [ 'name' => 'Haircut' ] );

		$this->assertSame( 'service', $result['type'] );
	}

	// ─── map_from_odoo ────────────────────────────────────────

	public function test_map_from_odoo_service_delegates_to_handler(): void {
		$this->module->test_parsed_service = [ 'name' => 'From Odoo', 'duration' => 60 ];

		$result = $this->module->map_from_odoo( 'service', [ 'name' => 'Odoo Service' ] );

		$this->assertSame( 'From Odoo', $result['name'] );
		$this->assertSame( 60, $result['duration'] );
	}

	// ─── save_wp_data ─────────────────────────────────────────

	public function test_save_wp_data_service_delegates_to_handler(): void {
		$this->module->test_saved_service_id = 42;

		$result = $this->module->test_save_wp_data( 'service', [ 'name' => 'New Service' ] );

		$this->assertSame( 42, $result );
	}

	public function test_save_wp_data_returns_zero_for_non_service(): void {
		$result = $this->module->test_save_wp_data( 'appointment', [ 'name' => 'Test' ] );

		$this->assertSame( 0, $result );
	}

	// ─── delete_wp_data ───────────────────────────────────────

	public function test_delete_wp_data_service_delegates_to_handler(): void {
		$this->module->test_delete_result = true;

		$result = $this->module->test_delete_wp_data( 'service', 5 );

		$this->assertTrue( $result );
	}

	public function test_delete_wp_data_returns_false_for_non_service(): void {
		$result = $this->module->test_delete_wp_data( 'appointment', 5 );

		$this->assertFalse( $result );
	}

	// ─── pull_from_odoo ───────────────────────────────────────

	public function test_pull_from_odoo_booking_returns_success_silently(): void {
		$result = $this->module->pull_from_odoo( 'appointment', 'create', 100 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_from_odoo_service_returns_success_when_pull_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_booking_test_settings'] = [ 'pull_services' => false ];

		$result = $this->module->pull_from_odoo( 'service', 'create', 100 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Deduplication domains ────────────────────────────────

	public function test_dedup_domain_for_service_with_name(): void {
		$result = $this->module->test_get_dedup_domain( 'service', [ 'name' => 'Massage' ] );

		$this->assertSame( [ [ 'name', '=', 'Massage' ] ], $result );
	}

	public function test_dedup_domain_for_service_without_name(): void {
		$result = $this->module->test_get_dedup_domain( 'service', [ 'list_price' => 50.0 ] );

		$this->assertSame( [], $result );
	}

	public function test_dedup_domain_for_booking_with_name(): void {
		$result = $this->module->test_get_dedup_domain( 'appointment', [ 'name' => 'Massage - John' ] );

		$this->assertSame( [ [ 'name', '=', 'Massage - John' ] ], $result );
	}

	public function test_dedup_domain_for_booking_without_name(): void {
		$result = $this->module->test_get_dedup_domain( 'appointment', [ 'start' => '2026-01-15' ] );

		$this->assertSame( [], $result );
	}

	public function test_dedup_domain_for_unknown_entity_is_empty(): void {
		$result = $this->module->test_get_dedup_domain( 'unknown', [ 'name' => 'Test' ] );

		$this->assertSame( [], $result );
	}
}
