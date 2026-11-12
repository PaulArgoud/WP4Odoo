<?php
/**
 * Runs the core business flows against real Odoo servers through every transport:
 * contacts, CRM leads, products, stock, sale orders, invoices, errors.
 *
 * Usage: wp eval-file wp-content/plugins/wp4odoo/tests/compat/odoo-flows.php
 */

use WP4Odoo\API\Odoo_Client;
use WP4Odoo\API\Odoo_Json2;
use WP4Odoo\API\Odoo_JsonRPC;
use WP4Odoo\API\Odoo_XmlRPC;
use WP4Odoo\Logger;
use WP4Odoo\Modules\Stock_Handler;

require_once __DIR__ . '/lib.php';

$targets = compat_odoo_targets();
if ( ! $targets ) {
	echo "No Odoo target configured (run odoo-setup.sh first).\n";
	exit( 2 );
}

$failed = 0;
$step   = function ( string $name, callable $fn ) use ( &$failed ) {
	try {
		$r = $fn();
		compat_report( true, $name, is_scalar( $r ) ? (string) $r : '' );
		return $r;
	} catch ( \Throwable $e ) {
		++$failed;
		compat_report( false, $name, substr( (string) preg_replace( '/\s+/', ' ', $e->getMessage() ), 0, 200 ) );
		return null;
	}
};

foreach ( $targets as $version => [ $url, $db, $key ] ) {
	foreach ( [
		'json2'   => fn() => new Odoo_Json2( $url, $db, $key, 60 ),
		'jsonrpc' => fn() => new Odoo_JsonRPC( $url, $db, 'admin', 60 ),
		'xmlrpc'  => fn() => new Odoo_XmlRPC( $url, $db, 'admin', 60 ),
	] as $proto => $make ) {
		echo "== Odoo $version / $proto\n";
		$t   = $make();
		$uid = $step( 'authenticate', fn() => 'uid=' . $t->authenticate( 'admin' ) );
		if ( ! $uid ) {
			continue;
		}
		$c    = new Odoo_Client( $t, null, fn() => [] );
		$tag  = uniqid( $proto . $version, true );
		$p    = $step( 'res.partner create', fn() => $c->create( 'res.partner', [ 'name' => "Compat $tag", 'email' => "$tag@example.com", 'phone' => '+33100', 'is_company' => true ] ) );
		$step( 'res.partner read/write/search', function () use ( $c, $p, $tag ) {
			$c->write( 'res.partner', [ $p ], [ 'phone' => '+33999' ] );
			$n = count( $c->search( 'res.partner', [ [ 'email', '=', "$tag@example.com" ] ] ) );
			return $c->read( 'res.partner', [ $p ], [ 'phone' ] )[0]['phone'] . " found=$n";
		} );
		$step( 'create_batch', fn() => implode( ',', $c->create_batch( 'res.partner', [ [ 'name' => "B1 $tag" ], [ 'name' => "B2 $tag" ] ] ) ) );
		$step( 'crm.lead create', fn() => $c->create( 'crm.lead', [ 'name' => "Lead $tag", 'email_from' => "l$tag@example.com" ] ) );
		$tmpl = $step( 'product.template create', fn() => $c->create( 'product.template', [ 'name' => "Product $tag", 'list_price' => 12.5, 'default_code' => substr( $tag, 0, 12 ) ] ) );
		$pp   = $tmpl ? ( $c->search( 'product.product', [ [ 'product_tmpl_id', '=', $tmpl ] ] )[0] ?? null ) : null;
		if ( $pp ) {
			$step( 'stock push on a non-storable product', function () use ( $c, $pp, $version ) {
				update_option( 'wp4odoo_odoo_version', $version . '.0' );
				$r = ( new Stock_Handler( new Logger( 'compat' ) ) )->push_stock( $c, $pp, 7.0 );
				if ( ! $r->succeeded() ) {
					throw new \RuntimeException( $r->get_message() );
				}
				$q = $c->search_read( 'stock.quant', [ [ 'product_id', '=', $pp ], [ 'location_id.usage', '=', 'internal' ] ], [ 'quantity' ] );
				return 'quantity=' . ( $q[0]['quantity'] ?? '?' );
			} );
			$so = $step( 'sale.order create + confirm', function () use ( $c, $p, $pp ) {
				$id = $c->create( 'sale.order', [ 'partner_id' => $p, 'order_line' => [ [ 0, 0, [ 'product_id' => $pp, 'product_uom_qty' => 2, 'price_unit' => 12.5 ] ] ] ] );
				$c->execute( 'sale.order', 'action_confirm', [ [ $id ] ] );
				return $c->read( 'sale.order', [ $id ], [ 'state' ] )[0]['state'];
			} );
			$step( 'account.move create + post', function () use ( $c, $p ) {
				$id = $c->create( 'account.move', [ 'move_type' => 'out_invoice', 'partner_id' => $p, 'invoice_date' => gmdate( 'Y-m-d' ), 'invoice_line_ids' => [ [ 0, 0, [ 'name' => 'L', 'quantity' => 1, 'price_unit' => 50 ] ] ] ] );
				$c->execute( 'account.move', 'action_post', [ [ $id ] ] );
				return $c->read( 'account.move', [ $id ], [ 'state' ] )[0]['state'];
			} );
		}
		$step( 'fields_get', fn() => count( $c->fields_get( 'res.partner', [ 'string', 'type' ] ) ) . ' fields' );
		$step( 'translation context write', fn() => $c->write( 'product.template', [ $tmpl ], [ 'name' => 'Produit' ], [ 'lang' => 'en_US' ] ) ? 'ok' : 'false' );
		$step( 'Odoo errors surface as exceptions', function () use ( $c ) {
			try {
				$c->create( 'res.partner', [ 'nope_field' => 1 ] );
			} catch ( \RuntimeException $e ) {
				return 'caught';
			}
			throw new \RuntimeException( 'no error raised for an unknown field' );
		} );
		unset( $so );
	}
}

echo $failed ? "\n$failed flow step(s) failed.\n" : "\nAll flows passed.\n";
exit( $failed ? 1 : 0 );
