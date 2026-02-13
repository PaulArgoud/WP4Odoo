<?php
declare( strict_types=1 );

namespace WP4Odoo\I18n;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Polylang translation adapter.
 *
 * Implements Translation_Adapter using Polylang's function API:
 *   - pll_default_language()
 *   - pll_languages_list()
 *   - pll_get_post_translations()
 *   - pll_get_post_language()
 *   - pll_get_post()
 *
 * Detection: `defined('POLYLANG_VERSION') && function_exists('pll_default_language')`
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
class Polylang_Adapter implements Translation_Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function get_default_language(): string {
		return pll_default_language();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_active_languages(): array {
		return pll_languages_list();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_translations( int $post_id ): array {
		$all = pll_get_post_translations( $post_id );

		$self_lang = $this->get_post_language( $post_id );
		$result    = [];

		foreach ( $all as $lang => $trans_id ) {
			$trans_id = (int) $trans_id;

			// Exclude the source post itself.
			if ( $trans_id === $post_id || $lang === $self_lang ) {
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
		$lang = pll_get_post_language( $post_id );
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_original_post_id( int $post_id ): int {
		$default_lang = $this->get_default_language();
		$original     = pll_get_post( $post_id, $default_lang );

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
		$existing = pll_get_post_translations( $original_post_id );
		if ( isset( $existing[ $lang ] ) ) {
			return (int) $existing[ $lang ];
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

		// Set the language for the new post.
		pll_set_post_language( $new_id, $lang );

		// Build the full translation group (original + existing + new).
		$default_lang                      = $this->get_default_language();
		$all_translations                  = $existing;
		$all_translations[ $default_lang ] = $original_post_id;
		$all_translations[ $lang ]         = $new_id;

		pll_save_post_translations( $all_translations );

		return $new_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function set_post_language( int $post_id, string $lang, string $post_type ): void {
		pll_set_post_language( $post_id, $lang );
	}

	/**
	 * {@inheritDoc}
	 */
	public function link_translations( array $translations ): void {
		pll_save_post_translations( $translations );
	}

	// ─── Term translations (Phase 6) ────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * Uses Polylang's `pll_get_term_translations()` to retrieve
	 * all translated term IDs for a given term.
	 *
	 * @since 3.1.0
	 */
	public function get_term_translations( int $term_id, string $taxonomy ): array {
		$all       = pll_get_term_translations( $term_id );
		$self_lang = pll_get_term_language( $term_id );
		$result    = [];

		foreach ( $all as $lang => $trans_id ) {
			$trans_id = (int) $trans_id;

			// Exclude the source term itself.
			if ( $trans_id === $term_id || $lang === $self_lang ) {
				continue;
			}

			$result[ $lang ] = $trans_id;
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Creates a placeholder term and links it to the original via
	 * Polylang's `pll_save_term_translations()`.
	 *
	 * @since 3.1.0
	 */
	public function create_term_translation( int $original_term_id, string $lang, string $taxonomy ): int {
		// Check if a translation already exists.
		$existing = pll_get_term_translations( $original_term_id );
		if ( isset( $existing[ $lang ] ) ) {
			return (int) $existing[ $lang ];
		}

		// Create a placeholder term.
		$result = wp_insert_term( '(translation)', $taxonomy );
		if ( is_wp_error( $result ) ) {
			return 0;
		}

		$new_term_id = (int) $result['term_id'];

		// Set the language for the new term.
		pll_set_term_language( $new_term_id, $lang );

		// Build the full translation group (original + existing + new).
		$default_lang                      = $this->get_default_language();
		$all_translations                  = $existing;
		$all_translations[ $default_lang ] = $original_term_id;
		$all_translations[ $lang ]         = $new_term_id;

		pll_save_term_translations( $all_translations );

		return $new_term_id;
	}
}
