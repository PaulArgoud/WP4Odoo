<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles product image import from Odoo (image_1920 → WC featured image).
 *
 * Decodes base64 image data from Odoo, creates a WordPress media library
 * attachment, and sets it as the WooCommerce product thumbnail.
 * Tracks image changes via a SHA-256 hash stored in post meta to avoid
 * re-downloading unchanged images.
 *
 * @package WP4Odoo
 * @since   1.6.0
 */
class Image_Handler {

	/**
	 * Post meta key for the image content hash.
	 *
	 * @var string
	 */
	private const IMAGE_HASH_META = '_wp4odoo_image_hash';

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Import the featured image for a WooCommerce product from Odoo data.
	 *
	 * Compares a SHA-256 hash of the base64 image data against the stored
	 * hash in post meta. If unchanged, skips the import. If changed or new,
	 * decodes the base64, writes to the uploads directory, creates a WP
	 * attachment, and sets it as the product thumbnail.
	 *
	 * @param int    $wp_product_id The WooCommerce product ID.
	 * @param mixed  $image_data    The Odoo image_1920 value (base64 string or false).
	 * @param string $product_name  Product name for the image filename.
	 * @return bool True if image was processed (set or cleared), false on skip or error.
	 */
	public function import_featured_image( int $wp_product_id, mixed $image_data, string $product_name = '' ): bool {
		// Handle missing/empty image: clear thumbnail if it was set by us.
		if ( empty( $image_data ) ) {
			return $this->maybe_clear_thumbnail( $wp_product_id );
		}

		if ( ! is_string( $image_data ) ) {
			$this->logger->warning(
				'Unexpected image_1920 type.',
				[
					'wp_product_id' => $wp_product_id,
					'type'          => gettype( $image_data ),
				]
			);
			return false;
		}

		// Hash the base64 data to detect changes.
		$new_hash = hash( 'sha256', $image_data );
		$old_hash = get_post_meta( $wp_product_id, self::IMAGE_HASH_META, true );

		if ( $new_hash === $old_hash ) {
			return false;
		}

		// Decode base64.
		$decoded = base64_decode( $image_data, true );
		if ( false === $decoded || '' === $decoded ) {
			$this->logger->error(
				'Failed to decode base64 image data.',
				[
					'wp_product_id' => $wp_product_id,
				]
			);
			return false;
		}

		// Detect MIME type from content.
		$mime_type = $this->detect_mime_type( $decoded );
		$extension = $this->mime_to_extension( $mime_type );

		// Build a clean filename.
		$filename = $this->build_filename( $product_name, $wp_product_id, $extension );

		// Create the attachment.
		$attachment_id = $this->create_attachment( $decoded, $filename, $mime_type, $wp_product_id );

		if ( 0 === $attachment_id ) {
			return false;
		}

		// Delete previous Odoo-sourced thumbnail if it exists.
		$this->delete_previous_thumbnail( $wp_product_id );

		// Set as product thumbnail.
		set_post_thumbnail( $wp_product_id, $attachment_id );

		// Store the hash for future comparisons.
		update_post_meta( $wp_product_id, self::IMAGE_HASH_META, $new_hash );

		$this->logger->info(
			'Product image imported from Odoo.',
			[
				'wp_product_id' => $wp_product_id,
				'attachment_id' => $attachment_id,
			]
		);

		return true;
	}

	/**
	 * Clear the product thumbnail if it was set by Odoo sync.
	 *
	 * Only clears if the image hash meta exists (indicating the current
	 * thumbnail was set by this handler).
	 *
	 * @param int $wp_product_id Product ID.
	 * @return bool True if thumbnail was cleared.
	 */
	private function maybe_clear_thumbnail( int $wp_product_id ): bool {
		$old_hash = get_post_meta( $wp_product_id, self::IMAGE_HASH_META, true );

		if ( empty( $old_hash ) ) {
			return false;
		}

		$this->delete_previous_thumbnail( $wp_product_id );
		delete_post_thumbnail( $wp_product_id );
		delete_post_meta( $wp_product_id, self::IMAGE_HASH_META );

		$this->logger->info(
			'Cleared product thumbnail (Odoo image removed).',
			[
				'wp_product_id' => $wp_product_id,
			]
		);

		return true;
	}

	/**
	 * Delete the previous thumbnail attachment if it was created by this handler.
	 *
	 * Only deletes if the image hash meta exists on the product
	 * (avoids deleting manually-uploaded images).
	 *
	 * @param int $wp_product_id Product ID.
	 * @return void
	 */
	private function delete_previous_thumbnail( int $wp_product_id ): void {
		$existing_thumb_id = (int) get_post_thumbnail_id( $wp_product_id );

		if ( $existing_thumb_id > 0 ) {
			$old_hash = get_post_meta( $wp_product_id, self::IMAGE_HASH_META, true );
			if ( ! empty( $old_hash ) ) {
				wp_delete_attachment( $existing_thumb_id, true );
			}
		}
	}

	/**
	 * Detect MIME type from binary image data.
	 *
	 * @param string $data Binary image data.
	 * @return string MIME type.
	 */
	private function detect_mime_type( string $data ): string {
		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$mime  = $finfo->buffer( $data );

		if ( false === $mime || '' === $mime ) {
			return 'image/png';
		}

		return $mime;
	}

	/**
	 * Map MIME type to file extension.
	 *
	 * @param string $mime_type MIME type.
	 * @return string File extension (without dot).
	 */
	private function mime_to_extension( string $mime_type ): string {
		return match ( $mime_type ) {
			'image/jpeg' => 'jpg',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			default      => 'png',
		};
	}

	/**
	 * Build a sanitized filename for the image attachment.
	 *
	 * @param string $product_name Product name.
	 * @param int    $wp_product_id Product ID (fallback).
	 * @param string $extension     File extension.
	 * @return string Filename.
	 */
	private function build_filename( string $product_name, int $wp_product_id, string $extension ): string {
		if ( '' !== $product_name ) {
			$slug = sanitize_title( $product_name );
		} else {
			$slug = 'product-' . $wp_product_id;
		}

		return $slug . '-odoo.' . $extension;
	}

	/**
	 * Create a WordPress media library attachment from binary image data.
	 *
	 * Writes data to the uploads directory, then uses wp_insert_attachment()
	 * and wp_generate_attachment_metadata() for proper integration.
	 *
	 * @param string $data          Binary image data.
	 * @param string $filename      Desired filename.
	 * @param string $mime_type     MIME type.
	 * @param int    $wp_product_id Parent post ID.
	 * @return int Attachment ID, or 0 on failure.
	 */
	private function create_attachment( string $data, string $filename, string $mime_type, int $wp_product_id ): int {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			$this->logger->error(
				'Upload directory not available.',
				[
					'error' => $upload_dir['error'],
				]
			);
			return 0;
		}

		$file_path = trailingslashit( $upload_dir['path'] ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to a temporary file, WP_Filesystem not available during cron.
		$bytes_written = file_put_contents( $file_path, $data );

		if ( false === $bytes_written ) {
			$this->logger->error(
				'Failed to write image file.',
				[
					'file_path' => $file_path,
				]
			);
			return 0;
		}

		$attachment = [
			'guid'           => trailingslashit( $upload_dir['url'] ) . $filename,
			'post_mime_type' => $mime_type,
			'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		/** @var int|\WP_Error $attachment_id */
		$attachment_id = wp_insert_attachment( $attachment, $file_path, $wp_product_id );

		if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
			$this->logger->error(
				'Failed to create attachment.',
				[
					'filename' => $filename,
				]
			);
			wp_delete_file( $file_path );
			return 0;
		}

		// Load image.php for wp_generate_attachment_metadata if needed.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}
}
