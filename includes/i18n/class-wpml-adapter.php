<?php
declare( strict_types=1 );

namespace WP4Odoo\I18n;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPML translation adapter.
 *
 * Implements Translation_Adapter using WPML's filter-based API:
 *   - wpml_default_language
 *   - wpml_active_languages
 *   - wpml_get_element_translations
 *   - wpml_post_language_details
 *   - wpml_object_id
 *
 * Detection: `defined('ICL_SITEPRESS_VERSION') && class_exists('SitePress')`
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
class WPML_Adapter implements Translation_Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function get_default_language(): string {
		/** @var string $lang */
		$lang = apply_filters( 'wpml_default_language', '' );
		return $lang;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_active_languages(): array {
		/** @var array<string, array<string, mixed>> $languages */
		$languages = apply_filters( 'wpml_active_languages', [], [ 'skip_missing' => 0 ] );

		return array_keys( $languages );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_translations( int $post_id ): array {
		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return [];
		}

		$element_type = 'post_' . $post_type;

		/** @var \stdClass[]|null $translations */
		$translations = apply_filters( 'wpml_get_element_translations', null, $this->get_trid( $post_id, $element_type ), $element_type );

		if ( ! is_array( $translations ) ) {
			return [];
		}

		$default_lang = $this->get_default_language();
		$result       = [];

		foreach ( $translations as $translation ) {
			if ( ! isset( $translation->language_code, $translation->element_id ) ) {
				continue;
			}

			$lang     = $translation->language_code;
			$trans_id = (int) $translation->element_id;

			// Exclude the original (source) post.
			if ( $lang === $default_lang || $trans_id === $post_id ) {
				continue;
			}

			$result[ $lang ] = $trans_id;
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_post_language( int $post_id ): string {
		/** @var array<string, mixed>|null $details */
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );

		if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
			return (string) $details['language_code'];
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_original_post_id( int $post_id ): int {
		$default_lang = $this->get_default_language();
		$post_type    = get_post_type( $post_id );

		if ( ! $post_type ) {
			return $post_id;
		}

		/** @var int|null $original */
		$original = apply_filters( 'wpml_object_id', $post_id, $post_type, true, $default_lang );

		return $original ? (int) $original : $post_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_translation( int $post_id ): bool {
		return $this->get_original_post_id( $post_id ) !== $post_id;
	}

	// ─── Write methods (Phase 4: pull direction) ────────────

	/**
	 * {@inheritDoc}
	 */
	public function create_translation( int $original_post_id, string $lang, string $post_type ): int {
		// Check if a translation already exists.
		$existing = $this->get_translation_in_language( $original_post_id, $lang, $post_type );
		if ( $existing > 0 ) {
			return $existing;
		}

		// Create a new post as a placeholder for the translation.
		$new_id = wp_insert_post(
			[
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => '(translation)',
			]
		);

		if ( $new_id <= 0 ) {
			return 0;
		}

		// Link to the original post's TRID.
		$element_type = 'post_' . $post_type;
		$trid         = $this->get_trid( $original_post_id, $element_type );

		if ( ! $trid ) {
			return 0;
		}

		do_action(
			'wpml_set_element_language_details',
			[
				'element_id'    => $new_id,
				'element_type'  => $element_type,
				'trid'          => $trid,
				'language_code' => $lang,
			]
		);

		return $new_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_post_language( int $post_id, string $lang, string $post_type ): void {
		$element_type = 'post_' . $post_type;

		do_action(
			'wpml_set_element_language_details',
			[
				'element_id'    => $post_id,
				'element_type'  => $element_type,
				'language_code' => $lang,
			]
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * WPML links translations implicitly via TRID during create_translation(),
	 * so this is a no-op.
	 */
	public function link_translations( array $translations ): void {
		// No-op: WPML uses TRID-based linking (implicit in create_translation).
	}

	// ─── Term translations (Phase 6 prep) ───────────────────

	/**
	 * {@inheritDoc}
	 *
	 * @since 3.1.0
	 */
	public function get_term_translations( int $term_id, string $taxonomy ): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 3.1.0
	 */
	public function create_term_translation( int $original_term_id, string $lang, string $taxonomy ): int {
		return 0;
	}

	/**
	 * Get the WPML translation group ID (trid) for a post.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $element_type WPML element type (e.g. 'post_product').
	 * @return int|null Translation group ID.
	 */
	private function get_trid( int $post_id, string $element_type ): ?int {
		/** @var int|null $trid */
		$trid = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
		return $trid;
	}

	/**
	 * Get the translated post ID for a specific language.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $lang      Target language code.
	 * @param string $post_type WP post type.
	 * @return int Translated post ID, or 0 if not found.
	 */
	private function get_translation_in_language( int $post_id, string $lang, string $post_type ): int {
		/** @var int|null $translated */
		$translated = apply_filters( 'wpml_object_id', $post_id, $post_type, false, $lang );

		return ( $translated && $translated !== $post_id ) ? (int) $translated : 0;
	}
}
