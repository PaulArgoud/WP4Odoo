<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\API\Odoo_Client;
use WP4Odoo\Module_Base;
use WP4Odoo\Modules\Dokan_Module;
use WP4Odoo\Modules\Sprout_Invoices_Module;
use WP4Odoo\Queue_Manager;
use WP4Odoo\Schema_Cache;

/**
 * Module whose model is chosen at runtime (like the Helpdesk and Events bases).
 *
 * Pushes through the real orchestrator with a MockTransport.
 */
class SchemaReconciliationTestModule extends Module_Base {

	public MockTransport $transport;

	/** @var array<string, mixed> */
	public array $wp_data = [];

	public string $runtime_model = 'hr.employee';

	public function __construct() {
		$transport = new MockTransport();
		$transport->return_value = 55;
		$this->transport         = $transport;

		$provider = function () use ( $transport ): Odoo_Client {
			$client = new Odoo_Client();
			$ref    = new \ReflectionClass( $client );
			$ref->getProperty( 'transport' )->setValue( $client, $transport );
			$ref->getProperty( 'connected' )->setValue( $client, true );
			return $client;
		};

		parent::__construct( 'schema_test', 'SchemaTest', $provider, wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$this->odoo_models         = [ 'employee' => 'hr.employee' ];
		$this->odoo_field_renames  = [ 'employee' => [ 'gender' => 'sex' ] ];
		$this->default_mappings    = [ 'employee' => [ 'name' => 'name', 'gender' => 'gender', 'phone' => 'x_phone' ] ];
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [];
	}

	protected function get_odoo_model( string $entity_type ): string {
		return $this->runtime_model;
	}

	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return $this->wp_data;
	}

	/** Pre-formatted modules return their data untouched. */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		return $wp_data;
	}

	/** @return array<string, mixed> Values of the last create() call. */
	public function last_created_values(): array {
		$creates = array_values( array_filter( $this->transport->calls, fn( $c ) => 'create' === $c['method'] ) );
		return $creates ? end( $creates )['args'][0] : [];
	}
}

/**
 * Tests that the sync orchestrator reconciles final values with the Odoo schema.
 */
#[CoversClass( Module_Base::class )]
class SchemaReconciliationTest extends TestCase {

	private SchemaReconciliationTestModule $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$this->module = new SchemaReconciliationTestModule();
		$GLOBALS['_wp_transients'] = [];
		Schema_Cache::flush();
		Queue_Manager::reset();
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_transients'] = [];
		Schema_Cache::flush();
		Queue_Manager::reset();
	}

	/**
	 * @param array<int, string> $fields Field names present in the fake Odoo schema.
	 */
	private function seed_schema( string $model, array $fields ): void {
		$schema = [];
		foreach ( $fields as $f ) {
			$schema[ $f ] = [ 'type' => 'char' ];
		}
		$GLOBALS['_wp_transients'][ 'wp4odoo_schema_' . md5( $model ) ] = $schema;
		Schema_Cache::flush();
	}

	private function push( array $payload ): void {
		$result = $this->module->push_to_odoo( 'employee', 'create', 5, 0, $payload );
		$this->assertTrue( $result->succeeded(), $result->get_message() );
	}

	// ─── Orchestrator: final values are reconciled ───────────

	public function test_renamed_field_is_sent_under_its_new_name(): void {
		$this->seed_schema( 'hr.employee', [ 'name', 'sex' ] );

		$this->push( [ 'name' => 'Ada', 'gender' => 'female' ] );

		$sent = $this->module->last_created_values();
		$this->assertSame( 'female', $sent['sex'] );
		$this->assertArrayNotHasKey( 'gender', $sent );
		$this->assertSame( 'Ada', $sent['name'] );
	}

	public function test_known_field_is_left_untouched(): void {
		$this->seed_schema( 'hr.employee', [ 'name', 'gender', 'sex' ] );

		$this->push( [ 'name' => 'Ada', 'gender' => 'female' ] );

		$sent = $this->module->last_created_values();
		$this->assertSame( 'female', $sent['gender'] );
		$this->assertArrayNotHasKey( 'sex', $sent );
	}

	public function test_unknown_fields_are_dropped_even_from_pre_formatted_data(): void {
		$this->seed_schema( 'hr.employee', [ 'name' ] );

		$this->push( [ 'name' => 'Ada', 'gender' => 'female', 'x_missing' => 'v' ] );

		$sent = $this->module->last_created_values();
		$this->assertSame( 'Ada', $sent['name'] );
		$this->assertArrayNotHasKey( 'gender', $sent );
		$this->assertArrayNotHasKey( 'x_missing', $sent );
		$this->assertArrayNotHasKey( '_wp_entity_id', $sent );
	}

	public function test_nothing_changes_when_schema_is_unavailable(): void {
		$this->push( [ 'name' => 'Ada', 'gender' => 'female' ] );

		$sent = $this->module->last_created_values();
		$this->assertSame( 'female', $sent['gender'] );
	}

	public function test_schema_of_the_runtime_model_is_used_for_dual_model_modules(): void {
		// The declared model has no `sex`, but the runtime model does.
		$this->seed_schema( 'hr.employee', [ 'name', 'gender' ] );
		$this->seed_schema( 'hr.worker', [ 'name', 'sex' ] );
		$this->module->runtime_model = 'hr.worker';

		$this->push( [ 'name' => 'Ada', 'gender' => 'female' ] );

		$this->assertSame( 'female', $this->module->last_created_values()['sex'] );
	}

	// ─── Declared renames in real modules ────────────────────

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function reconcile( object $module, string $entity, array $values ): array {
		return ( new \ReflectionMethod( $module, 'reconcile_with_schema' ) )->invoke( $module, $entity, $values );
	}

	public function test_sprout_payment_ref_becomes_memo(): void {
		$this->seed_schema( 'account.payment', [ 'partner_id', 'amount', 'date', 'memo' ] );
		$module = new Sprout_Invoices_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$mapped = $this->reconcile( $module, 'payment', [ 'partner_id' => 5, 'amount' => 10.0, 'ref' => 'INV-1' ] );

		$this->assertSame( 'INV-1', $mapped['memo'] );
		$this->assertArrayNotHasKey( 'ref', $mapped );
	}

	public function test_marketplace_payout_dedup_uses_memo_after_rename(): void {
		$module = new Dokan_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$method = new \ReflectionMethod( $module, 'get_dedup_domain' );

		$this->assertSame( [ [ 'memo', '=', 'WD-9' ] ], $method->invoke( $module, 'payout', [ 'memo' => 'WD-9' ] ) );
		$this->assertSame( [ [ 'ref', '=', 'WD-9' ] ], $method->invoke( $module, 'payout', [ 'ref' => 'WD-9' ] ) );
	}

	public function test_sprout_payment_dedup_uses_memo_after_rename(): void {
		$module = new Sprout_Invoices_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$method = new \ReflectionMethod( $module, 'get_dedup_domain' );

		$this->assertSame( [ [ 'memo', '=', 'P-1' ] ], $method->invoke( $module, 'payment', [ 'memo' => 'P-1' ] ) );
		$this->assertSame( [ [ 'ref', '=', 'I-1' ] ], $method->invoke( $module, 'invoice', [ 'ref' => 'I-1' ] ) );
	}

	/**
	 * @return array<string, array{0: class-string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public static function renameProvider(): array {
		return [
			'WP ERP employee gender'     => [ \WP4Odoo\Modules\WPERP_Module::class, 'employee', 'hr.employee', 'gender', 'sex' ],
			'WC Returns picking lines'   => [ \WP4Odoo\Modules\WC_Returns_Module::class, 'return_picking', 'stock.picking', 'move_ids_without_package', 'move_ids' ],
			'Food ordering order note'   => [ \WP4Odoo\Modules\Food_Ordering_Module::class, 'order', 'pos.order', 'note', 'internal_note' ],
			'Dokan payout reference'     => [ \WP4Odoo\Modules\Dokan_Module::class, 'payout', 'account.payment', 'ref', 'memo' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'renameProvider' )]
	public function test_module_declares_its_renames( string $class, string $entity, string $model, string $old, string $new ): void {
		$this->seed_schema( $model, [ 'id_placeholder', $new ] );
		$module = new $class( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$mapped = $this->reconcile( $module, $entity, [ $old => 'v' ] );

		$this->assertSame( [ $new => 'v' ], $mapped );
	}
}
