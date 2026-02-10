<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Lead_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Lead_Manager.
 *
 * Covers lead data load/save, shortcode rendering, and AJAX form submission.
 */
class LeadManagerTest extends TestCase {

	private Lead_Manager $manager;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options'] = [
			'wp4odoo_log_settings' => [ 'enabled' => true, 'level' => 'debug' ],
		];
		$GLOBALS['_wp_posts'] = [];

		$logger        = new Logger();
		$this->manager = new Lead_Manager(
			$logger,
			fn() => [ 'lead_form_enabled' => false ]
		);
	}

	protected function tearDown(): void {
		$_POST = [];
		unset( $GLOBALS['_wp_posts'], $GLOBALS['_wp_options'] );
	}

	// ─── load_lead_data() ──────────────────────────────────

	public function test_load_returns_empty_when_post_not_found(): void {
		$result = $this->manager->load_lead_data( 999 );

		$this->assertSame( [], $result );
	}

	public function test_load_returns_empty_when_wrong_post_type(): void {
		$post              = new \stdClass();
		$post->ID          = 42;
		$post->post_type   = 'post';
		$post->post_title  = 'Not a lead';
		$post->post_content = 'Some content';

		$GLOBALS['_wp_posts'][42] = $post;

		$result = $this->manager->load_lead_data( 42 );

		$this->assertSame( [], $result );
	}

	public function test_load_returns_data_when_valid_lead(): void {
		$post              = new \stdClass();
		$post->ID          = 42;
		$post->post_type   = 'wp4odoo_lead';
		$post->post_title  = 'Test Lead';
		$post->post_content = 'Test description';

		$GLOBALS['_wp_posts'][42] = $post;

		$result = $this->manager->load_lead_data( 42 );

		$this->assertSame( 'Test Lead', $result['name'] );
		$this->assertSame( 'Test description', $result['description'] );
		$this->assertArrayHasKey( 'email', $result );
		$this->assertArrayHasKey( 'phone', $result );
		$this->assertArrayHasKey( 'company', $result );
		$this->assertArrayHasKey( 'source', $result );
	}

	// ─── save_lead_data() ──────────────────────────────────

	public function test_save_creates_new_lead(): void {
		$data = [
			'name'        => 'New Lead',
			'email'       => 'lead@example.com',
			'phone'       => '+33123456789',
			'company'     => 'Acme Inc.',
			'description' => 'Interested in our product.',
			'source'      => 'Website',
		];

		$post_id = $this->manager->save_lead_data( $data, 0 );

		$this->assertGreaterThan( 0, $post_id );
	}

	public function test_save_updates_existing_lead(): void {
		$data = [
			'name'        => 'Updated Lead',
			'email'       => 'updated@example.com',
			'phone'       => '+33987654321',
			'company'     => 'Updated Corp.',
			'description' => 'Updated description.',
			'source'      => 'Email',
		];

		$post_id = $this->manager->save_lead_data( $data, 77 );

		$this->assertSame( 77, $post_id );
	}

	// ─── render_lead_form() ────────────────────────────────

	public function test_render_returns_empty_when_disabled(): void {
		$manager = new Lead_Manager(
			new Logger(),
			fn() => [ 'lead_form_enabled' => false ]
		);

		$html = $manager->render_lead_form( [] );

		$this->assertSame( '', $html );
	}

	public function test_render_returns_html_when_enabled(): void {
		$manager = new Lead_Manager(
			new Logger(),
			fn() => [ 'lead_form_enabled' => true ]
		);

		$html = $manager->render_lead_form( [] );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'wp4odoo-lead-form', $html );
		$this->assertStringContainsString( 'name="name"', $html );
		$this->assertStringContainsString( 'name="email"', $html );
	}

	// ─── handle_lead_submission() ──────────────────────────

	public function test_handle_submission_errors_on_missing_name(): void {
		$_POST = [
			'email' => 'test@example.com',
		];

		$this->expectException( \WP4Odoo_Test_JsonError::class );

		$this->manager->handle_lead_submission();
	}

	public function test_handle_submission_errors_on_invalid_email(): void {
		$_POST = [
			'name'  => 'John Doe',
			'email' => 'not-an-email',
		];

		try {
			$this->manager->handle_lead_submission();
			$this->fail( 'Expected WP4Odoo_Test_JsonError was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonError $e ) {
			$this->assertStringContainsString( 'email', strtolower( $e->data['message'] ) );
		}
	}

	public function test_handle_submission_succeeds(): void {
		global $wpdb;
		$wpdb            = new \WP_DB_Stub();
		$wpdb->insert_id = 1;

		$_POST = [
			'name'        => 'Jane Doe',
			'email'       => 'jane@example.com',
			'phone'       => '+33600000000',
			'company'     => 'TestCorp',
			'description' => 'I want to learn more.',
			'source'      => 'Contact page',
		];

		try {
			$this->manager->handle_lead_submission();
			$this->fail( 'Expected WP4Odoo_Test_JsonSuccess was not thrown.' );
		} catch ( \WP4Odoo_Test_JsonSuccess $e ) {
			$this->assertArrayHasKey( 'message', $e->data );
		}
	}
}
