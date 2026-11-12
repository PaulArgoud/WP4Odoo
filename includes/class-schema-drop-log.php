<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remembers the fields that were dropped from pushes because the Odoo model
 * does not know them (removed or renamed in the connected Odoo version).
 *
 * Shown in the Health tab so an administrator can see that part of the data
 * is not reaching Odoo. Writes are batched: at most one option update per
 * request, whatever the number of dropped fields.
 *
 * @package WP4Odoo
 * @since   3.10.0
 */
final class Schema_Drop_Log {

	/**
	 * Option name.
	 */
	public const OPTION = 'wp4odoo_schema_drops';

	/**
	 * Maximum number of distinct "model.field" entries kept.
	 */
	private const MAX_ENTRIES = 50;

	/**
	 * Entries older than this (seconds) are forgotten: the cause was probably fixed.
	 */
	private const MAX_AGE = 30 * DAY_IN_SECONDS;

	/**
	 * Drops seen during this request, not yet persisted.
	 *
	 * @var array<string, array{count: int, modules: array<string, true>}>
	 */
	private static array $pending = [];

	/**
	 * Whether the shutdown flush is registered.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Record one dropped field.
	 *
	 * @param string $module Module ID that pushed the value.
	 * @param string $model  Odoo model.
	 * @param string $field  Dropped field name.
	 * @return void
	 */
	public static function record( string $module, string $model, string $field ): void {
		$key = $model . '.' . $field;

		self::$pending[ $key ]['count']              = ( self::$pending[ $key ]['count'] ?? 0 ) + 1;
		self::$pending[ $key ]['modules'][ $module ] = true;

		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', [ self::class, 'flush' ] );
		}
	}

	/**
	 * Persist the drops seen during this request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		$all = self::all();
		$now = time();

		foreach ( self::$pending as $key => $seen ) {
			$entry = $all[ $key ] ?? [
				'count'   => 0,
				'modules' => [],
				'last'    => $now,
			];

			$entry['count']  += $seen['count'];
			$entry['modules'] = array_values( array_unique( array_merge( $entry['modules'], array_keys( $seen['modules'] ) ) ) );
			$entry['last']    = $now;
			$all[ $key ]      = $entry;
		}

		$all = array_filter( $all, static fn( array $e ): bool => ( $now - (int) $e['last'] ) < self::MAX_AGE );
		uasort( $all, static fn( array $a, array $b ): int => $b['last'] <=> $a['last'] );

		update_option( self::OPTION, array_slice( $all, 0, self::MAX_ENTRIES, true ), false );
		self::$pending = [];
	}

	/**
	 * All recorded drops, most recent first.
	 *
	 * @return array<string, array{count: int, modules: array<int, string>, last: int}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Forget everything (and any unsaved drops).
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::$pending = [];
		delete_option( self::OPTION );
	}
}
