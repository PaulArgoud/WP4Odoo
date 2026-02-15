<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Image_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Image_Handler.
 *
 * Tests image import logic: edge cases, MIME detection, hash comparison,
 * and successful import with valid image data.
 */
class ImageHandlerTest extends TestCase {

	private Image_Handler $handler;

	/** @var string[] Files created during tests (for cleanup). */
	private array $temp_files = [];

	protected function setUp(): void {
		$this->handler    = new Image_Handler( new Logger( 'woocommerce' ) );
		$this->temp_files = [];
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				@unlink( $file );
			}
		}
	}

	// ─── Instantiation ──────────────────────────────────────

	public function test_can_be_instantiated(): void {
		$this->assertInstanceOf( Image_Handler::class, $this->handler );
	}

	// ─── Empty / false image data ───────────────────────────

	public function test_returns_false_for_false_image(): void {
		$this->assertFalse( $this->handler->import_featured_image( 100, false ) );
	}

	public function test_returns_false_for_empty_string(): void {
		$this->assertFalse( $this->handler->import_featured_image( 100, '' ) );
	}

	public function test_returns_false_for_null(): void {
		$this->assertFalse( $this->handler->import_featured_image( 100, null ) );
	}

	// ─── Invalid types ──────────────────────────────────────

	public function test_returns_false_for_integer_data(): void {
		$this->assertFalse( $this->handler->import_featured_image( 100, 42 ) );
	}

	public function test_returns_false_for_array_data(): void {
		$this->assertFalse( $this->handler->import_featured_image( 100, [ 'data' ] ) );
	}

	// ─── Invalid base64 ─────────────────────────────────────

	public function test_returns_false_for_invalid_base64(): void {
		$this->assertFalse( $this->handler->import_featured_image( 100, '!!!invalid!!!', 'Test' ) );
	}

	// ─── Valid image imports ─────────────────────────────────

	public function test_processes_valid_png(): void {
		$png_data = $this->create_minimal_png();
		$base64   = base64_encode( $png_data );

		// Track the file that will be created.
		$this->register_temp_file( 'test-product-odoo.png' );

		$result = $this->handler->import_featured_image( 200, $base64, 'Test Product' );
		$this->assertTrue( $result );
	}

	public function test_processes_valid_jpeg(): void {
		$jpeg_data = $this->create_minimal_jpeg();
		$base64    = base64_encode( $jpeg_data );

		$this->register_temp_file( 'jpeg-product-odoo.jpg' );

		$result = $this->handler->import_featured_image( 201, $base64, 'JPEG Product' );
		$this->assertTrue( $result );
	}

	// ─── Helpers ─────────────────────────────────────────────

	/**
	 * Create a minimal valid PNG binary (1x1 pixel).
	 */
	private function create_minimal_png(): string {
		$img = imagecreatetruecolor( 1, 1 );
		ob_start();
		imagepng( $img );
		$data = ob_get_clean();
		imagedestroy( $img );
		return $data ?: '';
	}

	/**
	 * Create a minimal valid JPEG binary (1x1 pixel).
	 */
	private function create_minimal_jpeg(): string {
		$img = imagecreatetruecolor( 1, 1 );
		ob_start();
		imagejpeg( $img );
		$data = ob_get_clean();
		imagedestroy( $img );
		return $data ?: '';
	}

	/**
	 * Register a filename for cleanup (in the temp dir used by wp_upload_dir stub).
	 */
	private function register_temp_file( string $filename ): void {
		$this->temp_files[] = trailingslashit( sys_get_temp_dir() ) . $filename;
	}

	// ─── Gallery: import ────────────────────────────────────

	public function test_import_gallery_returns_zero_for_empty_data(): void {
		$this->assertSame( 0, $this->handler->import_gallery( 100, [] ) );
	}

	public function test_import_gallery_skips_invalid_records(): void {
		$result = $this->handler->import_gallery( 100, [
			[ 'name' => 'bad', 'image' => '' ],
			[ 'name' => 'bad2', 'image' => 42 ],
		] );

		$this->assertSame( 0, $result );
	}

	public function test_import_gallery_processes_valid_images(): void {
		$png    = $this->create_minimal_png();
		$base64 = base64_encode( $png );

		$this->register_temp_file( 'gallery-100-0-odoo.png' );

		$result = $this->handler->import_gallery( 100, [
			[ 'name' => 'Gallery Image', 'image' => $base64 ],
		] );

		$this->assertSame( 1, $result );
	}

	public function test_import_gallery_skips_unchanged_images(): void {
		$png    = $this->create_minimal_png();
		$base64 = base64_encode( $png );
		$hash   = hash( 'sha256', $base64 );

		$this->register_temp_file( 'gallery-100-0-odoo.png' );

		// First import — creates attachment.
		$this->handler->import_gallery( 100, [
			[ 'name' => 'Image', 'image' => $base64 ],
		] );

		// Second import with same data — should skip (0 imported).
		$result = $this->handler->import_gallery( 100, [
			[ 'name' => 'Image', 'image' => $base64 ],
		] );

		$this->assertSame( 0, $result );
	}

	// ─── Gallery: export ────────────────────────────────────

	public function test_export_gallery_returns_empty_when_no_gallery(): void {
		$this->assertSame( [], $this->handler->export_gallery( 100 ) );
	}

	public function test_export_gallery_builds_one2many_tuples(): void {
		// Create a temp file to simulate an attachment.
		$png  = $this->create_minimal_png();
		$path = trailingslashit( sys_get_temp_dir() ) . 'export-test.png';
		file_put_contents( $path, $png );
		$this->temp_files[] = $path;

		// Simulate a WC product with gallery meta and attached file.
		$GLOBALS['_wp_post_meta'][300] = [
			'_product_image_gallery' => '500',
		];
		$GLOBALS['_wp_attached_files'][500] = $path;
		$GLOBALS['_wp_posts'][500] = (object) [
			'ID'         => 500,
			'post_title' => 'Gallery Export Image',
			'post_type'  => 'attachment',
		];

		$tuples = $this->handler->export_gallery( 300 );

		$this->assertNotEmpty( $tuples );
		$this->assertSame( 0, $tuples[0][0] );
		$this->assertSame( 0, $tuples[0][1] );
		$this->assertSame( 'Gallery Export Image', $tuples[0][2]['name'] );
		$this->assertNotEmpty( $tuples[0][2]['image'] );
	}
}
