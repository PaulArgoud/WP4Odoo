<?php
/**
 * Checks one module against the third-party plugin that is currently active.
 *
 * Usage: wp eval-file .../check-plugin.php <module_id>
 * Exit code: 0 = ok or known issue, 1 = failure. A failure means the module is not
 * detected with the plugin active, or its dependency check reports a problem.
 */

require_once __DIR__ . '/lib.php';

$mid     = $args[0] ?? '';
$modules = compat_modules();
$known   = compat_known_issues();

if ( ! isset( $modules[ $mid ] ) ) {
	compat_report( false, $mid, 'unknown module id' );
	exit( 1 );
}

$fq       = 'WP4Odoo\\Modules\\' . $modules[ $mid ];
$problems = [];
$info     = [];

// 1. Detection: the module must be activated by the real plugin.
if ( ! $fq::detect() ) {
	$problems[] = 'not detected by ' . $modules[ $mid ] . '::detect()';
}

// 2. Dependency status and version, as the admin screen would report them.
try {
	$module = new $fq( fn() => null, new \WP4Odoo\Entity_Map_Repository(), new \WP4Odoo\Settings_Repository() );
	$rm     = new ReflectionMethod( $module, 'get_plugin_version' );
	$rm->setAccessible( true );
	$info[] = 'version=' . ( $rm->invoke( $module ) ?: '?' );

	$status = $module->get_dependency_status();
	foreach ( $status['notices'] ?? [] as $n ) {
		if ( 'error' === $n['type'] || ( 'warning' === $n['type'] && ! $status['available'] ) ) {
			$problems[] = $n['type'] . ': ' . $n['message'];
		} elseif ( str_contains( $n['message'], 'has not been tested' ) ) {
			$info[] = 'above tested range';
		}
		// Other info/warning notices (cron advice, optional plugins not active) are not problems.
	}
	if ( ! $status['available'] && ! $status['notices'] ) {
		$problems[] = 'dependency reported unavailable';
	}
} catch ( \Throwable $e ) {
	$problems[] = get_class( $e ) . ': ' . $e->getMessage();
}

// 3. Declared third-party symbols (module health manifest), reported for information only:
// many are optional or belong to premium editions the run cannot install.
$manifest = require dirname( __DIR__ ) . '/module-health-manifest.php';
$missing  = 0;
foreach ( $manifest[ $mid ] ?? [] as $kind => $symbols ) {
	foreach ( $symbols as $s ) {
		$ok = 'classes' === $kind ? class_exists( $s ) : ( 'functions' === $kind ? function_exists( $s ) : defined( $s ) );
		$missing += $ok ? 0 : 1;
	}
}
if ( $missing ) {
	$info[] = "$missing manifest symbol(s) not provided";
}

$label = $mid . ( $info ? ' (' . implode( ', ', $info ) . ')' : '' );
if ( ! $problems ) {
	compat_report( true, $label );
	exit( 0 );
}
if ( isset( $known['plugins'][ $mid ] ) ) {
	echo '[KNOWN] ' . $label . ' — ' . $known['plugins'][ $mid ] . "\n";
	exit( 0 );
}
compat_report( false, $label, implode( ' | ', $problems ) );
exit( 1 );
