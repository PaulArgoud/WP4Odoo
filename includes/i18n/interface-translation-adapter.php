<?php
declare( strict_types=1 );

namespace WP4Odoo\I18n;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation adapter interface.
 *
 * Abstracts the differences between WPML and Polylang so that
 * Translation_Service can work with either plugin transparently.
 *
 * Both plugins use a "one post per language" model where each
 * translation is a separate WP post linked to the original.
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
interface Translation_Adapter {

	/**
	 * Get the default site language code (e.g. 'en').
	 *
	 * @return string Two-letter language code.
	 */
	public function get_default_language(): string;

	/**
	 * Get all active language codes.
	 *
	 * @return array<int, string> E.g. ['en', 'fr', 'es'].
	 */
	public function get_active_languages(): array;

	/**
	 * Get translation post IDs for a given post.
	 *
	 * Returns language code => post ID pairs, excluding the
	 * original (source) post itself.
	 *
	 * @param int $post_id Source post ID.
	 * @return array<string, int> Language code => translated post ID.
	 */
	public function get_translations( int $post_id ): array;

	/**
	 * Get the language code for a specific post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Two-letter language code, or empty string if unknown.
	 */
	public function get_post_language( int $post_id ): string;

	/**
	 * Get the original (source) post ID for a translated post.
	 *
	 * If the post is already the original, returns the same ID.
	 *
	 * @param int $post_id Post ID (original or translation).
	 * @return int Original post ID.
	 */
	public function get_original_post_id( int $post_id ): int;

	/**
	 * Check if a post is a translation (not the original).
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if the post is a translation of another post.
	 */
	public function is_translation( int $post_id ): bool;

	// ─── Write methods (Phase 4: pull direction) ────────────

	/**
	 * Create a translated post linked to an original.
	 *
	 * Returns the existing translation ID if one already exists
	 * for the given language (idempotent).
	 *
	 * @param int    $original_post_id Original (source) post ID.
	 * @param string $lang             Target language code (e.g. 'fr').
	 * @param string $post_type        WP post type (e.g. 'product').
	 * @return int Translated post ID, or 0 on failure.
	 */
	public function create_translation( int $original_post_id, string $lang, string $post_type ): int;

	/**
	 * Set the language for a post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $lang      Language code (e.g. 'fr').
	 * @param string $post_type WP post type (e.g. 'product').
	 * @return void
	 */
	public function set_post_language( int $post_id, string $lang, string $post_type ): void;

	/**
	 * Link posts as translations of each other.
	 *
	 * @param array<string, int> $translations Language code => post ID map
	 *                                         (must include all languages).
	 * @return void
	 */
	public function link_translations( array $translations ): void;

	// ─── Term translations (Phase 6 prep) ───────────────────

	/**
	 * Get translations for a taxonomy term.
	 *
	 * Returns language code => translated term ID pairs, excluding
	 * the original term itself.
	 *
	 * @since 3.1.0
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name (e.g. 'product_cat').
	 * @return array<string, int> Language code => translated term ID.
	 */
	public function get_term_translations( int $term_id, string $taxonomy ): array;

	/**
	 * Create a translated term linked to an original.
	 *
	 * Returns the existing translation ID if one already exists
	 * for the given language (idempotent).
	 *
	 * @since 3.1.0
	 *
	 * @param int    $original_term_id Original term ID.
	 * @param string $lang             Target language code (e.g. 'fr').
	 * @param string $taxonomy         Taxonomy name (e.g. 'product_cat').
	 * @return int Translated term ID, or 0 on failure.
	 */
	public function create_term_translation( int $original_term_id, string $lang, string $taxonomy ): int;
}
