<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\WPRM_Handler;
use WP4Odoo\Logger;

#[CoversClass(\WP4Odoo\Modules\WPRM_Handler::class)]
class WPRMHandlerTest extends TestCase {

	private WPRM_Handler $handler;

	protected function setUp(): void {
		$this->handler = new WPRM_Handler( new Logger( 'test' ) );

		// Reset global stores.
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wp_options']   = [];
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Create a WPRM recipe in the global store.
	 *
	 * @param int                    $id    Post ID.
	 * @param string                 $title Recipe title.
	 * @param array<string, string>  $meta  Recipe meta values.
	 */
	private function create_recipe( int $id, string $title = 'Chocolate Cake', array $meta = [] ): void {
		$post              = new \stdClass();
		$post->ID          = $id;
		$post->post_title  = $title;
		$post->post_type   = 'wprm_recipe';
		$post->post_status = 'publish';
		$post->post_date   = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][ $id ] = $post;

		if ( ! empty( $meta ) ) {
			$GLOBALS['_wp_post_meta'][ $id ] = $meta;
		}
	}

	// ─── load_recipe: basic ─────────────────────────────────

	public function test_load_recipe_returns_name(): void {
		$this->create_recipe( 10, 'Lemon Tart' );
		$data = $this->handler->load_recipe( 10 );

		$this->assertSame( 'Lemon Tart', $data['recipe_name'] );
	}

	public function test_load_recipe_returns_service_type(): void {
		$this->create_recipe( 10 );
		$data = $this->handler->load_recipe( 10 );

		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_recipe_returns_zero_price_by_default(): void {
		$this->create_recipe( 10 );
		$data = $this->handler->load_recipe( 10 );

		$this->assertSame( 0.0, $data['list_price'] );
	}

	public function test_load_recipe_uses_cost_meta(): void {
		$this->create_recipe( 10, 'Cake', [ 'wprm_cost' => '12.50' ] );
		$data = $this->handler->load_recipe( 10 );

		$this->assertSame( 12.5, $data['list_price'] );
	}

	// ─── load_recipe: description ───────────────────────────

	public function test_load_recipe_includes_summary(): void {
		$this->create_recipe( 10, 'Cake', [ 'wprm_summary' => 'A rich chocolate cake.' ] );
		$data = $this->handler->load_recipe( 10 );

		$this->assertStringContainsString( 'A rich chocolate cake.', $data['description'] );
	}

	public function test_load_recipe_strips_html_from_summary(): void {
		$this->create_recipe( 10, 'Cake', [ 'wprm_summary' => '<p>A <strong>rich</strong> cake.</p>' ] );
		$data = $this->handler->load_recipe( 10 );

		$this->assertStringNotContainsString( '<', $data['description'] );
		$this->assertStringContainsString( 'A rich cake.', $data['description'] );
	}

	public function test_load_recipe_includes_servings(): void {
		$this->create_recipe( 10, 'Cake', [ 'wprm_servings' => '4' ] );
		$data = $this->handler->load_recipe( 10 );

		$this->assertStringContainsString( '4', $data['description'] );
	}

	public function test_load_recipe_includes_servings_unit(): void {
		$this->create_recipe( 10, 'Cake', [
			'wprm_servings'      => '4',
			'wprm_servings_unit' => 'persons',
		] );
		$data = $this->handler->load_recipe( 10 );

		$this->assertStringContainsString( '4 persons', $data['description'] );
	}

	public function test_load_recipe_includes_times(): void {
		$this->create_recipe( 10, 'Cake', [
			'wprm_prep_time'  => '15',
			'wprm_cook_time'  => '30',
			'wprm_total_time' => '45',
		] );
		$data = $this->handler->load_recipe( 10 );

		$this->assertStringContainsString( '15min', $data['description'] );
		$this->assertStringContainsString( '30min', $data['description'] );
		$this->assertStringContainsString( '45min', $data['description'] );
	}

	public function test_load_recipe_omits_empty_meta(): void {
		$this->create_recipe( 10, 'Cake', [ 'wprm_summary' => 'A simple cake.' ] );
		$data = $this->handler->load_recipe( 10 );

		// Only summary, no info line.
		$this->assertSame( 'A simple cake.', $data['description'] );
	}

	public function test_load_recipe_handles_no_meta(): void {
		$this->create_recipe( 10 );
		$data = $this->handler->load_recipe( 10 );

		$this->assertSame( '', $data['description'] );
	}

	// ─── load_recipe: not found ─────────────────────────────

	public function test_load_recipe_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_recipe( 999 ) );
	}

	public function test_load_recipe_empty_for_wrong_post_type(): void {
		$post              = new \stdClass();
		$post->ID          = 10;
		$post->post_title  = 'Not a recipe';
		$post->post_type   = 'post';
		$post->post_status = 'publish';
		$post->post_date   = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][10] = $post;

		$this->assertSame( [], $this->handler->load_recipe( 10 ) );
	}
}
