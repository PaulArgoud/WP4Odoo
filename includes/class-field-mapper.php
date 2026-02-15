<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utility class for type conversions between WordPress and Odoo data formats.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Field_Mapper {

	/**
	 * Extract numeric ID from an Odoo Many2one field value.
	 *
	 * Odoo returns Many2one as [id, "display_name"] or false.
	 *
	 * @param mixed $value The Many2one value from Odoo.
	 * @return int|null The ID, or null if the value is false/empty.
	 */
	public static function many2one_to_id( mixed $value ): ?int {
		if ( is_array( $value ) && count( $value ) >= 2 ) {
			return (int) $value[0];
		}

		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}

		return null;
	}

	/**
	 * Extract display name from an Odoo Many2one field value.
	 *
	 * @param mixed $value The Many2one value from Odoo.
	 * @return string|null The display name, or null.
	 */
	public static function many2one_to_name( mixed $value ): ?string {
		if ( is_array( $value ) && count( $value ) >= 2 ) {
			return (string) $value[1];
		}

		return null;
	}

	/**
	 * Convert an Odoo datetime string to WordPress-formatted date.
	 *
	 * Odoo format: "2024-01-15 14:30:00" (always UTC).
	 *
	 * @param string $odoo_date Odoo datetime string.
	 * @param string $format    Optional PHP date format. Defaults to WP date+time format.
	 * @return string Formatted date string, or empty string on failure.
	 */
	/**
	 * Cached WP date+time format string.
	 *
	 * Avoids repeated get_option() calls when converting multiple dates
	 * in a batch (e.g. 50 orders with date fields).
	 *
	 * @var string
	 */
	private static string $wp_datetime_format = '';

	public static function odoo_date_to_wp( string $odoo_date, string $format = '' ): string {
		if ( empty( $odoo_date ) || 'false' === $odoo_date ) {
			return '';
		}

		$dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $odoo_date, new \DateTimeZone( 'UTC' ) );

		if ( false === $dt ) {
			$dt = \DateTime::createFromFormat( 'Y-m-d', $odoo_date, new \DateTimeZone( 'UTC' ) );
		}

		if ( false === $dt ) {
			return '';
		}

		$dt->setTimezone( wp_timezone() );

		if ( empty( $format ) ) {
			if ( '' === self::$wp_datetime_format ) {
				self::$wp_datetime_format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i:s' );
			}
			$format = self::$wp_datetime_format;
		}

		return $dt->format( $format );
	}

	/**
	 * Convert a WordPress date to Odoo datetime format.
	 *
	 * @param string $wp_date WordPress date string (in WP timezone).
	 * @return string Odoo datetime string in "Y-m-d H:i:s" UTC format, or empty string on failure.
	 */
	public static function wp_date_to_odoo( string $wp_date ): string {
		if ( empty( $wp_date ) ) {
			return '';
		}

		$dt = date_create( $wp_date, wp_timezone() );

		if ( false === $dt ) {
			return '';
		}

		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );

		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Convert HTML content to plain text.
	 *
	 * @param string $html HTML string.
	 * @return string Plain text.
	 */
	public static function html_to_text( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Convert plain text to basic HTML (paragraphs and line breaks).
	 *
	 * @param string $text Plain text.
	 * @return string HTML string.
	 */
	public static function text_to_html( string $text ): string {
		return wpautop( esc_html( $text ) );
	}

	/**
	 * Convert an Odoo boolean to PHP bool.
	 *
	 * @param mixed $value The Odoo value.
	 * @return bool
	 */
	public static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), [ 'true', '1', 'yes' ], true );
		}

		return (bool) $value;
	}

	/**
	 * Convert a PHP value to strict boolean for Odoo.
	 *
	 * @param mixed $value Any truthy/falsy value.
	 * @return bool Strict boolean for JSON encoding.
	 */
	public static function from_bool( mixed $value ): bool {
		return (bool) $value;
	}

	/**
	 * Format a price/float value for Odoo.
	 *
	 * Ensures a float with the specified number of decimal places,
	 * no thousands separator, dot as decimal separator.
	 *
	 * @param mixed $value    The price or float value.
	 * @param int   $decimals Number of decimal places (default 2).
	 * @return float
	 */
	public static function format_price( mixed $value, int $decimals = 2 ): float {
		return round( (float) $value, $decimals );
	}

	/**
	 * Format an Odoo float for display in WordPress.
	 *
	 * Uses wc_price() if WooCommerce is active, otherwise number_format_i18n.
	 *
	 * @param float $value    The float value from Odoo.
	 * @param int   $decimals Number of decimal places (default 2).
	 * @return string Formatted price string.
	 */
	public static function display_price( float $value, int $decimals = 2 ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wc_price( $value, [ 'decimals' => $decimals ] );
		}

		return number_format_i18n( $value, $decimals );
	}

	/**
	 * Extract IDs from an Odoo One2many or Many2many field.
	 *
	 * @param mixed $value Array of IDs or false.
	 * @return array<int> Array of integer IDs.
	 */
	public static function relation_to_ids( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_map( 'intval', $value );
	}

	/**
	 * Build an Odoo Many2many "replace" command: [[6, 0, [ids]]].
	 *
	 * @param array<int> $ids The IDs to set.
	 * @return array The Odoo command array.
	 */
	public static function ids_to_many2many( array $ids ): array {
		return [ [ 6, 0, array_map( 'intval', $ids ) ] ];
	}

	/**
	 * Build an Odoo Many2many "add" command: [[4, id, 0]].
	 *
	 * @param int $id The ID to add.
	 * @return array The Odoo command array.
	 */
	public static function id_to_many2many_add( int $id ): array {
		return [ [ 4, $id, 0 ] ];
	}

	/**
	 * Build an Odoo One2many/Many2many "create" command: [[0, 0, {values}]].
	 *
	 * @param array $values The values for the new record.
	 * @return array The Odoo command array.
	 */
	public static function values_to_relation_create( array $values ): array {
		return [ [ 0, 0, $values ] ];
	}
}
