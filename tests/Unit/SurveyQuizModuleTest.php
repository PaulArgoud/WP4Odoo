<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\Survey_Quiz_Module;

/**
 * Unit tests for Survey_Quiz_Module.
 *
 * Tests module configuration, entity type declarations, default settings,
 * deduplication domains, load_wp_data dispatch, and boot safety.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Survey_Quiz_Module::class)]
class SurveyQuizModuleTest extends Module_Test_Case {

	private Survey_Quiz_Module $module;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->module = new Survey_Quiz_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_identity(): void {
		$this->assertModuleIdentity( $this->module, 'survey_quiz', 'Survey & Quiz', '', 'wp_to_odoo' );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_odoo_models(): void {
		$this->assertOdooModels( $this->module, [
			'survey'   => 'survey.survey',
			'response' => 'survey.user_input',
		] );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings(): void {
		$this->assertDefaultSettings( $this->module, [
			'sync_ays_quizzes' => true,
			'sync_qsm'        => true,
		] );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_are_checkboxes(): void {
		$this->assertSettingsFieldsAreCheckboxes( $this->module, [
			'sync_ays_quizzes',
			'sync_qsm',
		] );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Deduplication ─────────────────────────────────────

	public function test_survey_dedup_uses_title(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'survey', [ 'title' => 'Math Quiz' ] );

		$this->assertCount( 1, $domain );
		$this->assertSame( [ 'title', '=', 'Math Quiz' ], $domain[0] );
	}

	public function test_survey_dedup_empty_without_title(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'survey', [] );

		$this->assertEmpty( $domain );
	}

	public function test_response_dedup_returns_empty(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'response', [ 'state' => 'done' ] );

		$this->assertEmpty( $domain );
	}

	// ─── Boot ──────────────────────────────────────────────

	public function test_boot_does_not_throw(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── map_to_odoo ───────────────────────────────────────

	public function test_map_to_odoo_survey_is_identity(): void {
		$data = [
			'title'                 => 'Science Quiz',
			'description'           => 'A quiz about science.',
			'question_and_page_ids' => [ [ 0, 0, [ 'title' => 'Q1', 'question_type' => 'text_box' ] ] ],
		];

		$mapped = $this->module->map_to_odoo( 'survey', $data );

		$this->assertSame( 'Science Quiz', $mapped['title'] );
		$this->assertSame( 'A quiz about science.', $mapped['description'] );
		$this->assertCount( 1, $mapped['question_and_page_ids'] );
	}

	public function test_map_to_odoo_response_is_identity(): void {
		$data = [
			'state'      => 'done',
			'survey_id'  => 42,
			'partner_id' => 7,
		];

		$mapped = $this->module->map_to_odoo( 'response', $data );

		$this->assertSame( 'done', $mapped['state'] );
		$this->assertSame( 42, $mapped['survey_id'] );
		$this->assertSame( 7, $mapped['partner_id'] );
	}

	// ─── load_wp_data ──────────────────────────────────────

	public function test_load_wp_data_survey_reads_from_option(): void {
		$GLOBALS['_wp_options']['wp4odoo_survey_5'] = [
			'title'       => 'Stored Quiz',
			'description' => 'From option.',
		];

		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$data   = $method->invoke( $this->module, 'survey', 5 );

		$this->assertSame( 'Stored Quiz', $data['title'] );
		$this->assertSame( 'From option.', $data['description'] );
	}

	public function test_load_wp_data_survey_returns_empty_when_not_found(): void {
		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$data   = $method->invoke( $this->module, 'survey', 999 );

		$this->assertSame( [], $data );
	}

	public function test_load_wp_data_response_reads_from_option(): void {
		$GLOBALS['_wp_options']['wp4odoo_survey_response_10'] = [
			'state'      => 'done',
			'survey_id'  => 42,
			'partner_id' => 7,
		];

		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$data   = $method->invoke( $this->module, 'response', 10 );

		$this->assertSame( 'done', $data['state'] );
		$this->assertSame( 42, $data['survey_id'] );
	}

	public function test_load_wp_data_response_returns_empty_when_not_found(): void {
		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$data   = $method->invoke( $this->module, 'response', 999 );

		$this->assertSame( [], $data );
	}

	public function test_load_wp_data_unknown_entity_returns_empty(): void {
		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$data   = $method->invoke( $this->module, 'unknown', 1 );

		$this->assertSame( [], $data );
	}
}
