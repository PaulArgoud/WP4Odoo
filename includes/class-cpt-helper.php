<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for Custom Post Type registration, loading, and saving.
 *
 * Used by Sales_Module and WooCommerce_Module for order/invoice CPTs.
 *
 * @package WP4Odoo
 * @since   1.5.0
 */
final class CPT_Helper {

	/**
	 * Register a custom post type with standard WP4Odoo settings.
	 *
	 * @param string               $post_type CPT slug.
	 * @param array<string, string> $labels   CPT labels.
	 * @return void
	 */
	public static function register( string $post_type, array $labels ): void {
		register_post_type(
			$post_type,
			[
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wp4odoo',
				'supports'        => [ 'title' ],
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			]
		);
	}

	/**
	 * Load CPT data by post type and meta fields.
	 *
	 * @param int                   $wp_id       Post ID.
	 * @param string                $post_type   Expected post type.
	 * @param array<string, string> $meta_fields Meta field map: data key => meta key.
	 * @return array<string, mixed>
	 */
	public static function load( int $wp_id, string $post_type, array $meta_fields ): array {
		$post = get_post( $wp_id );
		if ( ! $post || $post_type !== $post->post_type ) {
			return [];
		}

		$data = [ 'post_title' => $post->post_title ];

		foreach ( $meta_fields as $key => $meta_key ) {
			$data[ $key ] = get_post_meta( $wp_id, $meta_key, true );
		}

		return $data;
	}

	/**
	 * Save CPT data with Many2one resolution and meta fields.
	 *
	 * @param array<string, mixed>  $data          Mapped data.
	 * @param int                   $wp_id         Existing post ID (0 to create).
	 * @param string                $post_type     CPT slug.
	 * @param array<string, string> $meta_fields   Meta field map: data key => meta key.
	 * @param string                $default_title Fallback post title.
	 * @param Logger|null           $logger        Optional logger for error reporting.
	 * @return int Post ID or 0 on failure.
	 */
	public static function save( array $data, int $wp_id, string $post_type, array $meta_fields, string $default_title, ?Logger $logger = null ): int {
		// Resolve partner_id from Many2one.
		if ( isset( $data['_wp4odoo_partner_id'] ) && is_array( $data['_wp4odoo_partner_id'] ) ) {
			$data['_wp4odoo_partner_id'] = Field_Mapper::many2one_to_id( $data['_wp4odoo_partner_id'] );
		}

		$post_data = [
			'post_type'   => $post_type,
			'post_title'  => $data['post_title'] ?? $default_title,
			'post_status' => 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_data['ID'] = $wp_id;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			if ( $logger ) {
				$logger->error( "Failed to save {$post_type} post.", [ 'error' => $result->get_error_message() ] );
			}
			return 0;
		}

		$post_id = (int) $result;

		foreach ( $meta_fields as $key => $meta_key ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $post_id, $meta_key, $data[ $key ] );
			}
		}

		return $post_id;
	}
}
