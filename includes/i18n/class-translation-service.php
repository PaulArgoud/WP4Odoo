<?php
declare( strict_types=1 );

namespace WP4Odoo\I18n;

use WP4Odoo\API\Odoo_Client;
use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation Service — cross-cutting service for multilingual sync.
 *
 * Detects the active translation plugin (WPML or Polylang), maps
 * WordPress language codes to Odoo locales, and pushes translated
 * field values to Odoo using the appropriate method for the Odoo
 * version (context-based for 16+, ir.translation for 14-15).
 *
 * This is not a module but a shared service, lazy-instantiated
 * from Module_Helpers (same pattern as Partner_Service).
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
class Translation_Service {

	/**
	 * Closure that returns the shared Odoo_Client instance.
	 *
	 * @var \Closure(): Odoo_Client
	 */
	private \Closure $client_getter;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Cached adapter instance (null = not yet detected, false = none available).
	 *
	 * @var Translation_Adapter|false|null
	 */
	private Translation_Adapter|false|null $adapter = null;

	/**
	 * In-memory cache for has_ir_translation() result.
	 *
	 * @var bool|null
	 */
	private ?bool $has_ir_translation_cache = null;

	/**
	 * WordPress 2-letter language code → Odoo locale mapping.
	 *
	 * Covers all major Odoo-supported languages. Filterable via
	 * 'wp4odoo_odoo_locale' for custom mappings.
	 *
	 * @var array<string, string>
	 */
	private const LOCALE_MAP = [
		'en' => 'en_US',
		'fr' => 'fr_FR',
		'es' => 'es_ES',
		'de' => 'de_DE',
		'it' => 'it_IT',
		'pt' => 'pt_BR',
		'nl' => 'nl_NL',
		'ru' => 'ru_RU',
		'zh' => 'zh_CN',
		'ja' => 'ja_JP',
		'ko' => 'ko_KR',
		'ar' => 'ar_001',
		'pl' => 'pl_PL',
		'sv' => 'sv_SE',
		'da' => 'da_DK',
		'nb' => 'nb_NO',
		'fi' => 'fi_FI',
		'cs' => 'cs_CZ',
		'tr' => 'tr_TR',
		'uk' => 'uk_UA',
		'ro' => 'ro_RO',
		'hu' => 'hu_HU',
		'el' => 'el_GR',
		'he' => 'he_IL',
		'th' => 'th_TH',
		'vi' => 'vi_VN',
		'id' => 'id_ID',
		'ca' => 'ca_ES',
		'hr' => 'hr_HR',
		'bg' => 'bg_BG',
		'sk' => 'sk_SK',
		'sl' => 'sl_SI',
		'et' => 'et_EE',
		'lv' => 'lv_LV',
		'lt' => 'lt_LT',
	];

	/**
	 * Transient key for ir.translation model probe.
	 *
	 * @var string
	 */
	private const TRANSIENT_IR_TRANSLATION = 'wp4odoo_has_ir_translation';

	/**
	 * Transient key for active Odoo language codes.
	 *
	 * @var string
	 */
	private const TRANSIENT_ODOO_LANGS = 'wp4odoo_odoo_active_langs';

	/**
	 * Constructor.
	 *
	 * @param \Closure $client_getter Returns the shared Odoo_Client instance.
	 */
	public function __construct( \Closure $client_getter ) {
		$this->client_getter = $client_getter;
		$this->logger        = new Logger( 'i18n' );
	}

	// ─── Adapter detection ──────────────────────────────────

	/**
	 * Detect the active translation adapter.
	 *
	 * WPML is checked first (higher market share), then Polylang.
	 * Returns null if neither plugin is active.
	 *
	 * @return Translation_Adapter|null
	 */
	public function get_adapter(): ?Translation_Adapter {
		if ( null === $this->adapter ) {
			$this->adapter = $this->detect_adapter();
		}

		return $this->adapter instanceof Translation_Adapter ? $this->adapter : null;
	}

	/**
	 * Check if any translation plugin is active.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return null !== $this->get_adapter();
	}

	/**
	 * Detect which adapter to use.
	 *
	 * @return Translation_Adapter|false Adapter instance, or false if none.
	 */
	private function detect_adapter(): Translation_Adapter|false {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'SitePress' ) ) {
			return new WPML_Adapter();
		}

		if ( defined( 'POLYLANG_VERSION' ) && function_exists( 'pll_default_language' ) ) {
			return new Polylang_Adapter();
		}

		return false;
	}

	// ─── Language detection ────────────────────────────────

	/**
	 * Detect languages from the WP translation plugin and check Odoo availability.
	 *
	 * Returns structured data with detected plugin name, default language,
	 * and per-language Odoo availability (probed via res.lang).
	 *
	 * @since 3.0.5
	 *
	 * @return array{
	 *     plugin: string,
	 *     default_language: string,
	 *     languages: array<string, array{code: string, odoo_locale: string, odoo_available: bool}>
	 * }|null Null if no translation adapter is available.
	 */
	public function detect_languages(): ?array {
		$adapter = $this->get_adapter();
		if ( ! $adapter ) {
			return null;
		}

		// Determine plugin name.
		$plugin = 'Unknown';
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && $adapter instanceof WPML_Adapter ) {
			$plugin = 'WPML';
		} elseif ( defined( 'POLYLANG_VERSION' ) && $adapter instanceof Polylang_Adapter ) {
			$plugin = 'Polylang';
		}

		$default_lang = $adapter->get_default_language();
		$active_langs = $adapter->get_active_languages();
		$odoo_locales = $this->get_odoo_active_locales();
		$languages    = [];

		foreach ( $active_langs as $lang ) {
			$odoo_locale        = $this->wp_to_odoo_locale( $lang );
			$languages[ $lang ] = [
				'code'           => $lang,
				'odoo_locale'    => $odoo_locale,
				'odoo_available' => in_array( $odoo_locale, $odoo_locales, true ),
			];
		}

		return [
			'plugin'           => $plugin,
			'default_language' => $default_lang,
			'languages'        => $languages,
		];
	}

	/**
	 * Get active language codes from the connected Odoo instance.
	 *
	 * Probes `res.lang` with `active=true`, cached via transient (1 hour).
	 *
	 * @since 3.0.5
	 *
	 * @return array<int, string> Odoo locale codes (e.g. ['en_US', 'fr_FR']).
	 */
	private function get_odoo_active_locales(): array {
		$cached = get_transient( self::TRANSIENT_ODOO_LANGS );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$records = $this->client()->search_read(
				'res.lang',
				[ [ 'active', '=', true ] ],
				[ 'code' ]
			);
			$locales = array_column( $records, 'code' );
		} catch ( \Exception $e ) {
			$this->logger->warning( 'Could not probe Odoo languages.', [ 'error' => $e->getMessage() ] );
			$locales = [];
		}

		set_transient( self::TRANSIENT_ODOO_LANGS, $locales, HOUR_IN_SECONDS );

		return $locales;
	}

	// ─── Locale mapping ─────────────────────────────────────

	/**
	 * Map a WordPress language code to an Odoo locale.
	 *
	 * Uses the static LOCALE_MAP, with a fallback to {lang}_{LANG}
	 * format. Filterable via 'wp4odoo_odoo_locale'.
	 *
	 * @param string $wp_lang WordPress 2-letter language code (e.g. 'fr').
	 * @return string Odoo locale (e.g. 'fr_FR').
	 */
	public function wp_to_odoo_locale( string $wp_lang ): string {
		$wp_lang = strtolower( substr( $wp_lang, 0, 2 ) );

		$locale = self::LOCALE_MAP[ $wp_lang ] ?? ( $wp_lang . '_' . strtoupper( $wp_lang ) );

		/**
		 * Filter the Odoo locale for a WordPress language code.
		 *
		 * @since 3.0.0
		 *
		 * @param string $locale  The resolved Odoo locale.
		 * @param string $wp_lang The WordPress language code.
		 */
		return (string) apply_filters( 'wp4odoo_odoo_locale', $locale, $wp_lang );
	}

	// ─── Odoo version detection ─────────────────────────────

	/**
	 * Check if the Odoo instance uses ir.translation (Odoo < 16).
	 *
	 * Odoo 16+ replaced ir.translation with JSONB inline columns.
	 * Cached in-memory and via transient (1 hour).
	 *
	 * @return bool True if ir.translation model exists (Odoo 14-15).
	 */
	public function has_ir_translation(): bool {
		if ( null !== $this->has_ir_translation_cache ) {
			return $this->has_ir_translation_cache;
		}

		$cached = get_transient( self::TRANSIENT_IR_TRANSLATION );
		if ( false !== $cached ) {
			$this->has_ir_translation_cache = (bool) $cached;
			return $this->has_ir_translation_cache;
		}

		try {
			$count  = $this->client()->search_count(
				'ir.model',
				[ [ 'model', '=', 'ir.translation' ] ]
			);
			$result = $count > 0;
		} catch ( \Exception $e ) {
			$this->logger->warning( 'Could not probe ir.translation model.', [ 'error' => $e->getMessage() ] );
			$result = false;
		}

		set_transient( self::TRANSIENT_IR_TRANSLATION, $result ? 1 : 0, HOUR_IN_SECONDS );
		$this->has_ir_translation_cache = $result;

		return $result;
	}

	// ─── Pull translations (batch) ──────────────────────────

	/**
	 * Pull translated field values from Odoo for a batch of records
	 * and apply them to WPML/Polylang translated WP posts.
	 *
	 * For each secondary language, makes ONE Odoo read() call for all
	 * records, then creates/updates WP translated posts via the adapter.
	 *
	 * @param string                $model              Odoo model (e.g. 'product.template').
	 * @param array<int, int>       $odoo_wp_map        Odoo ID => WP post ID map.
	 * @param array<int, string>    $odoo_fields        Odoo field names to read (e.g. ['name', 'description_sale']).
	 * @param array<string, string> $field_map          Odoo field => WP field (e.g. ['name' => 'post_title']).
	 * @param string                $post_type          WP post type (e.g. 'product').
	 * @param callable              $apply_callback     fn(int $trans_wp_id, array $wp_data, string $lang): void.
	 * @param array<int, string>    $enabled_languages  If non-empty, only pull these language codes.
	 * @return void
	 */
	public function pull_translations_batch(
		string $model,
		array $odoo_wp_map,
		array $odoo_fields,
		array $field_map,
		string $post_type,
		callable $apply_callback,
		array $enabled_languages = []
	): void {
		$adapter = $this->get_adapter();
		if ( ! $adapter ) {
			return;
		}

		$default_lang = $adapter->get_default_language();
		$languages    = $adapter->get_active_languages();

		// Filter to enabled languages if specified.
		if ( ! empty( $enabled_languages ) ) {
			$languages = array_values( array_intersect( $languages, $enabled_languages ) );
		}

		$odoo_ids = array_keys( $odoo_wp_map );

		if ( empty( $odoo_ids ) || empty( $odoo_fields ) ) {
			return;
		}

		foreach ( $languages as $lang ) {
			if ( $lang === $default_lang ) {
				continue;
			}

			$odoo_locale = $this->wp_to_odoo_locale( $lang );

			try {
				$records = $this->read_translated_batch( $model, $odoo_ids, $odoo_fields, $odoo_locale );
			} catch ( \Exception $e ) {
				$this->logger->warning(
					'Failed to read translations from Odoo.',
					[
						'model'  => $model,
						'locale' => $odoo_locale,
						'error'  => $e->getMessage(),
					]
				);
				continue;
			}

			// Index records by Odoo ID.
			$indexed = [];
			foreach ( $records as $record ) {
				if ( isset( $record['id'] ) ) {
					$indexed[ (int) $record['id'] ] = $record;
				}
			}

			foreach ( $odoo_wp_map as $odoo_id => $wp_id ) {
				if ( ! isset( $indexed[ $odoo_id ] ) ) {
					continue;
				}

				// Map Odoo fields to WP fields, filtering empty values.
				$wp_data = [];
				foreach ( $field_map as $odoo_field => $wp_field ) {
					$value = $indexed[ $odoo_id ][ $odoo_field ] ?? '';
					if ( is_string( $value ) && '' !== $value ) {
						$wp_data[ $wp_field ] = $value;
					}
				}

				if ( empty( $wp_data ) ) {
					continue;
				}

				// Create or get existing translated post.
				$trans_wp_id = $adapter->create_translation( $wp_id, $lang, $post_type );
				if ( $trans_wp_id <= 0 ) {
					$this->logger->warning(
						'Could not create translation post.',
						[
							'original_wp_id' => $wp_id,
							'lang'           => $lang,
						]
					);
					continue;
				}

				$apply_callback( $trans_wp_id, $wp_data, $lang );
			}

			$this->logger->info(
				'Pulled translations batch.',
				[
					'model'  => $model,
					'locale' => $odoo_locale,
					'count'  => count( $indexed ),
				]
			);
		}
	}

	// ─── Pull term translations (batch) ────────────────────

	/**
	 * Pull translated term names from Odoo and apply them to
	 * WPML/Polylang translated taxonomy terms.
	 *
	 * For each secondary language, reads `name` from the Odoo model
	 * with the appropriate locale, then creates/updates translated
	 * WP terms via the adapter.
	 *
	 * @since 3.1.0
	 *
	 * @param string             $model             Odoo model (e.g. 'product.category').
	 * @param array<int, int>    $odoo_wp_map       Odoo ID => WP term ID.
	 * @param string             $taxonomy          WP taxonomy (e.g. 'product_cat').
	 * @param callable           $apply_callback    fn(int $trans_term_id, string $name, string $lang): void.
	 * @param array<int, string> $enabled_languages If non-empty, only pull these language codes.
	 * @return void
	 */
	public function pull_term_translations_batch(
		string $model,
		array $odoo_wp_map,
		string $taxonomy,
		callable $apply_callback,
		array $enabled_languages = []
	): void {
		$adapter = $this->get_adapter();
		if ( ! $adapter ) {
			return;
		}

		$default_lang = $adapter->get_default_language();
		$languages    = $adapter->get_active_languages();

		// Filter to enabled languages if specified.
		if ( ! empty( $enabled_languages ) ) {
			$languages = array_values( array_intersect( $languages, $enabled_languages ) );
		}

		$odoo_ids = array_keys( $odoo_wp_map );

		if ( empty( $odoo_ids ) ) {
			return;
		}

		foreach ( $languages as $lang ) {
			if ( $lang === $default_lang ) {
				continue;
			}

			$odoo_locale = $this->wp_to_odoo_locale( $lang );

			try {
				$records = $this->read_translated_batch( $model, $odoo_ids, [ 'name' ], $odoo_locale );
			} catch ( \Exception $e ) {
				$this->logger->warning(
					'Failed to read term translations from Odoo.',
					[
						'model'  => $model,
						'locale' => $odoo_locale,
						'error'  => $e->getMessage(),
					]
				);
				continue;
			}

			// Index records by Odoo ID.
			$indexed = [];
			foreach ( $records as $record ) {
				if ( isset( $record['id'] ) ) {
					$indexed[ (int) $record['id'] ] = $record;
				}
			}

			foreach ( $odoo_wp_map as $odoo_id => $term_id ) {
				if ( ! isset( $indexed[ $odoo_id ] ) ) {
					continue;
				}

				$name = $indexed[ $odoo_id ]['name'] ?? '';
				if ( ! is_string( $name ) || '' === $name ) {
					continue;
				}

				// Create or get existing translated term.
				$trans_term_id = $adapter->create_term_translation( $term_id, $lang, $taxonomy );
				if ( $trans_term_id <= 0 ) {
					$this->logger->warning(
						'Could not create term translation.',
						[
							'original_term_id' => $term_id,
							'lang'             => $lang,
							'taxonomy'         => $taxonomy,
						]
					);
					continue;
				}

				$apply_callback( $trans_term_id, $name, $lang );
			}

			$this->logger->info(
				'Pulled term translations batch.',
				[
					'model'    => $model,
					'taxonomy' => $taxonomy,
					'locale'   => $odoo_locale,
					'count'    => count( $indexed ),
				]
			);
		}
	}

	/**
	 * Read translated field values for a batch of Odoo records.
	 *
	 * Uses context-based read for Odoo 16+ or ir.translation search for 14-15.
	 *
	 * @param string           $model       Odoo model name.
	 * @param array<int, int>  $odoo_ids    Record IDs.
	 * @param array<int, string> $fields    Field names to read.
	 * @param string           $odoo_locale Odoo locale (e.g. 'fr_FR').
	 * @return array<int, array<string, mixed>> Records indexed by position.
	 */
	private function read_translated_batch( string $model, array $odoo_ids, array $fields, string $odoo_locale ): array {
		if ( $this->has_ir_translation() ) {
			return $this->read_batch_via_ir_translation( $model, $odoo_ids, $fields, $odoo_locale );
		}

		// Odoo 16+: read with context={'lang': 'fr_FR'} returns translated values.
		return $this->client()->read( $model, $odoo_ids, array_merge( [ 'id' ], $fields ), [ 'lang' => $odoo_locale ] );
	}

	/**
	 * Read translations via ir.translation model (Odoo 14-15).
	 *
	 * Performs a single search_read on ir.translation, then reconstructs
	 * per-record arrays matching the read() format.
	 *
	 * @param string           $model       Odoo model name.
	 * @param array<int, int>  $odoo_ids    Record IDs.
	 * @param array<int, string> $fields    Field names to read.
	 * @param string           $odoo_locale Odoo locale (e.g. 'fr_FR').
	 * @return array<int, array<string, mixed>> Reconstructed records.
	 */
	private function read_batch_via_ir_translation( string $model, array $odoo_ids, array $fields, string $odoo_locale ): array {
		$field_names = array_map(
			fn( string $field ) => $model . ',' . $field,
			$fields
		);

		$translations = $this->client()->search_read(
			'ir.translation',
			[
				[ 'type', '=', 'model' ],
				[ 'name', 'in', $field_names ],
				[ 'res_id', 'in', $odoo_ids ],
				[ 'lang', '=', $odoo_locale ],
			],
			[ 'name', 'res_id', 'value' ]
		);

		// Reconstruct per-record arrays: [id => [field => value, ...]].
		$result = [];
		foreach ( $translations as $row ) {
			$res_id = (int) ( $row['res_id'] ?? 0 );
			$name   = $row['name'] ?? '';
			$value  = $row['value'] ?? '';

			// Extract field name from "model,field".
			$parts = explode( ',', $name, 2 );
			$field = $parts[1] ?? '';

			if ( $res_id > 0 && '' !== $field ) {
				if ( ! isset( $result[ $res_id ] ) ) {
					$result[ $res_id ] = [ 'id' => $res_id ];
				}
				$result[ $res_id ][ $field ] = $value;
			}
		}

		return array_values( $result );
	}

	// ─── Push translations ──────────────────────────────────

	/**
	 * Push translated field values to Odoo for a specific record.
	 *
	 * For Odoo 16+ (no ir.translation): writes directly with
	 * context={'lang': 'fr_FR'} so Odoo stores translations in
	 * JSONB columns.
	 *
	 * For Odoo 14-15 (ir.translation exists): writes to the
	 * ir.translation model directly with type='model'.
	 *
	 * @param string               $model   Odoo model (e.g. 'product.product').
	 * @param int                  $odoo_id Odoo record ID.
	 * @param array<string, mixed> $values  Field values to translate.
	 * @param string               $wp_lang WP language code (e.g. 'fr').
	 * @return void
	 */
	public function push_translation( string $model, int $odoo_id, array $values, string $wp_lang ): void {
		if ( empty( $values ) || $odoo_id <= 0 ) {
			return;
		}

		$odoo_locale = $this->wp_to_odoo_locale( $wp_lang );

		if ( $this->has_ir_translation() ) {
			$this->push_via_ir_translation( $model, $odoo_id, $values, $odoo_locale );
		} else {
			$this->push_via_context( $model, $odoo_id, $values, $odoo_locale );
		}
	}

	/**
	 * Push translations using context (Odoo 16+).
	 *
	 * @param string               $model       Odoo model name.
	 * @param int                  $odoo_id     Record ID.
	 * @param array<string, mixed> $values      Translated field values.
	 * @param string               $odoo_locale Odoo locale (e.g. 'fr_FR').
	 * @return void
	 */
	private function push_via_context( string $model, int $odoo_id, array $values, string $odoo_locale ): void {
		try {
			$this->client()->write( $model, [ $odoo_id ], $values, [ 'lang' => $odoo_locale ] );

			$this->logger->info(
				'Pushed translation via context.',
				[
					'model'  => $model,
					'id'     => $odoo_id,
					'locale' => $odoo_locale,
					'fields' => array_keys( $values ),
				]
			);
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to push translation via context.',
				[
					'model'  => $model,
					'id'     => $odoo_id,
					'locale' => $odoo_locale,
					'error'  => $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Push translations using ir.translation (Odoo 14-15).
	 *
	 * For each field, searches for an existing ir.translation record
	 * and updates it, or creates a new one.
	 *
	 * @param string               $model       Odoo model name.
	 * @param int                  $odoo_id     Record ID.
	 * @param array<string, mixed> $values      Translated field values.
	 * @param string               $odoo_locale Odoo locale (e.g. 'fr_FR').
	 * @return void
	 */
	private function push_via_ir_translation( string $model, int $odoo_id, array $values, string $odoo_locale ): void {
		$client = $this->client();

		foreach ( $values as $field => $value ) {
			$field_name = $model . ',' . $field;

			try {
				// Search for existing translation.
				$existing = $client->search(
					'ir.translation',
					[
						[ 'type', '=', 'model' ],
						[ 'name', '=', $field_name ],
						[ 'res_id', '=', $odoo_id ],
						[ 'lang', '=', $odoo_locale ],
					]
				);

				if ( ! empty( $existing ) ) {
					$client->write( 'ir.translation', $existing, [ 'value' => (string) $value ] );
				} else {
					$client->create(
						'ir.translation',
						[
							'type'   => 'model',
							'name'   => $field_name,
							'res_id' => $odoo_id,
							'lang'   => $odoo_locale,
							'src'    => '',
							'value'  => (string) $value,
						]
					);
				}

				$this->logger->info(
					'Pushed translation via ir.translation.',
					[
						'field'  => $field_name,
						'id'     => $odoo_id,
						'locale' => $odoo_locale,
					]
				);
			} catch ( \Exception $e ) {
				$this->logger->warning(
					'Failed to push translation via ir.translation.',
					[
						'field'  => $field_name,
						'id'     => $odoo_id,
						'locale' => $odoo_locale,
						'error'  => $e->getMessage(),
					]
				);
			}
		}
	}

	/**
	 * Get the Odoo client instance.
	 *
	 * @return Odoo_Client
	 */
	private function client(): Odoo_Client {
		return ( $this->client_getter )();
	}
}
