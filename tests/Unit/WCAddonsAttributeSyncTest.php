<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\API\Odoo_Client;
use WP4Odoo\API\Transport;
use WP4Odoo\Modules\WC_Addons_Module;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Add-ons are written as product attributes on the product.template (not to a made-up model).
 */
#[CoversClass( WC_Addons_Module::class )]
class WCAddonsAttributeSyncTest extends Module_Test_Case {

	/** @var array<int, array{model: string, method: string, args: array, kwargs: array}> */
	private array $calls = [];

	/** @var array<string, mixed> Canned answers keyed "model::method". */
	private array $answers = [];

	private int $next_id = 100;

	private function module(): WC_Addons_Module {
		$self      = $this;
		$transport = new class( $self ) implements Transport {
			public function __construct( private WCAddonsAttributeSyncTest $test ) {}

			public function authenticate( string $username ): int {
				return 1;
			}

			public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
				return $this->test->answer( $model, $method, $args, $kwargs );
			}

			public function get_uid(): ?int {
				return 1;
			}

			public function get_server_version(): ?string {
				return '19.0';
			}
		};

		$provider = function () use ( $transport ): Odoo_Client {
			$client = new Odoo_Client();
			$ref    = new \ReflectionClass( $client );
			$ref->getProperty( 'transport' )->setValue( $client, $transport );
			$ref->getProperty( 'connected' )->setValue( $client, true );
			return $client;
		};

		return new WC_Addons_Module( $provider, wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	/** @internal Called by the scripted transport. */
	public function answer( string $model, string $method, array $args, array $kwargs ): mixed {
		$this->calls[] = compact( 'model', 'method', 'args', 'kwargs' );
		$key           = $model . '::' . $method;

		if ( array_key_exists( $key, $this->answers ) ) {
			return $this->answers[ $key ];
		}

		return 'create' === $method ? ++$this->next_id : ( 'write' === $method ? true : [] );
	}

	/** @return array<int, array> Calls matching a model and method. */
	private function calls_to( string $model, string $method ): array {
		return array_values( array_filter( $this->calls, fn( $c ) => $c['model'] === $model && $c['method'] === $method ) );
	}

	private function sync( array $addons ): int {
		$module = $this->module();

		return ( new \ReflectionMethod( $module, 'sync_attribute_lines' ) )->invoke( $module, 7, $addons );
	}

	private function addons(): array {
		return [
			[
				'name'    => 'Gift wrap',
				'type'    => 'select',
				'options' => [
					[ 'label' => 'Standard', 'price' => 2.5 ],
					[ 'label' => 'Luxury', 'price' => 6.0 ],
				],
			],
		];
	}

	public function test_creates_the_attribute_values_and_line_on_the_product_template(): void {
		$this->answers['product.template.attribute.value::search_read'] = [
			[ 'id' => 31, 'product_attribute_value_id' => [ 102, 'Standard' ], 'price_extra' => 0.0 ],
			[ 'id' => 32, 'product_attribute_value_id' => [ 103, 'Luxury' ], 'price_extra' => 0.0 ],
		];

		$this->assertSame( 1, $this->sync( $this->addons() ) );

		$attribute = $this->calls_to( 'product.attribute', 'create' )[0];
		$this->assertSame( 'Gift wrap', $attribute['args'][0]['name'] );
		$this->assertSame( 'no_variant', $attribute['args'][0]['create_variant'] );
		$this->assertCount( 2, $this->calls_to( 'product.attribute.value', 'create' ) );

		$line = $this->calls_to( 'product.template.attribute.line', 'create' )[0]['args'][0];
		$this->assertSame( 7, $line['product_tmpl_id'] );
		$this->assertSame( [ [ 6, 0, [ 102, 103 ] ] ], $line['value_ids'] );

		$writes = $this->calls_to( 'product.template.attribute.value', 'write' );
		$this->assertSame( [ [ 31 ], [ 'price_extra' => 2.5 ] ], $writes[0]['args'] );
		$this->assertSame( [ [ 32 ], [ 'price_extra' => 6.0 ] ], $writes[1]['args'] );

		$this->assertSame( [], $this->calls_to( 'product.template', 'create' ), 'No product template is created.' );
	}

	public function test_reuses_existing_attribute_value_and_line(): void {
		$this->answers['product.attribute::search']                     = [ 5 ];
		$this->answers['product.attribute.value::search']               = [ 9 ];
		$this->answers['product.template.attribute.line::search']       = [ 77 ];
		$this->answers['product.template.attribute.value::search_read'] = [];

		$this->sync( [ [ 'name' => 'Gift wrap', 'type' => 'select', 'options' => [ [ 'label' => 'Standard', 'price' => 2.5 ] ] ] ] );

		$this->assertSame( [], $this->calls_to( 'product.attribute', 'create' ) );
		$this->assertSame( [], $this->calls_to( 'product.attribute.value', 'create' ) );
		$this->assertSame( [], $this->calls_to( 'product.template.attribute.line', 'create' ) );
		$this->assertSame( [ [ 77 ], [ 'value_ids' => [ [ 6, 0, [ 9 ] ] ] ] ], $this->calls_to( 'product.template.attribute.line', 'write' )[0]['args'] );
	}

	public function test_skips_addons_without_options_and_unchanged_prices(): void {
		$this->answers['product.template.attribute.value::search_read'] = [
			[ 'id' => 31, 'product_attribute_value_id' => [ 102, 'Standard' ], 'price_extra' => 2.5 ],
		];

		$written = $this->sync(
			[
				[ 'name' => 'Empty', 'type' => 'select', 'options' => [] ],
				[ 'name' => 'Gift wrap', 'type' => 'select', 'options' => [ [ 'label' => 'Standard', 'price' => 2.5 ] ] ],
			]
		);

		$this->assertSame( 1, $written );
		$this->assertSame( [], $this->calls_to( 'product.template.attribute.value', 'write' ), 'The price is already right.' );
	}

	public function test_a_template_id_is_resolved_without_reading_product_product(): void {
		$this->answers['product.template::search'] = [ 7 ];
		$module                                     = $this->module();

		$id = ( new \ReflectionMethod( $module, 'resolve_product_template_id' ) )->invoke( $module, 7 );

		$this->assertSame( 7, $id );
		$this->assertSame( [], $this->calls_to( 'product.product', 'read' ) );
	}
}
