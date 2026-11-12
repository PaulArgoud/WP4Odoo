<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Logger;
use WP4Odoo\Modules\JetEngine_Meta_Handler;

/**
 * Unit tests for JetEngine_Meta_Handler.
 *
 */
#[CoversClass(\WP4Odoo\Modules\JetEngine_Meta_Handler::class)]
class JetEngineMetaHandlerTest extends TestCase {

	private JetEngine_Meta_Handler $handler;

	protected function setUp(): void {
		$this->handler = new JetEngine_Meta_Handler( new Logger( 'test' ) );

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Build a mapping rule.
	 *
	 * @param string $jet_field  JetEngine meta field name.
	 * @param string $odoo_field Odoo field name.
	 * @param string $type       Conversion type.
	 * @return array<string, string>
	 */
	private function rule( string $jet_field, string $odoo_field, string $type = 'text' ): array {
		return [
			'jet_field'     => $jet_field,
			'odoo_field'    => $odoo_field,
			'type'          => $type,
			'target_module' => 'test',
			'entity_type'   => 'entity',
		];
	}

	// ═══════════════════════════════════════════════════════
	// enrich_push
	// ═══════════════════════════════════════════════════════

	public function test_enrich_push_injects_text_value(): void {
		$GLOBALS['_wp_post_meta'][42] = [ 'custom_color' => 'Red' ];

		$result = $this->handler->enrich_push(
			[ 'name' => 'Test' ],
			[ '_wp_entity_id' => 42 ],
			[ $this->rule( 'custom_color', 'x_custom_color' ) ]
		);

		$this->assertSame( 'Red', $result['x_custom_color'] );
		$this->assertSame( 'Test', $result['name'] );
	}

	public function test_enrich_push_skips_empty_values(): void {
		$result = $this->handler->enrich_push(
			[ 'name' => 'Test' ],
			[ '_wp_entity_id' => 42 ],
			[ $this->rule( 'nonexistent', 'x_nonexistent' ) ]
		);

		$this->assertArrayNotHasKey( 'x_nonexistent', $result );
	}

	public function test_enrich_push_returns_unchanged_when_no_wp_id(): void {
		$original = [ 'name' => 'Test' ];

		$result = $this->handler->enrich_push(
			$original,
			[],
			[ $this->rule( 'field', 'x_field' ) ]
		);

		$this->assertSame( $original, $result );
	}

	public function test_enrich_push_multiple_rules(): void {
		$GLOBALS['_wp_post_meta'][10] = [
			'weight' => '5.5',
			'active' => '1',
		];

		$result = $this->handler->enrich_push(
			[],
			[ '_wp_entity_id' => 10 ],
			[
				$this->rule( 'weight', 'x_weight', 'number' ),
				$this->rule( 'active', 'x_active', 'boolean' ),
			]
		);

		$this->assertSame( 5.5, $result['x_weight'] );
		$this->assertTrue( $result['x_active'] );
	}

	// ═══════════════════════════════════════════════════════
	// enrich_pull
	// ═══════════════════════════════════════════════════════

	public function test_enrich_pull_extracts_odoo_values(): void {
		$result = $this->handler->enrich_pull(
			[ 'name' => 'Test' ],
			[ 'x_custom_color' => 'Blue' ],
			[ $this->rule( 'custom_color', 'x_custom_color' ) ]
		);

		$this->assertSame( 'Blue', $result['_jet_custom_color'] );
		$this->assertSame( 'Test', $result['name'] );
	}

	public function test_enrich_pull_skips_missing_odoo_fields(): void {
		$result = $this->handler->enrich_pull(
			[ 'name' => 'Test' ],
			[],
			[ $this->rule( 'field', 'x_field' ) ]
		);

		$this->assertArrayNotHasKey( '_jet_field', $result );
	}

	public function test_enrich_pull_converts_integer(): void {
		$result = $this->handler->enrich_pull(
			[],
			[ 'x_qty' => 10 ],
			[ $this->rule( 'qty', 'x_qty', 'integer' ) ]
		);

		$this->assertSame( 10, $result['_jet_qty'] );
	}

	public function test_enrich_pull_converts_boolean(): void {
		$result = $this->handler->enrich_pull(
			[],
			[ 'x_active' => true ],
			[ $this->rule( 'active', 'x_active', 'boolean' ) ]
		);

		$this->assertTrue( $result['_jet_active'] );
	}

	public function test_enrich_pull_converts_number(): void {
		$result = $this->handler->enrich_pull(
			[],
			[ 'x_weight' => 3.14 ],
			[ $this->rule( 'weight', 'x_weight', 'number' ) ]
		);

		$this->assertSame( 3.14, $result['_jet_weight'] );
	}

	// ═══════════════════════════════════════════════════════
	// write_jet_fields
	// ═══════════════════════════════════════════════════════

	public function test_write_jet_fields_calls_update_post_meta(): void {
		$this->handler->write_jet_fields(
			42,
			[ '_jet_custom_color' => 'Green' ],
			[ $this->rule( 'custom_color', 'x_custom_color' ) ]
		);

		$this->assertSame( 'Green', $GLOBALS['_wp_post_meta'][42]['custom_color'] );
	}

	public function test_write_jet_fields_skips_missing_keys(): void {
		$this->handler->write_jet_fields(
			42,
			[ 'name' => 'No jet data' ],
			[ $this->rule( 'custom_color', 'x_custom_color' ) ]
		);

		$this->assertArrayNotHasKey( 42, $GLOBALS['_wp_post_meta'] );
	}

	public function test_write_jet_fields_writes_multiple(): void {
		$this->handler->write_jet_fields(
			10,
			[
				'_jet_weight' => 5.5,
				'_jet_active' => true,
			],
			[
				$this->rule( 'weight', 'x_weight', 'number' ),
				$this->rule( 'active', 'x_active', 'boolean' ),
			]
		);

		$this->assertSame( 5.5, $GLOBALS['_wp_post_meta'][10]['weight'] );
		$this->assertTrue( $GLOBALS['_wp_post_meta'][10]['active'] );
	}

	// ═══════════════════════════════════════════════════════
	// convert_to_odoo
	// ═══════════════════════════════════════════════════════

	public function test_convert_to_odoo_text(): void {
		$this->assertSame( 'hello', $this->handler->convert_to_odoo( 'hello', 'text' ) );
	}

	public function test_convert_to_odoo_number(): void {
		$this->assertSame( 19.99, $this->handler->convert_to_odoo( '19.99', 'number' ) );
	}

	public function test_convert_to_odoo_integer(): void {
		$this->assertSame( 42, $this->handler->convert_to_odoo( '42', 'integer' ) );
	}

	public function test_convert_to_odoo_boolean_true(): void {
		$this->assertTrue( $this->handler->convert_to_odoo( true, 'boolean' ) );
	}

	public function test_convert_to_odoo_boolean_false(): void {
		$this->assertFalse( $this->handler->convert_to_odoo( false, 'boolean' ) );
	}

	public function test_convert_to_odoo_unknown_type_passthrough(): void {
		$this->assertSame( 'raw', $this->handler->convert_to_odoo( 'raw', 'unknown' ) );
	}

	// ═══════════════════════════════════════════════════════
	// convert_from_odoo
	// ═══════════════════════════════════════════════════════

	public function test_convert_from_odoo_text(): void {
		$this->assertSame( 'hello', $this->handler->convert_from_odoo( 'hello', 'text' ) );
	}

	public function test_convert_from_odoo_text_null(): void {
		$this->assertSame( '', $this->handler->convert_from_odoo( null, 'text' ) );
	}

	public function test_convert_from_odoo_number(): void {
		$this->assertSame( 19.99, $this->handler->convert_from_odoo( 19.99, 'number' ) );
	}

	public function test_convert_from_odoo_integer(): void {
		$this->assertSame( 42, $this->handler->convert_from_odoo( 42, 'integer' ) );
	}

	public function test_convert_from_odoo_unknown_type_passthrough(): void {
		$this->assertSame( 'raw', $this->handler->convert_from_odoo( 'raw', 'unknown' ) );
	}

	// ═══════════════════════════════════════════════════════
	// validate_rule
	// ═══════════════════════════════════════════════════════

	public function test_validate_rule_valid(): void {
		$result = JetEngine_Meta_Handler::validate_rule( [
			'jet_field'     => 'custom_weight',
			'odoo_field'    => 'x_custom_weight',
			'target_module' => 'woocommerce',
			'entity_type'   => 'product',
			'type'          => 'number',
		] );

		$this->assertNotNull( $result );
		$this->assertSame( 'custom_weight', $result['jet_field'] );
		$this->assertSame( 'x_custom_weight', $result['odoo_field'] );
		$this->assertSame( 'number', $result['type'] );
	}

	public function test_validate_rule_missing_jet_field(): void {
		$this->assertNull( JetEngine_Meta_Handler::validate_rule( [
			'jet_field'     => '',
			'odoo_field'    => 'x_test',
			'target_module' => 'woocommerce',
			'entity_type'   => 'product',
			'type'          => 'text',
		] ) );
	}

	public function test_validate_rule_missing_odoo_field(): void {
		$this->assertNull( JetEngine_Meta_Handler::validate_rule( [
			'jet_field'     => 'test',
			'odoo_field'    => '',
			'target_module' => 'woocommerce',
			'entity_type'   => 'product',
			'type'          => 'text',
		] ) );
	}

	public function test_validate_rule_missing_module(): void {
		$this->assertNull( JetEngine_Meta_Handler::validate_rule( [
			'jet_field'     => 'test',
			'odoo_field'    => 'x_test',
			'target_module' => '',
			'entity_type'   => 'product',
			'type'          => 'text',
		] ) );
	}

	public function test_validate_rule_missing_entity(): void {
		$this->assertNull( JetEngine_Meta_Handler::validate_rule( [
			'jet_field'     => 'test',
			'odoo_field'    => 'x_test',
			'target_module' => 'woocommerce',
			'entity_type'   => '',
			'type'          => 'text',
		] ) );
	}

	public function test_validate_rule_invalid_type_defaults_to_text(): void {
		$result = JetEngine_Meta_Handler::validate_rule( [
			'jet_field'     => 'test',
			'odoo_field'    => 'x_test',
			'target_module' => 'woocommerce',
			'entity_type'   => 'product',
			'type'          => 'invalid_type',
		] );

		$this->assertSame( 'text', $result['type'] );
	}

	// ═══════════════════════════════════════════════════════
	// get_valid_types
	// ═══════════════════════════════════════════════════════

	public function test_get_valid_types_returns_all_types(): void {
		$types = JetEngine_Meta_Handler::get_valid_types();
		$this->assertContains( 'text', $types );
		$this->assertContains( 'number', $types );
		$this->assertContains( 'integer', $types );
		$this->assertContains( 'boolean', $types );
		$this->assertContains( 'date', $types );
		$this->assertContains( 'datetime', $types );
		$this->assertContains( 'html', $types );
		$this->assertContains( 'select', $types );
	}

	public function test_get_valid_types_count(): void {
		$this->assertCount( 8, JetEngine_Meta_Handler::get_valid_types() );
	}
}
