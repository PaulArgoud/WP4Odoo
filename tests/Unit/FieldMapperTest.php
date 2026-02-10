<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Field_Mapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for Field_Mapper static utilities.
 *
 * Tests only the pure PHP methods that have no WordPress dependency.
 */
class FieldMapperTest extends TestCase {

	// ─── many2one_to_id ──────────────────────────────────────

	public function test_many2one_to_id_with_array(): void {
		$this->assertSame( 42, Field_Mapper::many2one_to_id( [ 42, 'France' ] ) );
	}

	public function test_many2one_to_id_with_int(): void {
		$this->assertSame( 7, Field_Mapper::many2one_to_id( 7 ) );
	}

	public function test_many2one_to_id_with_zero_int(): void {
		$this->assertNull( Field_Mapper::many2one_to_id( 0 ) );
	}

	public function test_many2one_to_id_with_false(): void {
		$this->assertNull( Field_Mapper::many2one_to_id( false ) );
	}

	public function test_many2one_to_id_with_null(): void {
		$this->assertNull( Field_Mapper::many2one_to_id( null ) );
	}

	public function test_many2one_to_id_with_empty_array(): void {
		$this->assertNull( Field_Mapper::many2one_to_id( [] ) );
	}

	// ─── many2one_to_name ────────────────────────────────────

	public function test_many2one_to_name_with_array(): void {
		$this->assertSame( 'France', Field_Mapper::many2one_to_name( [ 42, 'France' ] ) );
	}

	public function test_many2one_to_name_with_int(): void {
		$this->assertNull( Field_Mapper::many2one_to_name( 7 ) );
	}

	public function test_many2one_to_name_with_false(): void {
		$this->assertNull( Field_Mapper::many2one_to_name( false ) );
	}

	// ─── to_bool ─────────────────────────────────────────────

	public function test_to_bool_with_true(): void {
		$this->assertTrue( Field_Mapper::to_bool( true ) );
	}

	public function test_to_bool_with_false(): void {
		$this->assertFalse( Field_Mapper::to_bool( false ) );
	}

	#[DataProvider( 'truthy_strings_provider' )]
	public function test_to_bool_with_truthy_string( string $value ): void {
		$this->assertTrue( Field_Mapper::to_bool( $value ) );
	}

	public static function truthy_strings_provider(): array {
		return [
			'lowercase true' => [ 'true' ],
			'digit one'      => [ '1' ],
			'yes'            => [ 'yes' ],
			'uppercase TRUE' => [ 'TRUE' ],
			'mixed Yes'      => [ 'Yes' ],
		];
	}

	public function test_to_bool_with_falsy_string(): void {
		$this->assertFalse( Field_Mapper::to_bool( 'false' ) );
		$this->assertFalse( Field_Mapper::to_bool( '0' ) );
		$this->assertFalse( Field_Mapper::to_bool( 'no' ) );
		$this->assertFalse( Field_Mapper::to_bool( '' ) );
	}

	public function test_to_bool_with_int(): void {
		$this->assertTrue( Field_Mapper::to_bool( 1 ) );
		$this->assertFalse( Field_Mapper::to_bool( 0 ) );
	}

	// ─── from_bool ───────────────────────────────────────────

	public function test_from_bool(): void {
		$this->assertTrue( Field_Mapper::from_bool( 1 ) );
		$this->assertTrue( Field_Mapper::from_bool( 'yes' ) );
		$this->assertFalse( Field_Mapper::from_bool( 0 ) );
		$this->assertFalse( Field_Mapper::from_bool( '' ) );
		$this->assertFalse( Field_Mapper::from_bool( null ) );
	}

	// ─── format_price ────────────────────────────────────────

	public function test_format_price_default_decimals(): void {
		$this->assertSame( 19.99, Field_Mapper::format_price( 19.99 ) );
		$this->assertSame( 100.00, Field_Mapper::format_price( 100 ) );
		$this->assertSame( 0.00, Field_Mapper::format_price( 0 ) );
	}

	public function test_format_price_custom_decimals(): void {
		$this->assertSame( 19.995, Field_Mapper::format_price( 19.9951, 3 ) );
		$this->assertSame( 20.0, Field_Mapper::format_price( 20, 1 ) );
	}

	public function test_format_price_string_input(): void {
		$this->assertSame( 42.50, Field_Mapper::format_price( '42.50' ) );
	}

	// ─── relation_to_ids ─────────────────────────────────────

	public function test_relation_to_ids_with_valid_array(): void {
		$this->assertSame( [ 1, 2, 3 ], Field_Mapper::relation_to_ids( [ 1, 2, 3 ] ) );
	}

	public function test_relation_to_ids_casts_to_int(): void {
		$this->assertSame( [ 1, 2, 3 ], Field_Mapper::relation_to_ids( [ '1', '2', '3' ] ) );
	}

	public function test_relation_to_ids_with_false(): void {
		$this->assertSame( [], Field_Mapper::relation_to_ids( false ) );
	}

	public function test_relation_to_ids_with_null(): void {
		$this->assertSame( [], Field_Mapper::relation_to_ids( null ) );
	}

	// ─── ids_to_many2many ────────────────────────────────────

	public function test_ids_to_many2many(): void {
		$result = Field_Mapper::ids_to_many2many( [ 1, 5, 10 ] );
		$this->assertSame( [ [ 6, 0, [ 1, 5, 10 ] ] ], $result );
	}

	public function test_ids_to_many2many_empty(): void {
		$this->assertSame( [ [ 6, 0, [] ] ], Field_Mapper::ids_to_many2many( [] ) );
	}

	// ─── id_to_many2many_add ─────────────────────────────────

	public function test_id_to_many2many_add(): void {
		$this->assertSame( [ [ 4, 42, 0 ] ], Field_Mapper::id_to_many2many_add( 42 ) );
	}

	// ─── values_to_relation_create ───────────────────────────

	public function test_values_to_relation_create(): void {
		$values = [ 'name' => 'Test', 'value' => 100 ];
		$this->assertSame( [ [ 0, 0, $values ] ], Field_Mapper::values_to_relation_create( $values ) );
	}

	// ─── odoo_date_to_wp ────────────────────────────────────

	public function test_odoo_date_to_wp_with_datetime(): void {
		$result = Field_Mapper::odoo_date_to_wp( '2024-01-15 14:30:00', 'Y-m-d H:i:s' );
		$this->assertSame( '2024-01-15 14:30:00', $result );
	}

	public function test_odoo_date_to_wp_with_date_only(): void {
		$result = Field_Mapper::odoo_date_to_wp( '2024-06-01', 'Y-m-d' );
		$this->assertSame( '2024-06-01', $result );
	}

	public function test_odoo_date_to_wp_returns_empty_for_empty_input(): void {
		$this->assertSame( '', Field_Mapper::odoo_date_to_wp( '' ) );
	}

	public function test_odoo_date_to_wp_returns_empty_for_false_string(): void {
		$this->assertSame( '', Field_Mapper::odoo_date_to_wp( 'false' ) );
	}

	public function test_odoo_date_to_wp_returns_empty_for_invalid_date(): void {
		$this->assertSame( '', Field_Mapper::odoo_date_to_wp( 'not-a-date' ) );
	}

	public function test_odoo_date_to_wp_uses_default_format(): void {
		// Default format reads date_format and time_format options (return 'Y-m-d' and 'H:i:s' defaults).
		$result = Field_Mapper::odoo_date_to_wp( '2024-03-20 10:00:00' );
		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( '2024', $result );
	}

	// ─── wp_date_to_odoo ────────────────────────────────────

	public function test_wp_date_to_odoo_converts_date(): void {
		$result = Field_Mapper::wp_date_to_odoo( '2024-01-15 14:30:00' );
		$this->assertSame( '2024-01-15 14:30:00', $result );
	}

	public function test_wp_date_to_odoo_returns_empty_for_empty_input(): void {
		$this->assertSame( '', Field_Mapper::wp_date_to_odoo( '' ) );
	}

	public function test_wp_date_to_odoo_returns_empty_for_invalid_date(): void {
		$this->assertSame( '', Field_Mapper::wp_date_to_odoo( 'garbage' ) );
	}

	public function test_wp_date_to_odoo_formats_as_utc(): void {
		$result = Field_Mapper::wp_date_to_odoo( '2024-12-25 00:00:00' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result );
	}

	// ─── html_to_text ───────────────────────────────────────

	public function test_html_to_text_strips_tags(): void {
		$this->assertSame( 'Hello World', Field_Mapper::html_to_text( '<p>Hello <strong>World</strong></p>' ) );
	}

	public function test_html_to_text_decodes_entities(): void {
		$this->assertSame( 'Price: 5 < 10 & done', Field_Mapper::html_to_text( 'Price: 5 &lt; 10 &amp; done' ) );
	}

	public function test_html_to_text_empty_string(): void {
		$this->assertSame( '', Field_Mapper::html_to_text( '' ) );
	}

	// ─── text_to_html ───────────────────────────────────────

	public function test_text_to_html_wraps_in_paragraph(): void {
		$result = Field_Mapper::text_to_html( 'Hello World' );
		$this->assertStringContainsString( '<p>', $result );
		$this->assertStringContainsString( 'Hello World', $result );
	}

	public function test_text_to_html_escapes_html_entities(): void {
		$result = Field_Mapper::text_to_html( '<script>alert("xss")</script>' );
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}

	public function test_text_to_html_empty_string(): void {
		$this->assertSame( '', Field_Mapper::text_to_html( '' ) );
	}

	// ─── display_price ──────────────────────────────────────

	public function test_display_price_without_woocommerce(): void {
		// wc_price() is not defined, so falls back to number_format_i18n.
		$result = Field_Mapper::display_price( 1234.56 );
		$this->assertSame( '1,234.56', $result );
	}

	public function test_display_price_with_zero(): void {
		$result = Field_Mapper::display_price( 0.00 );
		$this->assertSame( '0.00', $result );
	}

	public function test_display_price_custom_decimals(): void {
		$result = Field_Mapper::display_price( 99.999, 3 );
		$this->assertSame( '99.999', $result );
	}
}
