<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\Helpdesk_Module_Base;
use WP4Odoo\Sync_Result;

/**
 * Concrete stub to test the abstract Helpdesk_Module_Base.
 *
 * Implements all abstract methods with test-friendly defaults and
 * exposes protected methods via public proxies.
 */
class HelpdeskModuleBaseTestModule extends Helpdesk_Module_Base {

	/** @var array<string, mixed> */
	public array $test_ticket_data = [];

	/** @var bool */
	public bool $test_save_status_result = true;

	/** @var array<string, mixed> */
	public array $test_parsed_ticket = [];

	public function __construct() {
		parent::__construct(
			'helpdesk_test',
			'Helpdesk Test',
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);

		$this->odoo_models = [
			'ticket' => 'helpdesk.ticket',
		];
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 0,
			'odoo_project_id' => 0,
		];
	}

	protected function get_closed_status(): string {
		return 'closed';
	}

	protected function handler_load_ticket( int $ticket_id ): array {
		return $this->test_ticket_data;
	}

	protected function handler_save_ticket_status( int $ticket_id, string $wp_status ): bool {
		return $this->test_save_status_result;
	}

	protected function handler_parse_ticket_from_odoo( array $odoo_data, bool $is_helpdesk ): array {
		return $this->test_parsed_ticket;
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

	/**
	 * Expose get_odoo_model() for testing.
	 *
	 * @param string $entity_type Entity type.
	 * @return string
	 */
	public function test_get_odoo_model( string $entity_type ): string {
		return $this->get_odoo_model( $entity_type );
	}
}

/**
 * Unit tests for the Helpdesk_Module_Base abstract class.
 *
 * Tests sync direction, exclusive group, deduplication domains,
 * load_wp_data dispatch, dual-model detection (helpdesk.ticket vs
 * project.task), map_to_odoo ticket injection, pull gating, save/delete
 * delegation, and map_from_odoo routing.
 */
#[CoversClass(\WP4Odoo\Modules\Helpdesk_Module_Base::class)]
class HelpdeskModuleBaseTest extends Module_Test_Case {

	private HelpdeskModuleBaseTestModule $module;

	protected function setUp(): void {
		parent::setUp();

		$this->module = new HelpdeskModuleBaseTestModule();
	}

	// ─── Sync direction ───────────────────────────────────────

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Exclusive group ──────────────────────────────────────

	public function test_exclusive_group_is_helpdesk(): void {
		$this->assertSame( 'helpdesk', $this->module->get_exclusive_group() );
	}

	// ─── load_wp_data dispatch ────────────────────────────────

	public function test_load_wp_data_returns_empty_for_non_ticket(): void {
		$result = $this->module->test_load_wp_data( 'unknown', 1 );

		$this->assertSame( [], $result );
	}

	public function test_load_wp_data_returns_empty_when_handler_empty(): void {
		$this->module->test_ticket_data = [];

		$result = $this->module->test_load_wp_data( 'ticket', 1 );

		$this->assertSame( [], $result );
	}

	public function test_load_wp_data_returns_handler_data_for_ticket(): void {
		$this->module->test_ticket_data = [
			'name'        => 'Bug Report',
			'description' => 'Something broke',
			'_user_id'    => 0,
			'_wp_status'  => 'open',
			'priority'    => '1',
		];

		$result = $this->module->test_load_wp_data( 'ticket', 1 );

		$this->assertSame( 'Bug Report', $result['name'] );
		$this->assertSame( 'Something broke', $result['description'] );
	}

	public function test_load_wp_data_injects_project_id_when_helpdesk_unavailable(): void {
		// has_helpdesk_model() returns false (client not connected),
		// so the code falls back to project.task with project_id.
		$GLOBALS['_wp_options']['wp4odoo_module_helpdesk_test_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 0,
			'odoo_project_id' => 8,
		];

		$this->module->test_ticket_data = [
			'name'       => 'Test Ticket',
			'_user_id'   => 0,
			'_wp_status' => 'open',
		];

		$result = $this->module->test_load_wp_data( 'ticket', 1 );

		$this->assertSame( 8, $result['project_id'] );
	}

	// ─── map_to_odoo ──────────────────────────────────────────

	public function test_map_to_odoo_ticket_strips_internal_keys(): void {
		$wp_data = [
			'name'       => 'Test',
			'_user_id'   => 5,
			'_wp_status' => 'open',
			'partner_id' => 42,
		];

		$result = $this->module->map_to_odoo( 'ticket', $wp_data );

		$this->assertArrayNotHasKey( '_user_id', $result );
		$this->assertArrayNotHasKey( '_wp_status', $result );
		$this->assertSame( 42, $result['partner_id'] );
	}

	public function test_map_to_odoo_ticket_injects_team_id(): void {
		$wp_data = [
			'name'    => 'Test',
			'team_id' => 3,
		];

		$result = $this->module->map_to_odoo( 'ticket', $wp_data );

		$this->assertSame( 3, $result['team_id'] );
	}

	public function test_map_to_odoo_ticket_injects_project_id(): void {
		$wp_data = [
			'name'       => 'Test',
			'project_id' => 7,
		];

		$result = $this->module->map_to_odoo( 'ticket', $wp_data );

		$this->assertSame( 7, $result['project_id'] );
	}

	public function test_map_to_odoo_ticket_injects_user_ids(): void {
		$wp_data = [
			'name'     => 'Test',
			'user_ids' => [ [ 4, 42, 0 ] ],
		];

		$result = $this->module->map_to_odoo( 'ticket', $wp_data );

		$this->assertSame( [ [ 4, 42, 0 ] ], $result['user_ids'] );
	}

	// ─── map_from_odoo ────────────────────────────────────────

	public function test_map_from_odoo_ticket_delegates_to_handler(): void {
		$this->module->test_parsed_ticket = [ '_stage_name' => 'In Progress' ];

		$result = $this->module->map_from_odoo( 'ticket', [ 'stage_id' => [ 1, 'In Progress' ] ] );

		$this->assertSame( 'In Progress', $result['_stage_name'] );
	}

	// ─── save_wp_data ─────────────────────────────────────────

	public function test_save_wp_data_returns_zero_for_non_ticket(): void {
		$result = $this->module->test_save_wp_data( 'unknown', [ 'name' => 'Test' ], 1 );

		$this->assertSame( 0, $result );
	}

	public function test_save_wp_data_returns_zero_when_wp_id_is_zero(): void {
		$result = $this->module->test_save_wp_data( 'ticket', [ '_stage_name' => 'Done' ], 0 );

		$this->assertSame( 0, $result );
	}

	public function test_save_wp_data_returns_wp_id_on_success(): void {
		$this->module->test_save_status_result = true;

		$result = $this->module->test_save_wp_data( 'ticket', [ '_stage_name' => 'Done' ], 42 );

		$this->assertSame( 42, $result );
	}

	public function test_save_wp_data_returns_zero_on_handler_failure(): void {
		$this->module->test_save_status_result = false;

		$result = $this->module->test_save_wp_data( 'ticket', [ '_stage_name' => 'Done' ], 42 );

		$this->assertSame( 0, $result );
	}

	// ─── delete_wp_data ───────────────────────────────────────

	public function test_delete_wp_data_always_returns_false(): void {
		$result = $this->module->test_delete_wp_data( 'ticket', 5 );

		$this->assertFalse( $result );
	}

	// ─── pull_from_odoo ───────────────────────────────────────

	public function test_pull_from_odoo_returns_success_when_pull_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_helpdesk_test_settings'] = [ 'pull_tickets' => false ];

		$result = $this->module->pull_from_odoo( 'ticket', 'update', 100 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_from_odoo_returns_success_for_create_action(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_helpdesk_test_settings'] = [ 'pull_tickets' => true ];

		$result = $this->module->pull_from_odoo( 'ticket', 'create', 100 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_from_odoo_returns_success_for_delete_action(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_helpdesk_test_settings'] = [ 'pull_tickets' => true ];

		$result = $this->module->pull_from_odoo( 'ticket', 'delete', 100 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Dual-model detection ─────────────────────────────────

	public function test_get_odoo_model_falls_back_to_project_task(): void {
		// has_helpdesk_model() returns false when client is not connected.
		$result = $this->module->test_get_odoo_model( 'ticket' );

		$this->assertSame( 'project.task', $result );
	}

	// ─── Deduplication domains ────────────────────────────────

	public function test_dedup_domain_for_ticket_with_name(): void {
		$result = $this->module->test_get_dedup_domain( 'ticket', [ 'name' => 'Bug Report' ] );

		$this->assertSame( [ [ 'name', '=', 'Bug Report' ] ], $result );
	}

	public function test_dedup_domain_for_ticket_without_name(): void {
		$result = $this->module->test_get_dedup_domain( 'ticket', [ 'description' => 'Test' ] );

		$this->assertSame( [], $result );
	}

	public function test_dedup_domain_for_unknown_entity_is_empty(): void {
		$result = $this->module->test_get_dedup_domain( 'unknown', [ 'name' => 'Test' ] );

		$this->assertSame( [], $result );
	}
}
