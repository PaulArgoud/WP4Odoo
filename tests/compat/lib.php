<?php
declare( strict_types=1 );

/**
 * Shared helpers for the compatibility scripts (run with `wp eval-file` inside wp-env).
 */

if ( ! defined( 'WP4ODOO_VERSION' ) ) {
	require_once dirname( __DIR__, 2 ) . '/wp4odoo.php';
}

/**
 * Module id => class name, read from the registry source (plus the always-on CRM module).
 *
 * @return array<string, string>
 */
function compat_modules(): array {
	$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-module-registry.php' );
	preg_match_all( "/\[ '(\w+)', Modules\\\\(\w+)::class \]/", $src, $m, PREG_SET_ORDER );

	$map = [ 'crm' => 'CRM_Module' ];
	foreach ( $m as $row ) {
		$map[ $row[1] ] = $row[2];
	}
	return $map;
}

/**
 * Odoo targets: version => [ url, database, api key ].
 *
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
function compat_odoo_targets(): array {
	$dir     = __DIR__ . '/.keys';
	$targets = [];
	foreach ( explode( ' ', (string) ( getenv( 'ODOO_VERSIONS' ) ?: '19 20' ) ) as $v ) {
		$file = "$dir/odoo$v.key";
		if ( is_readable( $file ) ) {
			$targets[ $v ] = [ "http://odoo$v:8069", "db$v", trim( (string) file_get_contents( $file ) ) ];
		}
	}
	return $targets;
}

/**
 * @return array{plugins: array<string, string>, models_absent: array<string, string>, fields_absent: array<string, string>}
 */
function compat_known_issues(): array {
	return require __DIR__ . '/known-issues.php';
}

/**
 * Print a result line and remember failures.
 */
function compat_report( bool $ok, string $label, string $detail = '' ): bool {
	echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $label . ( '' !== $detail ? ' — ' . $detail : '' ) . "\n";
	return $ok;
}
