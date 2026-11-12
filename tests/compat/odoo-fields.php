<?php
/**
 * Checks that every field the modules map by default exists in the Odoo schema
 * (or is renamed through $odoo_field_renames), for each Odoo version under test.
 *
 * Usage: wp eval-file wp-content/plugins/wp4odoo/tests/compat/odoo-fields.php
 */

use WP4Odoo\API\Odoo_JsonRPC;

require_once __DIR__ . '/lib.php';

$known   = compat_known_issues();
$targets = compat_odoo_targets();
if ( ! $targets ) {
	echo "No Odoo target configured (run odoo-setup.sh first).\n";
	exit( 2 );
}

$settings = new \WP4Odoo\Settings_Repository();
$emap     = new \WP4Odoo\Entity_Map_Repository();
$rm_map   = new ReflectionProperty( \WP4Odoo\Module_Base::class, 'default_mappings' );
$rm_ren   = new ReflectionProperty( \WP4Odoo\Module_Base::class, 'odoo_field_renames' );

// module id => [ entity => [ model, [ fields ], [ renames ] ] ]
$wanted = [];
foreach ( compat_modules() as $id => $class ) {
	$fq = 'WP4Odoo\\Modules\\' . $class;
	try {
		$module = ( new ReflectionClass( $fq ) )->newInstance( fn() => null, $emap, $settings );
	} catch ( \Throwable $e ) {
		continue;
	}
	$models  = $module->get_odoo_models();
	$renames = $rm_ren->getValue( $module );
	foreach ( $rm_map->getValue( $module ) as $entity => $map ) {
		if ( empty( $models[ $entity ] ) || ! is_array( $map ) ) {
			continue;
		}
		foreach ( $map as $odoo_field ) {
			if ( is_string( $odoo_field ) && '' !== $odoo_field && ! str_starts_with( $odoo_field, '_' ) && ! str_starts_with( $odoo_field, 'x_' ) ) {
				$wanted[ $id ][ $entity ]['model']    = $models[ $entity ];
				$wanted[ $id ][ $entity ]['fields'][] = $odoo_field;
				$wanted[ $id ][ $entity ]['renames']  = $renames[ $entity ] ?? [];
			}
		}
	}
}

$failed = 0;
foreach ( $targets as $version => [ $url, $db, $key ] ) {
	$t = new Odoo_JsonRPC( $url, $db, $key, 60 );
	$t->authenticate( 'admin' );
	echo "== Odoo $version\n";

	$schemas = [];
	$checked = 0;
	foreach ( $wanted as $id => $entities ) {
		foreach ( $entities as $entity => $spec ) {
			$model = $spec['model'];
			if ( ! array_key_exists( $model, $schemas ) ) {
				try {
					$schemas[ $model ] = array_keys( $t->execute_kw( $model, 'fields_get', [], [ 'attributes' => [ 'type' ] ] ) );
				} catch ( \Throwable $e ) {
					$schemas[ $model ] = null;
				}
			}
			if ( null === $schemas[ $model ] ) {
				if ( ! isset( $known['models_absent'][ $model ] ) ) {
					++$failed;
					compat_report( false, "$id: model $model", 'absent and not listed in known-issues.php' );
				}
				continue;
			}
			foreach ( array_unique( $spec['fields'] ) as $field ) {
				++$checked;
				$aliases = (array) ( $spec['renames'][ $field ] ?? [] );
				if ( in_array( $field, $schemas[ $model ], true ) || array_intersect( $aliases, $schemas[ $model ] ) ) {
					continue;
				}
				if ( isset( $known['fields_absent'][ "$model.$field" ] ) ) {
					continue;
				}
				++$failed;
				compat_report( false, "$id: $model.$field", 'not in the Odoo schema, no rename declared and not listed in known-issues.php' );
			}
		}
	}
	echo "checked $checked mapped fields\n";
}

echo $failed ? "\n$failed unexpected schema gap(s).\n" : "\nAll mapped fields are accounted for.\n";
exit( $failed ? 1 : 0 );
