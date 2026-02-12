<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status_Mapper — resolves a source status to a target status via a filterable map.
 *
 * Centralizes the 2-line pattern repeated in 20 handler methods:
 *   $map = apply_filters( $hook, self::CONST_MAP );
 *   return $map[ $status ] ?? $default;
 *
 * @package WP4Odoo
 * @since   2.9.5
 */
class Status_Mapper {

	/**
	 * Resolve a status through a filterable map.
	 *
	 * @param string                $status  Source status string.
	 * @param array<string, string> $map     Default status map (source → target).
	 * @param string                $hook    WordPress filter hook name.
	 * @param string                $default Fallback value if status not in map.
	 * @return string Resolved target status.
	 */
	public static function resolve( string $status, array $map, string $hook, string $default ): string {
		/** @var array<string, string> $map */
		$map = apply_filters( $hook, $map );

		return $map[ $status ] ?? $default;
	}
}
