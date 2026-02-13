<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\API\Odoo_Client;
use WP4Odoo\I18n\Translation_Service;
use WP4Odoo\I18n\WPML_Adapter;
use WP4Odoo\I18n\Polylang_Adapter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Translation_Service.
 *
 * Tests adapter detection, locale mapping, Odoo version detection,
 * and the dual-path translation push.
 *
 * @covers \WP4Odoo\I18n\Translation_Service
 */
class TranslationServiceTest extends TestCase {

	private Translation_Service $service;
	private Odoo_Client $client;
	private MockTransport $transport;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_filters']    = [];

		// Enable logging so Logger doesn't short-circuit.
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'enabled' => true,
			'level'   => 'debug',
		];

		$this->client    = new Odoo_Client();
		$this->transport = new MockTransport();

		// Inject mock transport via reflection.
		$ref = new \ReflectionClass( $this->client );

		$tp = $ref->getProperty( 'transport' );
		$tp->setAccessible( true );
		$tp->setValue( $this->client, $this->transport );

		$cp = $ref->getProperty( 'connected' );
		$cp->setAccessible( true );
		$cp->setValue( $this->client, true );

		$client = $this->client;
		$this->service = new Translation_Service( fn() => $client );
	}

	// ─── Adapter detection ──────────────────────────────────

	public function test_is_available_true_with_wpml(): void {
		// ICL_SITEPRESS_VERSION and SitePress are defined in stubs.
		$this->assertTrue( $this->service->is_available() );
	}

	public function test_get_adapter_returns_wpml_by_default(): void {
		$adapter = $this->service->get_adapter();
		$this->assertInstanceOf( WPML_Adapter::class, $adapter );
	}

	public function test_get_adapter_returns_same_instance(): void {
		$adapter1 = $this->service->get_adapter();
		$adapter2 = $this->service->get_adapter();
		$this->assertSame( $adapter1, $adapter2 );
	}

	// ─── Locale mapping ─────────────────────────────────────

	public function test_wp_to_odoo_locale_known_french(): void {
		$this->assertSame( 'fr_FR', $this->service->wp_to_odoo_locale( 'fr' ) );
	}

	public function test_wp_to_odoo_locale_known_spanish(): void {
		$this->assertSame( 'es_ES', $this->service->wp_to_odoo_locale( 'es' ) );
	}

	public function test_wp_to_odoo_locale_known_german(): void {
		$this->assertSame( 'de_DE', $this->service->wp_to_odoo_locale( 'de' ) );
	}

	public function test_wp_to_odoo_locale_known_english(): void {
		$this->assertSame( 'en_US', $this->service->wp_to_odoo_locale( 'en' ) );
	}

	public function test_wp_to_odoo_locale_known_arabic(): void {
		$this->assertSame( 'ar_001', $this->service->wp_to_odoo_locale( 'ar' ) );
	}

	public function test_wp_to_odoo_locale_unknown_falls_back(): void {
		// Unknown language code → fallback format.
		$this->assertSame( 'xx_XX', $this->service->wp_to_odoo_locale( 'xx' ) );
	}

	public function test_wp_to_odoo_locale_trims_long_codes(): void {
		// 'fr_FR' → takes first 2 chars 'fr' → 'fr_FR'.
		$this->assertSame( 'fr_FR', $this->service->wp_to_odoo_locale( 'fr_FR' ) );
	}

	public function test_wp_to_odoo_locale_filterable(): void {
		$GLOBALS['_wp_filters']['wp4odoo_odoo_locale'] = function ( $locale, $wp_lang ) {
			if ( 'pt' === $wp_lang ) {
				return 'pt_PT'; // Override pt_BR → pt_PT.
			}
			return $locale;
		};

		$this->assertSame( 'pt_PT', $this->service->wp_to_odoo_locale( 'pt' ) );
	}

	// ─── Odoo version detection ─────────────────────────────

	public function test_has_ir_translation_true(): void {
		$this->transport->return_value = 1; // search_count returns 1.

		$this->assertTrue( $this->service->has_ir_translation() );

		// Verify ir.model probe call.
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame( 'ir.model', $this->transport->calls[0]['model'] );
		$this->assertSame( 'search_count', $this->transport->calls[0]['method'] );
	}

	public function test_has_ir_translation_false(): void {
		$this->transport->return_value = 0; // search_count returns 0.

		$this->assertFalse( $this->service->has_ir_translation() );
	}

	public function test_has_ir_translation_cached_in_memory(): void {
		$this->transport->return_value = 1;

		$this->service->has_ir_translation();
		$this->service->has_ir_translation();

		// Should only call the API once (cached in memory).
		$this->assertCount( 1, $this->transport->calls );
	}

	public function test_has_ir_translation_cached_via_transient(): void {
		// Pre-set the transient.
		$GLOBALS['_wp_transients']['wp4odoo_has_ir_translation'] = 1;

		$this->assertTrue( $this->service->has_ir_translation() );
		$this->assertCount( 0, $this->transport->calls );
	}

	public function test_has_ir_translation_false_on_exception(): void {
		$this->transport->throw = new \RuntimeException( 'Connection failed' );

		$this->assertFalse( $this->service->has_ir_translation() );
	}

	// ─── Push via context (Odoo 16+) ────────────────────────

	public function test_push_translation_via_context(): void {
		// Odoo 16+: no ir.translation model.
		$this->transport->return_value = 0; // search_count for ir.model.
		$this->service->has_ir_translation(); // Cache the result.

		// Reset calls after detection.
		$this->transport->calls = [];
		$this->transport->return_value = true; // write succeeds.

		$this->service->push_translation(
			'product.product',
			42,
			[ 'name' => 'Produit', 'description_sale' => 'Description FR' ],
			'fr'
		);

		// Should call write with context.
		$this->assertCount( 1, $this->transport->calls );
		$call = $this->transport->calls[0];
		$this->assertSame( 'product.product', $call['model'] );
		$this->assertSame( 'write', $call['method'] );
		$this->assertSame( [ [ 42 ], [ 'name' => 'Produit', 'description_sale' => 'Description FR' ] ], $call['args'] );
		$this->assertSame( [ 'context' => [ 'lang' => 'fr_FR' ] ], $call['kwargs'] );
	}

	// ─── Push via ir.translation (Odoo 14-15) ──────────────

	public function test_push_translation_via_ir_translation(): void {
		// Odoo 14-15: ir.translation exists.
		$this->transport->return_value = 1; // search_count for ir.model.
		$this->service->has_ir_translation(); // Cache: true.

		// Reset calls and set return values.
		$this->transport->calls = [];

		// For each field: search returns empty (no existing translation), then create.
		$call_count = 0;
		$original_transport = $this->transport;

		// search for 'name' → empty, create 'name'.
		// search for 'description_sale' → empty, create 'description_sale'.
		$this->transport->return_value = []; // search returns empty.

		$this->service->push_translation(
			'product.product',
			42,
			[ 'name' => 'Produit' ],
			'fr'
		);

		// Should have: search (for name) + create (for name) = 2 calls.
		$this->assertCount( 2, $this->transport->calls );

		// First call: search for existing translation.
		$search_call = $this->transport->calls[0];
		$this->assertSame( 'ir.translation', $search_call['model'] );
		$this->assertSame( 'search', $search_call['method'] );

		// Second call: create new translation.
		$create_call = $this->transport->calls[1];
		$this->assertSame( 'ir.translation', $create_call['model'] );
		$this->assertSame( 'create', $create_call['method'] );
	}

	// ─── Edge cases ─────────────────────────────────────────

	public function test_push_translation_skips_empty_values(): void {
		$this->transport->return_value = 0;
		$this->service->has_ir_translation();
		$this->transport->calls = [];

		$this->service->push_translation( 'product.product', 42, [], 'fr' );

		$this->assertCount( 0, $this->transport->calls );
	}

	public function test_push_translation_skips_zero_id(): void {
		$this->transport->return_value = 0;
		$this->service->has_ir_translation();
		$this->transport->calls = [];

		$this->service->push_translation( 'product.product', 0, [ 'name' => 'Test' ], 'fr' );

		$this->assertCount( 0, $this->transport->calls );
	}

	// ─── Pull translations batch (Odoo 16+) ─────────────────

	public function test_pull_translations_batch_reads_per_language(): void {
		// Set up WPML adapter with 3 languages.
		$GLOBALS['_wp_filters']['wpml_active_languages'] = function () {
			return [
				'en' => [ 'code' => 'en' ],
				'fr' => [ 'code' => 'fr' ],
				'es' => [ 'code' => 'es' ],
			];
		};
		$GLOBALS['_wpml_default_lang'] = 'en';

		// Odoo 16+: no ir.translation.
		$this->transport->return_value = 0;
		$this->service->has_ir_translation();
		$this->transport->calls = [];

		// Mock read to return translated records.
		$this->transport->return_value = [
			[ 'id' => 100, 'name' => 'Produit Test', 'description_sale' => 'Description FR' ],
		];

		// WPML object_id: return existing translation IDs.
		$GLOBALS['_wp_filters']['wpml_object_id'] = function ( $post_id, $post_type, $return_original, $lang ) {
			if ( 'en' !== $lang ) {
				return $post_id + ( 'fr' === $lang ? 1000 : 2000 );
			}
			return $post_id;
		};

		$applied = [];
		$callback = function ( int $trans_wp_id, array $data, string $lang ) use ( &$applied ) {
			$applied[] = [ 'id' => $trans_wp_id, 'data' => $data, 'lang' => $lang ];
		};

		$this->service->pull_translations_batch(
			'product.template',
			[ 100 => 10 ],
			[ 'name', 'description_sale' ],
			[ 'name' => 'post_title', 'description_sale' => 'post_content' ],
			'product',
			$callback
		);

		// Callback should have been called twice (once for fr, once for es).
		$this->assertCount( 2, $applied );

		// Verify mapped data.
		$this->assertSame( 'Produit Test', $applied[0]['data']['post_title'] );
		$this->assertSame( 'Description FR', $applied[0]['data']['post_content'] );
		$this->assertSame( 'fr', $applied[0]['lang'] );
		$this->assertSame( 'es', $applied[1]['lang'] );

		// Verify correct translation post IDs.
		$this->assertSame( 1010, $applied[0]['id'] ); // 10 + 1000 for fr.
		$this->assertSame( 2010, $applied[1]['id'] ); // 10 + 2000 for es.
	}

	public function test_pull_translations_batch_skips_empty_values(): void {
		$GLOBALS['_wp_filters']['wpml_active_languages'] = function () {
			return [
				'en' => [ 'code' => 'en' ],
				'fr' => [ 'code' => 'fr' ],
			];
		};
		$GLOBALS['_wpml_default_lang'] = 'en';

		$this->transport->return_value = 0;
		$this->service->has_ir_translation();
		$this->transport->calls = [];

		// Return record with empty translated name.
		$this->transport->return_value = [
			[ 'id' => 100, 'name' => '', 'description_sale' => '' ],
		];

		$applied = [];
		$callback = function ( int $trans_wp_id, array $data, string $lang ) use ( &$applied ) {
			$applied[] = $data;
		};

		$this->service->pull_translations_batch(
			'product.template',
			[ 100 => 10 ],
			[ 'name', 'description_sale' ],
			[ 'name' => 'post_title', 'description_sale' => 'post_content' ],
			'product',
			$callback
		);

		// Callback should NOT have been called (empty values filtered).
		$this->assertCount( 0, $applied );
	}

	public function test_pull_translations_batch_skips_when_no_adapter(): void {
		// Force no adapter by removing WPML/Polylang constants.
		// Use a fresh service with forced no-adapter.
		$ref = new \ReflectionClass( $this->service );
		$prop = $ref->getProperty( 'adapter' );
		$prop->setAccessible( true );
		$prop->setValue( $this->service, false );

		$applied = [];
		$this->service->pull_translations_batch(
			'product.template',
			[ 100 => 10 ],
			[ 'name' ],
			[ 'name' => 'post_title' ],
			'product',
			function () use ( &$applied ) { $applied[] = true; }
		);

		$this->assertCount( 0, $applied );
	}

	public function test_pull_translations_batch_skips_empty_map(): void {
		$this->transport->return_value = 0;
		$this->service->has_ir_translation();
		$this->transport->calls = [];

		$applied = [];
		$this->service->pull_translations_batch(
			'product.template',
			[], // Empty map.
			[ 'name' ],
			[ 'name' => 'post_title' ],
			'product',
			function () use ( &$applied ) { $applied[] = true; }
		);

		$this->assertCount( 0, $applied );
		// No Odoo calls should have been made.
		$this->assertCount( 0, $this->transport->calls );
	}

	// ─── Pull translations batch (Odoo 14-15 ir.translation) ─

	public function test_pull_translations_batch_filters_by_enabled_languages(): void {
		$GLOBALS['_wp_filters']['wpml_active_languages'] = function () {
			return [
				'en' => [ 'code' => 'en' ],
				'fr' => [ 'code' => 'fr' ],
				'es' => [ 'code' => 'es' ],
				'de' => [ 'code' => 'de' ],
			];
		};
		$GLOBALS['_wpml_default_lang'] = 'en';

		// Odoo 16+: no ir.translation.
		$this->transport->return_value = 0;
		$this->service->has_ir_translation();
		$this->transport->calls = [];

		$this->transport->return_value = [
			[ 'id' => 100, 'name' => 'Translated', 'description_sale' => 'Desc' ],
		];

		$GLOBALS['_wp_filters']['wpml_object_id'] = function ( $post_id, $post_type, $return_original, $lang ) {
			$offsets = [ 'fr' => 1000, 'es' => 2000, 'de' => 3000 ];
			if ( isset( $offsets[ $lang ] ) ) {
				return $post_id + $offsets[ $lang ];
			}
			return $post_id;
		};

		$applied = [];
		$callback = function ( int $trans_wp_id, array $data, string $lang ) use ( &$applied ) {
			$applied[] = [ 'lang' => $lang ];
		};

		// Only enable fr and de — es should be skipped.
		$this->service->pull_translations_batch(
			'product.template',
			[ 100 => 10 ],
			[ 'name', 'description_sale' ],
			[ 'name' => 'post_title', 'description_sale' => 'post_content' ],
			'product',
			$callback,
			[ 'fr', 'de' ]
		);

		// Callback should have been called twice (fr and de), NOT for es.
		$this->assertCount( 2, $applied );
		$langs = array_column( $applied, 'lang' );
		$this->assertContains( 'fr', $langs );
		$this->assertContains( 'de', $langs );
		$this->assertNotContains( 'es', $langs );
	}

	// ─── Language detection (detect_languages) ───────────────

	public function test_detect_languages_returns_null_without_adapter(): void {
		// Force no adapter.
		$ref = new \ReflectionClass( $this->service );
		$prop = $ref->getProperty( 'adapter' );
		$prop->setAccessible( true );
		$prop->setValue( $this->service, false );

		$this->assertNull( $this->service->detect_languages() );
	}

	public function test_detect_languages_returns_structured_result(): void {
		$GLOBALS['_wp_filters']['wpml_default_language'] = function () {
			return 'en';
		};
		$GLOBALS['_wp_filters']['wpml_active_languages'] = function () {
			return [
				'en' => [ 'code' => 'en' ],
				'fr' => [ 'code' => 'fr' ],
			];
		};

		// Odoo res.lang returns en_US and fr_FR as active.
		$this->transport->return_value = [
			[ 'code' => 'en_US' ],
			[ 'code' => 'fr_FR' ],
		];

		$result = $this->service->detect_languages();

		$this->assertNotNull( $result );
		$this->assertSame( 'WPML', $result['plugin'] );
		$this->assertSame( 'en', $result['default_language'] );
		$this->assertArrayHasKey( 'en', $result['languages'] );
		$this->assertArrayHasKey( 'fr', $result['languages'] );
		$this->assertTrue( $result['languages']['en']['odoo_available'] );
		$this->assertTrue( $result['languages']['fr']['odoo_available'] );
		$this->assertSame( 'fr_FR', $result['languages']['fr']['odoo_locale'] );
	}

	public function test_detect_languages_marks_unavailable_odoo_locale(): void {
		$GLOBALS['_wp_filters']['wpml_default_language'] = function () {
			return 'en';
		};
		$GLOBALS['_wp_filters']['wpml_active_languages'] = function () {
			return [
				'en' => [ 'code' => 'en' ],
				'fr' => [ 'code' => 'fr' ],
				'de' => [ 'code' => 'de' ],
			];
		};

		// Odoo only has en_US installed — fr_FR and de_DE are missing.
		$this->transport->return_value = [
			[ 'code' => 'en_US' ],
		];

		$result = $this->service->detect_languages();

		$this->assertNotNull( $result );
		$this->assertTrue( $result['languages']['en']['odoo_available'] );
		$this->assertFalse( $result['languages']['fr']['odoo_available'] );
		$this->assertFalse( $result['languages']['de']['odoo_available'] );
	}

	public function test_detect_languages_caches_odoo_locales(): void {
		$GLOBALS['_wp_filters']['wpml_default_language'] = function () {
			return 'en';
		};
		$GLOBALS['_wp_filters']['wpml_active_languages'] = function () {
			return [ 'en' => [ 'code' => 'en' ] ];
		};

		// Pre-set the transient to avoid API call.
		$GLOBALS['_wp_transients']['wp4odoo_odoo_active_langs'] = [ 'en_US', 'fr_FR' ];

		$result = $this->service->detect_languages();

		// No API calls should be made (cached via transient).
		$this->assertCount( 0, $this->transport->calls );
		$this->assertTrue( $result['languages']['en']['odoo_available'] );
	}

	// ─── Pull translations batch (Odoo 14-15 ir.translation) ─

	public function test_pull_translations_batch_via_ir_translation(): void {
		$GLOBALS['_wp_filters']['wpml_active_languages'] = function () {
			return [
				'en' => [ 'code' => 'en' ],
				'fr' => [ 'code' => 'fr' ],
			];
		};
		$GLOBALS['_wpml_default_lang'] = 'en';

		// Odoo 14-15: ir.translation exists.
		$this->transport->return_value = 1;
		$this->service->has_ir_translation();
		$this->transport->calls = [];

		// Return ir.translation search_read results.
		$this->transport->return_value = [
			[ 'name' => 'product.template,name', 'res_id' => 100, 'value' => 'Produit FR' ],
			[ 'name' => 'product.template,description_sale', 'res_id' => 100, 'value' => 'Description FR' ],
		];

		// Existing translation for FR.
		$GLOBALS['_wp_filters']['wpml_object_id'] = function ( $post_id, $post_type, $return_original, $lang ) {
			if ( 'fr' === $lang && 10 === $post_id ) {
				return 1010;
			}
			return $post_id;
		};

		$applied = [];
		$callback = function ( int $trans_wp_id, array $data, string $lang ) use ( &$applied ) {
			$applied[] = [ 'id' => $trans_wp_id, 'data' => $data, 'lang' => $lang ];
		};

		$this->service->pull_translations_batch(
			'product.template',
			[ 100 => 10 ],
			[ 'name', 'description_sale' ],
			[ 'name' => 'post_title', 'description_sale' => 'post_content' ],
			'product',
			$callback
		);

		// Callback should have been called with mapped data.
		$this->assertCount( 1, $applied );
		$this->assertSame( 1010, $applied[0]['id'] );
		$this->assertSame( 'Produit FR', $applied[0]['data']['post_title'] );
		$this->assertSame( 'Description FR', $applied[0]['data']['post_content'] );
		$this->assertSame( 'fr', $applied[0]['lang'] );
	}
}
