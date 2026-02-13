<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\I18n\Polylang_Adapter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Polylang_Adapter.
 *
 * Tests Polylang function-based API integration for translation detection.
 *
 * @covers \WP4Odoo\I18n\Polylang_Adapter
 */
class PolylangAdapterTest extends TestCase {

	private Polylang_Adapter $adapter;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_pll_translations'] = [];
		$GLOBALS['_pll_languages']    = [ 'en', 'fr', 'es' ];
		$GLOBALS['_pll_default_lang'] = 'en';
		$GLOBALS['_post_languages']   = [];

		$this->adapter = new Polylang_Adapter();
	}

	// ─── get_default_language ───────────────────────────────

	public function test_get_default_language(): void {
		$GLOBALS['_pll_default_lang'] = 'en';
		$this->assertSame( 'en', $this->adapter->get_default_language() );
	}

	public function test_get_default_language_french(): void {
		$GLOBALS['_pll_default_lang'] = 'fr';
		$this->assertSame( 'fr', $this->adapter->get_default_language() );
	}

	// ─── get_active_languages ───────────────────────────────

	public function test_get_active_languages(): void {
		$GLOBALS['_pll_languages'] = [ 'en', 'fr', 'de' ];
		$this->assertSame( [ 'en', 'fr', 'de' ], $this->adapter->get_active_languages() );
	}

	// ─── get_post_language ──────────────────────────────────

	public function test_get_post_language(): void {
		$GLOBALS['_post_languages'][10] = 'fr';
		$this->assertSame( 'fr', $this->adapter->get_post_language( 10 ) );
	}

	public function test_get_post_language_empty_for_unknown(): void {
		$this->assertSame( '', $this->adapter->get_post_language( 999 ) );
	}

	// ─── get_translations ───────────────────────────────────

	public function test_get_translations_returns_translations(): void {
		$GLOBALS['_post_languages'][10] = 'en';
		$GLOBALS['_pll_translations'][10] = [
			'en' => 10,
			'fr' => 20,
			'es' => 30,
		];

		$translations = $this->adapter->get_translations( 10 );

		$this->assertSame( [ 'fr' => 20, 'es' => 30 ], $translations );
	}

	public function test_get_translations_excludes_self(): void {
		$GLOBALS['_post_languages'][10] = 'en';
		$GLOBALS['_pll_translations'][10] = [
			'en' => 10,
			'fr' => 20,
		];

		$translations = $this->adapter->get_translations( 10 );

		$this->assertArrayNotHasKey( 'en', $translations );
		$this->assertSame( [ 'fr' => 20 ], $translations );
	}

	public function test_get_translations_empty_for_no_translations(): void {
		$GLOBALS['_post_languages'][10] = 'en';
		$GLOBALS['_pll_translations'][10] = [ 'en' => 10 ];

		$this->assertSame( [], $this->adapter->get_translations( 10 ) );
	}

	// ─── get_original_post_id ───────────────────────────────

	public function test_get_original_post_id_for_translation(): void {
		$GLOBALS['_pll_default_lang'] = 'en';
		// Translation 20 (fr) → original 10 (en).
		$GLOBALS['_pll_translations'][10] = [
			'en' => 10,
			'fr' => 20,
		];

		$this->assertSame( 10, $this->adapter->get_original_post_id( 20 ) );
	}

	public function test_get_original_post_id_returns_self_for_original(): void {
		$GLOBALS['_pll_default_lang'] = 'en';
		$GLOBALS['_pll_translations'][10] = [
			'en' => 10,
			'fr' => 20,
		];

		$this->assertSame( 10, $this->adapter->get_original_post_id( 10 ) );
	}

	// ─── is_translation ─────────────────────────────────────

	public function test_is_translation_true(): void {
		$GLOBALS['_pll_default_lang'] = 'en';
		$GLOBALS['_pll_translations'][10] = [
			'en' => 10,
			'fr' => 20,
		];

		$this->assertTrue( $this->adapter->is_translation( 20 ) );
	}

	public function test_is_translation_false_for_original(): void {
		$GLOBALS['_pll_default_lang'] = 'en';
		$GLOBALS['_pll_translations'][10] = [
			'en' => 10,
			'fr' => 20,
		];

		$this->assertFalse( $this->adapter->is_translation( 10 ) );
	}

	// ─── create_translation ────────────────────────────────

	public function test_create_translation_returns_existing(): void {
		$GLOBALS['_pll_translations'][10] = [
			'en' => 10,
			'fr' => 20,
		];

		$result = $this->adapter->create_translation( 10, 'fr', 'product' );

		$this->assertSame( 20, $result );
	}

	public function test_create_translation_creates_new_post(): void {
		$GLOBALS['_pll_default_lang'] = 'en';
		$GLOBALS['_pll_translations'][10] = [];
		$GLOBALS['_pll_saved_translations'] = [];

		// wp_insert_post will return the next auto-increment ID from stub.
		$result = $this->adapter->create_translation( 10, 'fr', 'product' );

		$this->assertGreaterThan( 0, $result );

		// Verify pll_set_post_language was called.
		$this->assertSame( 'fr', $GLOBALS['_post_languages'][ $result ] ?? '' );

		// Verify pll_save_post_translations was called.
		$this->assertNotEmpty( $GLOBALS['_pll_saved_translations'] );
		$saved = end( $GLOBALS['_pll_saved_translations'] );
		$this->assertSame( 10, $saved['en'] );
		$this->assertSame( $result, $saved['fr'] );
	}

	// ─── set_post_language ─────────────────────────────────

	public function test_set_post_language(): void {
		$this->adapter->set_post_language( 50, 'es', 'product' );

		$this->assertSame( 'es', $GLOBALS['_post_languages'][50] );
	}

	// ─── link_translations ─────────────────────────────────

	public function test_link_translations(): void {
		$GLOBALS['_pll_saved_translations'] = [];

		$this->adapter->link_translations( [ 'en' => 10, 'fr' => 20, 'es' => 30 ] );

		$this->assertCount( 1, $GLOBALS['_pll_saved_translations'] );
		$this->assertSame( [ 'en' => 10, 'fr' => 20, 'es' => 30 ], $GLOBALS['_pll_saved_translations'][0] );
	}

	// ─── get_term_translations (Phase 6) ────────────────────

	public function test_get_term_translations_returns_translated_term_ids(): void {
		$GLOBALS['_pll_term_languages'][100]      = 'en';
		$GLOBALS['_pll_term_translations'][100] = [
			'en' => 100,
			'fr' => 200,
			'es' => 300,
		];

		$result = $this->adapter->get_term_translations( 100, 'product_cat' );

		$this->assertSame( [ 'fr' => 200, 'es' => 300 ], $result );
	}

	public function test_get_term_translations_empty_for_no_translations(): void {
		$GLOBALS['_pll_term_languages'][100]      = 'en';
		$GLOBALS['_pll_term_translations'][100] = [ 'en' => 100 ];

		$result = $this->adapter->get_term_translations( 100, 'product_cat' );

		$this->assertSame( [], $result );
	}

	// ─── create_term_translation (Phase 6) ──────────────────

	public function test_create_term_translation_returns_existing(): void {
		$GLOBALS['_pll_term_translations'][100] = [
			'en' => 100,
			'fr' => 200,
		];

		$result = $this->adapter->create_term_translation( 100, 'fr', 'product_cat' );

		$this->assertSame( 200, $result );
	}

	public function test_create_term_translation_creates_and_links_term(): void {
		$GLOBALS['_pll_default_lang']            = 'en';
		$GLOBALS['_pll_term_translations'][100]  = [];
		$GLOBALS['_pll_term_languages']          = [];
		$GLOBALS['_pll_saved_term_translations'] = [];

		$result = $this->adapter->create_term_translation( 100, 'fr', 'product_cat' );

		// wp_insert_term returns auto-increment ID > 0.
		$this->assertGreaterThan( 0, $result );

		// Verify pll_set_term_language was called.
		$this->assertSame( 'fr', $GLOBALS['_pll_term_languages'][ $result ] ?? '' );

		// Verify pll_save_term_translations was called.
		$this->assertNotEmpty( $GLOBALS['_pll_saved_term_translations'] );
		$saved = end( $GLOBALS['_pll_saved_term_translations'] );
		$this->assertSame( 100, $saved['en'] );
		$this->assertSame( $result, $saved['fr'] );
	}
}
