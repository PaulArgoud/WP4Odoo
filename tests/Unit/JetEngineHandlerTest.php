<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\JetEngine_Handler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JetEngine_Handler.
 *
 */
#[CoversClass(\WP4Odoo\Modules\JetEngine_Handler::class)]
class JetEngineHandlerTest extends TestCase {

	private JetEngine_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wp_object_terms'] = [];

		$this->handler = new JetEngine_Handler();
	}

	// ─── load_cpt_data ────────────────────────────────────

	public function test_load_cpt_data_reads_post_title(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'           => 10,
			'post_title'   => 'My Property',
			'post_content' => 'Description here',
			'post_excerpt' => 'Short desc',
			'post_status'  => 'publish',
			'post_name'    => 'my-property',
			'post_date'    => '2025-01-15 10:00:00',
			'post_author'  => '1',
			'post_type'    => 'property',
		];

		$fields = [
			[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'text' ],
		];

		$data = $this->handler->load_cpt_data( 10, $fields );

		$this->assertSame( 'My Property', $data['name'] );
		$this->assertSame( 10, $data['_wp_entity_id'] );
	}

	public function test_load_cpt_data_reads_meta_prefix(): void {
		$GLOBALS['_wp_posts'][11] = (object) [
			'ID'           => 11,
			'post_title'   => 'Prop',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'property',
		];
		$GLOBALS['_wp_post_meta'][11] = [ '_price' => '250000' ];

		$fields = [
			[ 'wp_field' => 'meta:_price', 'odoo_field' => 'x_price', 'type' => 'number' ],
		];

		$data = $this->handler->load_cpt_data( 11, $fields );

		$this->assertSame( 250000.0, $data['x_price'] );
	}

	public function test_load_cpt_data_reads_jet_prefix(): void {
		$GLOBALS['_wp_posts'][12] = (object) [
			'ID'           => 12,
			'post_title'   => 'Vehicle',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'vehicle',
		];
		$GLOBALS['_wp_post_meta'][12] = [ 'license_plate' => 'AB-123-CD' ];

		$fields = [
			[ 'wp_field' => 'jet:license_plate', 'odoo_field' => 'license_plate', 'type' => 'text' ],
		];

		$data = $this->handler->load_cpt_data( 12, $fields );

		$this->assertSame( 'AB-123-CD', $data['license_plate'] );
	}

	public function test_load_cpt_data_reads_taxonomy(): void {
		$GLOBALS['_wp_posts'][13] = (object) [
			'ID'           => 13,
			'post_title'   => 'Tagged',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'property',
		];
		$GLOBALS['_wp_object_terms'][13] = [
			'property_type' => [
				(object) [ 'name' => 'House' ],
				(object) [ 'name' => 'Villa' ],
			],
		];

		$fields = [
			[ 'wp_field' => 'tax:property_type', 'odoo_field' => 'x_type', 'type' => 'text' ],
		];

		$data = $this->handler->load_cpt_data( 13, $fields );

		$this->assertSame( 'House, Villa', $data['x_type'] );
	}

	public function test_load_cpt_data_returns_empty_for_nonexistent(): void {
		$data = $this->handler->load_cpt_data( 999, [
			[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'text' ],
		] );

		$this->assertEmpty( $data );
	}

	public function test_load_cpt_data_skips_empty_fields(): void {
		$GLOBALS['_wp_posts'][14] = (object) [
			'ID'           => 14,
			'post_title'   => 'Test',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'test',
		];

		$fields = [
			[ 'wp_field' => '', 'odoo_field' => 'name', 'type' => 'text' ],
			[ 'wp_field' => 'post_title', 'odoo_field' => '', 'type' => 'text' ],
			[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'text' ],
		];

		$data = $this->handler->load_cpt_data( 14, $fields );

		$this->assertCount( 2, $data ); // name + _wp_entity_id
		$this->assertSame( 'Test', $data['name'] );
	}

	public function test_load_cpt_data_fallback_meta_key(): void {
		$GLOBALS['_wp_posts'][15] = (object) [
			'ID'           => 15,
			'post_title'   => 'Fallback',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'test',
		];
		$GLOBALS['_wp_post_meta'][15] = [ 'custom_key' => 'custom_value' ];

		$fields = [
			[ 'wp_field' => 'custom_key', 'odoo_field' => 'x_custom', 'type' => 'text' ],
		];

		$data = $this->handler->load_cpt_data( 15, $fields );

		$this->assertSame( 'custom_value', $data['x_custom'] );
	}

	// ─── convert_to_odoo ──────────────────────────────────

	public function test_convert_text(): void {
		$this->assertSame( 'hello', $this->handler->convert_to_odoo( 'hello', 'text' ) );
	}

	public function test_convert_text_null_to_empty(): void {
		$this->assertSame( '', $this->handler->convert_to_odoo( null, 'text' ) );
	}

	public function test_convert_number(): void {
		$this->assertSame( 42.5, $this->handler->convert_to_odoo( '42.5', 'number' ) );
	}

	public function test_convert_integer(): void {
		$this->assertSame( 42, $this->handler->convert_to_odoo( '42', 'integer' ) );
	}

	public function test_convert_boolean_true(): void {
		$this->assertTrue( $this->handler->convert_to_odoo( '1', 'boolean' ) );
	}

	public function test_convert_boolean_false(): void {
		$this->assertFalse( $this->handler->convert_to_odoo( '', 'boolean' ) );
	}

	public function test_convert_date_already_formatted(): void {
		$this->assertSame( '2025-06-15', $this->handler->convert_to_odoo( '2025-06-15', 'date' ) );
	}

	public function test_convert_date_compact_format(): void {
		$this->assertSame( '2025-06-15', $this->handler->convert_to_odoo( '20250615', 'date' ) );
	}

	public function test_convert_date_empty(): void {
		$this->assertSame( '', $this->handler->convert_to_odoo( '', 'date' ) );
	}

	public function test_convert_html(): void {
		$this->assertSame( '<p>Hi</p>', $this->handler->convert_to_odoo( '<p>Hi</p>', 'html' ) );
	}

	public function test_convert_select(): void {
		$this->assertSame( 'option_a', $this->handler->convert_to_odoo( 'option_a', 'select' ) );
	}

	public function test_convert_unknown_type_returns_raw(): void {
		$this->assertSame( 'raw', $this->handler->convert_to_odoo( 'raw', 'unknown_type' ) );
	}

	// ─── validate_mapping ─────────────────────────────────

	public function test_validate_mapping_valid(): void {
		$result = JetEngine_Handler::validate_mapping( [
			'cpt_slug'    => 'property',
			'entity_type' => 'property',
			'odoo_model'  => 'x_property',
			'dedup_field' => 'name',
			'fields'      => [
				[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'text' ],
				[ 'wp_field' => 'meta:_price', 'odoo_field' => 'x_price', 'type' => 'number' ],
			],
		] );

		$this->assertNotNull( $result );
		$this->assertSame( 'property', $result['cpt_slug'] );
		$this->assertSame( 'property', $result['entity_type'] );
		$this->assertSame( 'x_property', $result['odoo_model'] );
		$this->assertSame( 'name', $result['dedup_field'] );
		$this->assertCount( 2, $result['fields'] );
	}

	public function test_validate_mapping_null_when_missing_slug(): void {
		$result = JetEngine_Handler::validate_mapping( [
			'cpt_slug'    => '',
			'entity_type' => 'test',
			'odoo_model'  => 'x_test',
		] );

		$this->assertNull( $result );
	}

	public function test_validate_mapping_null_when_missing_entity_type(): void {
		$result = JetEngine_Handler::validate_mapping( [
			'cpt_slug'    => 'test',
			'entity_type' => '',
			'odoo_model'  => 'x_test',
		] );

		$this->assertNull( $result );
	}

	public function test_validate_mapping_null_when_missing_model(): void {
		$result = JetEngine_Handler::validate_mapping( [
			'cpt_slug'    => 'test',
			'entity_type' => 'test',
			'odoo_model'  => '',
		] );

		$this->assertNull( $result );
	}

	public function test_validate_mapping_skips_invalid_fields(): void {
		$result = JetEngine_Handler::validate_mapping( [
			'cpt_slug'    => 'test',
			'entity_type' => 'test',
			'odoo_model'  => 'x_test',
			'dedup_field' => '',
			'fields'      => [
				'not_an_array',
				[ 'wp_field' => '', 'odoo_field' => 'name' ],
				[ 'wp_field' => 'post_title', 'odoo_field' => '' ],
				[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'text' ],
			],
		] );

		$this->assertNotNull( $result );
		$this->assertCount( 1, $result['fields'] );
		$this->assertSame( 'post_title', $result['fields'][0]['wp_field'] );
	}

	public function test_validate_mapping_defaults_invalid_type_to_text(): void {
		$result = JetEngine_Handler::validate_mapping( [
			'cpt_slug'    => 'test',
			'entity_type' => 'test',
			'odoo_model'  => 'x_test',
			'dedup_field' => '',
			'fields'      => [
				[ 'wp_field' => 'post_title', 'odoo_field' => 'name', 'type' => 'invalid_type' ],
			],
		] );

		$this->assertSame( 'text', $result['fields'][0]['type'] );
	}

	// ─── get_valid_types ──────────────────────────────────

	public function test_get_valid_types_returns_expected_types(): void {
		$types = JetEngine_Handler::get_valid_types();

		$this->assertContains( 'text', $types );
		$this->assertContains( 'number', $types );
		$this->assertContains( 'integer', $types );
		$this->assertContains( 'boolean', $types );
		$this->assertContains( 'date', $types );
		$this->assertContains( 'datetime', $types );
		$this->assertContains( 'html', $types );
		$this->assertContains( 'select', $types );
		$this->assertCount( 8, $types );
	}
}
