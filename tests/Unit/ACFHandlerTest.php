<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Logger;
use WP4Odoo\Modules\ACF_Handler;

#[CoversClass(\WP4Odoo\Modules\ACF_Handler::class)]
class ACFHandlerTest extends TestCase {

	private ACF_Handler $handler;

	protected function setUp(): void {
		$this->handler = new ACF_Handler( new Logger( 'test' ) );

		// Reset global stores.
		$GLOBALS['_acf_fields']   = [];
		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Build a mapping rule.
	 *
	 * @param string $acf_field ACF field name.
	 * @param string $odoo_field Odoo field name.
	 * @param string $type Conversion type.
	 * @param string $context ACF context ('post' or 'user').
	 * @return array<string, string>
	 */
	private function rule( string $acf_field, string $odoo_field, string $type = 'text', string $context = 'post' ): array {
		return [
			'acf_field'     => $acf_field,
			'odoo_field'    => $odoo_field,
			'type'          => $type,
			'target_module' => 'test',
			'entity_type'   => 'entity',
			'context'       => $context,
		];
	}

	// ═══════════════════════════════════════════════════════
	// enrich_push
	// ═══════════════════════════════════════════════════════

	public function test_enrich_push_injects_text_value(): void {
		$GLOBALS['_acf_fields'][42]['company_size'] = 'Large';

		$result = $this->handler->enrich_push(
			[ 'name' => 'Test' ],
			[ '_wp_entity_id' => 42 ],
			[ $this->rule( 'company_size', 'x_company_size' ) ]
		);

		$this->assertSame( 'Large', $result['x_company_size'] );
		$this->assertSame( 'Test', $result['name'] );
	}

	public function test_enrich_push_skips_null_values(): void {
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

	public function test_enrich_push_uses_user_context(): void {
		$GLOBALS['_acf_fields']['user_5']['bio'] = 'Hello world';

		$result = $this->handler->enrich_push(
			[],
			[ '_wp_entity_id' => 5 ],
			[ $this->rule( 'bio', 'x_bio', 'text', 'user' ) ]
		);

		$this->assertSame( 'Hello world', $result['x_bio'] );
	}

	public function test_enrich_push_multiple_rules(): void {
		$GLOBALS['_acf_fields'][10]['size']   = '50';
		$GLOBALS['_acf_fields'][10]['active'] = true;

		$result = $this->handler->enrich_push(
			[],
			[ '_wp_entity_id' => 10 ],
			[
				$this->rule( 'size', 'x_size', 'integer' ),
				$this->rule( 'active', 'x_active', 'boolean' ),
			]
		);

		$this->assertSame( 50, $result['x_size'] );
		$this->assertTrue( $result['x_active'] );
	}

	// ═══════════════════════════════════════════════════════
	// enrich_pull
	// ═══════════════════════════════════════════════════════

	public function test_enrich_pull_extracts_odoo_values(): void {
		$result = $this->handler->enrich_pull(
			[ 'name' => 'Test' ],
			[ 'x_company_size' => 'Large' ],
			[ $this->rule( 'company_size', 'x_company_size' ) ]
		);

		$this->assertSame( 'Large', $result['_acf_company_size'] );
		$this->assertSame( 'Test', $result['name'] );
	}

	public function test_enrich_pull_skips_missing_odoo_fields(): void {
		$result = $this->handler->enrich_pull(
			[ 'name' => 'Test' ],
			[],
			[ $this->rule( 'field', 'x_field' ) ]
		);

		$this->assertArrayNotHasKey( '_acf_field', $result );
	}

	public function test_enrich_pull_converts_integer(): void {
		$result = $this->handler->enrich_pull(
			[],
			[ 'x_size' => 42 ],
			[ $this->rule( 'size', 'x_size', 'integer' ) ]
		);

		$this->assertSame( 42, $result['_acf_size'] );
	}

	public function test_enrich_pull_converts_boolean(): void {
		$result = $this->handler->enrich_pull(
			[],
			[ 'x_active' => true ],
			[ $this->rule( 'active', 'x_active', 'boolean' ) ]
		);

		$this->assertTrue( $result['_acf_active'] );
	}

	public function test_enrich_pull_converts_number(): void {
		$result = $this->handler->enrich_pull(
			[],
			[ 'x_price' => 19.99 ],
			[ $this->rule( 'price', 'x_price', 'number' ) ]
		);

		$this->assertSame( 19.99, $result['_acf_price'] );
	}

	public function test_enrich_pull_handles_false_odoo_value(): void {
		$result = $this->handler->enrich_pull(
			[],
			[ 'x_field' => false ],
			[ $this->rule( 'field', 'x_field', 'boolean' ) ]
		);

		$this->assertFalse( $result['_acf_field'] );
	}

	// ═══════════════════════════════════════════════════════
	// write_acf_fields
	// ═══════════════════════════════════════════════════════

	public function test_write_acf_fields_calls_update_field(): void {
		$this->handler->write_acf_fields(
			42,
			[ '_acf_company_size' => 'Large' ],
			[ $this->rule( 'company_size', 'x_company_size' ) ]
		);

		$this->assertSame( 'Large', $GLOBALS['_acf_fields'][42]['company_size'] );
	}

	public function test_write_acf_fields_skips_missing_acf_keys(): void {
		$this->handler->write_acf_fields(
			42,
			[ 'name' => 'No ACF data' ],
			[ $this->rule( 'company_size', 'x_company_size' ) ]
		);

		$this->assertArrayNotHasKey( 42, $GLOBALS['_acf_fields'] );
	}

	public function test_write_acf_fields_uses_user_context(): void {
		$this->handler->write_acf_fields(
			5,
			[ '_acf_bio' => 'Updated bio' ],
			[ $this->rule( 'bio', 'x_bio', 'text', 'user' ) ]
		);

		$this->assertSame( 'Updated bio', $GLOBALS['_acf_fields']['user_5']['bio'] );
	}

	public function test_write_acf_fields_writes_multiple(): void {
		$this->handler->write_acf_fields(
			10,
			[
				'_acf_size'   => 50,
				'_acf_active' => true,
			],
			[
				$this->rule( 'size', 'x_size', 'integer' ),
				$this->rule( 'active', 'x_active', 'boolean' ),
			]
		);

		$this->assertSame( 50, $GLOBALS['_acf_fields'][10]['size'] );
		$this->assertTrue( $GLOBALS['_acf_fields'][10]['active'] );
	}

	// ═══════════════════════════════════════════════════════
	// convert_to_odoo
	// ═══════════════════════════════════════════════════════

	public function test_convert_to_odoo_text(): void {
		$this->assertSame( 'hello', $this->handler->convert_to_odoo( 'hello', 'text' ) );
	}

	public function test_convert_to_odoo_html(): void {
		$this->assertSame( '<p>Hi</p>', $this->handler->convert_to_odoo( '<p>Hi</p>', 'html' ) );
	}

	public function test_convert_to_odoo_select(): void {
		$this->assertSame( 'option_a', $this->handler->convert_to_odoo( 'option_a', 'select' ) );
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

	public function test_convert_to_odoo_date_ymd(): void {
		$this->assertSame( '2026-02-15', $this->handler->convert_to_odoo( '20260215', 'date' ) );
	}

	public function test_convert_to_odoo_date_already_formatted(): void {
		$this->assertSame( '2026-02-15', $this->handler->convert_to_odoo( '2026-02-15', 'date' ) );
	}

	public function test_convert_to_odoo_date_empty(): void {
		$this->assertSame( '', $this->handler->convert_to_odoo( '', 'date' ) );
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

	public function test_convert_from_odoo_boolean_true(): void {
		$this->assertTrue( $this->handler->convert_from_odoo( true, 'boolean' ) );
	}

	public function test_convert_from_odoo_boolean_false(): void {
		$this->assertFalse( $this->handler->convert_from_odoo( false, 'boolean' ) );
	}

	public function test_convert_from_odoo_date(): void {
		$this->assertSame( '20260215', $this->handler->convert_from_odoo( '2026-02-15', 'date' ) );
	}

	public function test_convert_from_odoo_date_empty(): void {
		$this->assertSame( '', $this->handler->convert_from_odoo( '', 'date' ) );
	}

	public function test_convert_from_odoo_date_false_string(): void {
		$this->assertSame( '', $this->handler->convert_from_odoo( 'false', 'date' ) );
	}

	public function test_convert_from_odoo_binary_passthrough(): void {
		$data = base64_encode( 'image_data' );
		$this->assertSame( $data, $this->handler->convert_from_odoo( $data, 'binary' ) );
	}

	public function test_convert_from_odoo_unknown_type_passthrough(): void {
		$this->assertSame( 'raw', $this->handler->convert_from_odoo( 'raw', 'unknown' ) );
	}

	// ═══════════════════════════════════════════════════════
	// ACF date helpers
	// ═══════════════════════════════════════════════════════

	public function test_acf_date_to_odoo_ymd_format(): void {
		$this->assertSame( '2026-02-15', $this->handler->acf_date_to_odoo( '20260215' ) );
	}

	public function test_acf_date_to_odoo_already_formatted(): void {
		$this->assertSame( '2026-02-15', $this->handler->acf_date_to_odoo( '2026-02-15' ) );
	}

	public function test_acf_date_to_odoo_empty(): void {
		$this->assertSame( '', $this->handler->acf_date_to_odoo( '' ) );
	}

	public function test_acf_date_to_odoo_invalid(): void {
		$this->assertSame( '', $this->handler->acf_date_to_odoo( 'not-a-date' ) );
	}

	public function test_odoo_date_to_acf_standard(): void {
		$this->assertSame( '20260215', $this->handler->odoo_date_to_acf( '2026-02-15' ) );
	}

	public function test_odoo_date_to_acf_empty(): void {
		$this->assertSame( '', $this->handler->odoo_date_to_acf( '' ) );
	}

	public function test_odoo_date_to_acf_false_string(): void {
		$this->assertSame( '', $this->handler->odoo_date_to_acf( 'false' ) );
	}

	// ═══════════════════════════════════════════════════════
	// ACF context resolution
	// ═══════════════════════════════════════════════════════

	public function test_resolve_acf_post_id_for_post(): void {
		$this->assertSame( 42, $this->handler->resolve_acf_post_id( 42, 'post' ) );
	}

	public function test_resolve_acf_post_id_for_user(): void {
		$this->assertSame( 'user_5', $this->handler->resolve_acf_post_id( 5, 'user' ) );
	}

	public function test_resolve_context_for_crm_contact_is_user(): void {
		$this->assertSame( 'user', ACF_Handler::resolve_context_for_module( 'crm', 'contact' ) );
	}

	public function test_resolve_context_for_woocommerce_product_is_post(): void {
		$this->assertSame( 'post', ACF_Handler::resolve_context_for_module( 'woocommerce', 'product' ) );
	}

	public function test_resolve_context_for_sales_order_is_post(): void {
		$this->assertSame( 'post', ACF_Handler::resolve_context_for_module( 'sales', 'order' ) );
	}

	// ═══════════════════════════════════════════════════════
	// image_to_base64
	// ═══════════════════════════════════════════════════════

	public function test_image_to_base64_with_invalid_value(): void {
		$this->assertNull( $this->handler->image_to_base64( 'not_numeric' ) );
	}

	public function test_image_to_base64_with_zero(): void {
		$this->assertNull( $this->handler->image_to_base64( 0 ) );
	}

	public function test_image_to_base64_with_array_id(): void {
		// Stub returns false for non-existent attachments.
		$result = $this->handler->image_to_base64( [ 'ID' => 999 ] );
		$this->assertNull( $result );
	}

	public function test_image_to_base64_with_negative(): void {
		$this->assertNull( $this->handler->image_to_base64( -1 ) );
	}

	// ═══════════════════════════════════════════════════════
	// validate_rule
	// ═══════════════════════════════════════════════════════

	public function test_validate_rule_valid(): void {
		$result = ACF_Handler::validate_rule( [
			'acf_field'     => 'company_size',
			'odoo_field'    => 'x_company_size',
			'target_module' => 'crm',
			'entity_type'   => 'contact',
			'type'          => 'integer',
		] );

		$this->assertNotNull( $result );
		$this->assertSame( 'company_size', $result['acf_field'] );
		$this->assertSame( 'x_company_size', $result['odoo_field'] );
		$this->assertSame( 'integer', $result['type'] );
		$this->assertSame( 'user', $result['context'] );
	}

	public function test_validate_rule_missing_acf_field(): void {
		$this->assertNull( ACF_Handler::validate_rule( [
			'acf_field'     => '',
			'odoo_field'    => 'x_test',
			'target_module' => 'crm',
			'entity_type'   => 'contact',
			'type'          => 'text',
		] ) );
	}

	public function test_validate_rule_missing_odoo_field(): void {
		$this->assertNull( ACF_Handler::validate_rule( [
			'acf_field'     => 'test',
			'odoo_field'    => '',
			'target_module' => 'crm',
			'entity_type'   => 'contact',
			'type'          => 'text',
		] ) );
	}

	public function test_validate_rule_missing_module(): void {
		$this->assertNull( ACF_Handler::validate_rule( [
			'acf_field'     => 'test',
			'odoo_field'    => 'x_test',
			'target_module' => '',
			'entity_type'   => 'contact',
			'type'          => 'text',
		] ) );
	}

	public function test_validate_rule_missing_entity(): void {
		$this->assertNull( ACF_Handler::validate_rule( [
			'acf_field'     => 'test',
			'odoo_field'    => 'x_test',
			'target_module' => 'crm',
			'entity_type'   => '',
			'type'          => 'text',
		] ) );
	}

	public function test_validate_rule_invalid_type_defaults_to_text(): void {
		$result = ACF_Handler::validate_rule( [
			'acf_field'     => 'test',
			'odoo_field'    => 'x_test',
			'target_module' => 'crm',
			'entity_type'   => 'contact',
			'type'          => 'invalid_type',
		] );

		$this->assertSame( 'text', $result['type'] );
	}

	public function test_validate_rule_context_for_non_crm(): void {
		$result = ACF_Handler::validate_rule( [
			'acf_field'     => 'weight',
			'odoo_field'    => 'x_weight',
			'target_module' => 'woocommerce',
			'entity_type'   => 'product',
			'type'          => 'number',
		] );

		$this->assertSame( 'post', $result['context'] );
	}

	// ═══════════════════════════════════════════════════════
	// get_valid_types
	// ═══════════════════════════════════════════════════════

	public function test_get_valid_types_returns_all_types(): void {
		$types = ACF_Handler::get_valid_types();
		$this->assertContains( 'text', $types );
		$this->assertContains( 'number', $types );
		$this->assertContains( 'integer', $types );
		$this->assertContains( 'boolean', $types );
		$this->assertContains( 'date', $types );
		$this->assertContains( 'datetime', $types );
		$this->assertContains( 'html', $types );
		$this->assertContains( 'select', $types );
		$this->assertContains( 'binary', $types );
	}

	public function test_get_valid_types_count(): void {
		$this->assertCount( 9, ACF_Handler::get_valid_types() );
	}
}
