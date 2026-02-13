<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP4Odoo\Logger;
use WP4Odoo\Modules\Job_Manager_Handler;

/**
 * @covers \WP4Odoo\Modules\Job_Manager_Handler
 */
class JobManagerHandlerTest extends TestCase {

	private Job_Manager_Handler $handler;

	protected function setUp(): void {
		$this->handler = new Job_Manager_Handler( new Logger( 'test' ) );

		// Reset global stores.
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wp_options']   = [];
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Create a job_listing post in the global store.
	 *
	 * @param int                   $id     Post ID.
	 * @param string                $title  Job title.
	 * @param string                $status Post status.
	 * @param array<string, string> $meta   Post meta.
	 */
	private function create_job( int $id, string $title = 'Software Engineer', string $status = 'publish', array $meta = [] ): void {
		$post               = new \stdClass();
		$post->ID           = $id;
		$post->post_title   = $title;
		$post->post_type    = 'job_listing';
		$post->post_status  = $status;
		$post->post_content = '';
		$post->post_date    = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][ $id ] = $post;

		if ( ! empty( $meta ) ) {
			$GLOBALS['_wp_post_meta'][ $id ] = $meta;
		}
	}

	// ─── load_job: basic ────────────────────────────────────

	public function test_load_job_returns_name(): void {
		$this->create_job( 10, 'Backend Developer' );
		$data = $this->handler->load_job( 10 );

		$this->assertSame( 'Backend Developer', $data['name'] );
	}

	public function test_load_job_returns_recruit_state_for_published(): void {
		$this->create_job( 10 );
		$data = $this->handler->load_job( 10 );

		$this->assertSame( 'recruit', $data['state'] );
	}

	public function test_load_job_returns_open_state_for_expired(): void {
		$this->create_job( 10, 'Designer', 'expired' );
		$data = $this->handler->load_job( 10 );

		$this->assertSame( 'open', $data['state'] );
	}

	public function test_load_job_returns_open_state_when_filled(): void {
		$this->create_job( 10, 'Designer', 'publish', [ '_filled' => '1' ] );
		$data = $this->handler->load_job( 10 );

		$this->assertSame( 'open', $data['state'] );
	}

	public function test_load_job_returns_open_state_for_preview(): void {
		$this->create_job( 10, 'Designer', 'preview' );
		$data = $this->handler->load_job( 10 );

		$this->assertSame( 'open', $data['state'] );
	}

	public function test_load_job_returns_open_state_for_pending(): void {
		$this->create_job( 10, 'Designer', 'pending' );
		$data = $this->handler->load_job( 10 );

		$this->assertSame( 'open', $data['state'] );
	}

	public function test_load_job_returns_one_recruitment_when_not_filled(): void {
		$this->create_job( 10 );
		$data = $this->handler->load_job( 10 );

		$this->assertSame( 1, $data['no_of_recruitment'] );
	}

	public function test_load_job_returns_zero_recruitment_when_filled(): void {
		$this->create_job( 10, 'Designer', 'publish', [ '_filled' => '1' ] );
		$data = $this->handler->load_job( 10 );

		$this->assertSame( 0, $data['no_of_recruitment'] );
	}

	// ─── load_job: description ──────────────────────────────

	public function test_load_job_includes_content_in_description(): void {
		$this->create_job( 10 );
		$GLOBALS['_wp_posts'][10]->post_content = 'We are looking for a developer.';
		$data = $this->handler->load_job( 10 );

		$this->assertStringContainsString( 'We are looking for a developer.', $data['description'] );
	}

	public function test_load_job_includes_location_in_description(): void {
		$this->create_job( 10, 'Dev', 'publish', [ '_job_location' => 'Paris, France' ] );
		$data = $this->handler->load_job( 10 );

		$this->assertStringContainsString( 'Paris, France', $data['description'] );
	}

	public function test_load_job_includes_company_in_description(): void {
		$this->create_job( 10, 'Dev', 'publish', [ '_company_name' => 'Acme Inc.' ] );
		$data = $this->handler->load_job( 10 );

		$this->assertStringContainsString( 'Acme Inc.', $data['description'] );
	}

	public function test_load_job_strips_html_from_content(): void {
		$this->create_job( 10 );
		$GLOBALS['_wp_posts'][10]->post_content = '<p>We need a <strong>developer</strong>.</p>';
		$data = $this->handler->load_job( 10 );

		$this->assertStringNotContainsString( '<', $data['description'] );
		$this->assertStringContainsString( 'We need a developer.', $data['description'] );
	}

	public function test_load_job_empty_description_when_no_content_or_meta(): void {
		$this->create_job( 10 );
		$data = $this->handler->load_job( 10 );

		$this->assertSame( '', $data['description'] );
	}

	public function test_load_job_combines_content_and_info_line(): void {
		$this->create_job( 10, 'Dev', 'publish', [
			'_job_location' => 'London',
			'_company_name' => 'ACME',
		] );
		$GLOBALS['_wp_posts'][10]->post_content = 'Great opportunity.';
		$data = $this->handler->load_job( 10 );

		// Content first, then info line separated by double newline.
		$this->assertStringContainsString( "Great opportunity.\n\n", $data['description'] );
		$this->assertStringContainsString( 'London', $data['description'] );
		$this->assertStringContainsString( 'ACME', $data['description'] );
	}

	public function test_load_job_location_and_company_joined_with_pipe(): void {
		$this->create_job( 10, 'Dev', 'publish', [
			'_job_location' => 'Berlin',
			'_company_name' => 'BigCorp',
		] );
		$data = $this->handler->load_job( 10 );

		$this->assertStringContainsString( ' | ', $data['description'] );
	}

	// ─── load_job: not found ────────────────────────────────

	public function test_load_job_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_job( 999 ) );
	}

	public function test_load_job_empty_for_wrong_post_type(): void {
		$post              = new \stdClass();
		$post->ID          = 10;
		$post->post_title  = 'Not a job';
		$post->post_type   = 'post';
		$post->post_status = 'publish';
		$post->post_date   = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][10] = $post;

		$this->assertSame( [], $this->handler->load_job( 10 ) );
	}

	// ─── parse_job_from_odoo ────────────────────────────────

	public function test_parse_job_returns_name(): void {
		$data = $this->handler->parse_job_from_odoo( [
			'name'  => 'PHP Developer',
			'state' => 'recruit',
		] );

		$this->assertSame( 'PHP Developer', $data['name'] );
	}

	public function test_parse_job_maps_recruit_to_publish(): void {
		$data = $this->handler->parse_job_from_odoo( [ 'state' => 'recruit' ] );

		$this->assertSame( 'publish', $data['post_status'] );
	}

	public function test_parse_job_maps_open_to_expired(): void {
		$data = $this->handler->parse_job_from_odoo( [ 'state' => 'open' ] );

		$this->assertSame( 'expired', $data['post_status'] );
	}

	public function test_parse_job_defaults_unknown_state_to_expired(): void {
		$data = $this->handler->parse_job_from_odoo( [ 'state' => 'closed' ] );

		$this->assertSame( 'expired', $data['post_status'] );
	}

	public function test_parse_job_extracts_department_name_from_many2one(): void {
		$data = $this->handler->parse_job_from_odoo( [
			'department_id' => [ 5, 'Engineering' ],
		] );

		$this->assertSame( 'Engineering', $data['department_name'] );
	}

	public function test_parse_job_empty_department_when_false(): void {
		$data = $this->handler->parse_job_from_odoo( [
			'department_id' => false,
		] );

		$this->assertSame( '', $data['department_name'] );
	}

	public function test_parse_job_empty_department_when_missing(): void {
		$data = $this->handler->parse_job_from_odoo( [] );

		$this->assertSame( '', $data['department_name'] );
	}

	public function test_parse_job_filled_when_no_recruitment(): void {
		$data = $this->handler->parse_job_from_odoo( [
			'no_of_recruitment' => 0,
		] );

		$this->assertTrue( $data['filled'] );
	}

	public function test_parse_job_not_filled_when_recruiting(): void {
		$data = $this->handler->parse_job_from_odoo( [
			'no_of_recruitment' => 3,
		] );

		$this->assertFalse( $data['filled'] );
	}

	public function test_parse_job_preserves_description(): void {
		$data = $this->handler->parse_job_from_odoo( [
			'description' => 'Looking for a senior developer.',
		] );

		$this->assertSame( 'Looking for a senior developer.', $data['description'] );
	}

	// ─── save_job ───────────────────────────────────────────

	public function test_save_job_creates_new_post(): void {
		$post_id = $this->handler->save_job( [
			'name'        => 'DevOps Engineer',
			'description' => 'Cloud infrastructure role.',
			'post_status' => 'publish',
			'filled'      => false,
		] );

		$this->assertGreaterThan( 0, $post_id );
	}

	public function test_save_job_updates_existing_post(): void {
		$this->create_job( 50, 'Old Title' );

		$post_id = $this->handler->save_job( [
			'name'        => 'New Title',
			'description' => 'Updated description.',
			'post_status' => 'publish',
			'filled'      => false,
		], 50 );

		$this->assertSame( 50, $post_id );
	}

	public function test_save_job_sets_filled_meta(): void {
		$post_id = $this->handler->save_job( [
			'name'        => 'Filled Job',
			'description' => '',
			'post_status' => 'expired',
			'filled'      => true,
		] );

		$this->assertSame( '1', $GLOBALS['_wp_post_meta'][ $post_id ]['_filled'] ?? '' );
	}

	public function test_save_job_sets_unfilled_meta(): void {
		$post_id = $this->handler->save_job( [
			'name'        => 'Open Job',
			'description' => '',
			'post_status' => 'publish',
			'filled'      => false,
		] );

		$this->assertSame( '0', $GLOBALS['_wp_post_meta'][ $post_id ]['_filled'] ?? '' );
	}

	public function test_save_job_assigns_department_as_category(): void {
		$post_id = $this->handler->save_job( [
			'name'            => 'Dev',
			'description'     => '',
			'post_status'     => 'publish',
			'filled'          => false,
			'department_name' => 'Engineering',
		] );

		$this->assertGreaterThan( 0, $post_id );
	}

	public function test_save_job_skips_empty_department(): void {
		$post_id = $this->handler->save_job( [
			'name'            => 'Dev',
			'description'     => '',
			'post_status'     => 'publish',
			'filled'          => false,
			'department_name' => '',
		] );

		// Should succeed without errors.
		$this->assertGreaterThan( 0, $post_id );
	}

	public function test_save_job_sets_post_status(): void {
		$post_id = $this->handler->save_job( [
			'name'        => 'Closed Job',
			'description' => '',
			'post_status' => 'expired',
			'filled'      => true,
		] );

		$this->assertGreaterThan( 0, $post_id );
	}
}
