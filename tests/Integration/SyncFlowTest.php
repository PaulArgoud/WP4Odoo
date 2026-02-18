<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

use WP4Odoo\Sync_Engine;
use WP4Odoo\Sync_Queue_Repository;
use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\API\Odoo_Client;
use WP4Odoo\Modules\CRM_Module;

/**
 * Integration tests for the complete sync flow.
 *
 * Validates the end-to-end pipeline: enqueue → process → transport → entity_map.
 * Uses the CRM module (always available, no third-party dependency) with a
 * SyncFlowTransport mock that returns method-appropriate values.
 *
 * @package WP4Odoo\Tests\Integration
 * @since   3.6.0
 */
class SyncFlowTest extends WP4Odoo_TestCase {

	private Sync_Queue_Repository $queue;
	private Entity_Map_Repository $entity_map;
	private SyncFlowTransport $transport;

	public function setUp(): void {
		parent::setUp();
		$this->queue      = new Sync_Queue_Repository();
		$this->entity_map = new Entity_Map_Repository();
		$this->entity_map->flush_cache();
		$this->transport  = new SyncFlowTransport();
	}

	public function tearDown(): void {
		$this->entity_map->flush_cache();
		parent::tearDown();
	}

	/**
	 * Create a CRM module backed by the mock transport.
	 */
	private function create_crm_module(): CRM_Module {
		$transport       = $this->transport;
		$client_provider = fn() => new Odoo_Client( $transport );
		return new CRM_Module( $client_provider, $this->entity_map, wp4odoo_test_settings() );
	}

	/**
	 * Create a Sync_Engine with the CRM module as sole resolver.
	 */
	private function create_engine( CRM_Module $crm ): Sync_Engine {
		$resolver = fn( string $id ) => 'crm' === $id ? $crm : null;
		return new Sync_Engine( $resolver, $this->queue, wp4odoo_test_settings() );
	}

	// ─── Test 1: Enqueue creates a pending job ─────────────

	public function test_enqueue_creates_pending_job(): void {
		$job_id = $this->queue->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'action'      => 'create',
			'wp_id'       => 1,
		] );

		$this->assertIsInt( $job_id );
		$this->assertGreaterThan( 0, $job_id );

		$jobs  = $this->queue->fetch_pending( 10, gmdate( 'Y-m-d H:i:s' ) );
		$found = false;
		foreach ( $jobs as $job ) {
			if ( $job->id === $job_id ) {
				$found = true;
				$this->assertSame( 'crm', $job->module );
				$this->assertSame( 'contact', $job->entity_type );
				$this->assertSame( 'create', $job->action );
			}
		}
		$this->assertTrue( $found, 'Enqueued job should appear in fetch_pending results.' );
	}

	// ─── Test 2: Queue processing calls the transport ──────

	public function test_queue_process_calls_transport(): void {
		$user_id = self::factory()->user->create( [
			'user_email' => 'transport-test@example.com',
			'first_name' => 'Transport',
			'last_name'  => 'Test',
		] );

		$crm = $this->create_crm_module();

		$this->queue->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'action'      => 'create',
			'wp_id'       => $user_id,
		] );

		$engine    = $this->create_engine( $crm );
		$processed = $engine->process_queue();

		$this->assertGreaterThan( 0, $processed );
		$this->assertNotEmpty( $this->transport->calls, 'Expected at least one transport call.' );

		// Verify res.partner model was targeted.
		$models = array_column( $this->transport->calls, 'model' );
		$this->assertContains( 'res.partner', $models );
	}

	// ─── Test 3: Successful push creates entity_map entry ──

	public function test_successful_push_creates_entity_map_entry(): void {
		$user_id = self::factory()->user->create( [
			'user_email' => 'map-test@example.com',
			'first_name' => 'Map',
			'last_name'  => 'Test',
		] );

		$this->transport->create_id = 99;

		$crm    = $this->create_crm_module();
		$engine = $this->create_engine( $crm );

		$this->queue->enqueue( [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'action'      => 'create',
			'wp_id'       => $user_id,
		] );

		$engine->process_queue();

		$odoo_id = $this->entity_map->get_odoo_id( 'crm', 'contact', $user_id );
		$this->assertSame( 99, $odoo_id, 'Entity map should contain the Odoo ID returned by transport.' );
	}

	// ─── Test 4: Duplicate push is deduplicated ────────────

	public function test_duplicate_push_deduplicated(): void {
		$job_args = [
			'module'      => 'crm',
			'direction'   => 'wp_to_odoo',
			'entity_type' => 'contact',
			'action'      => 'create',
			'wp_id'       => 1,
		];

		$job_id_1 = $this->queue->enqueue( $job_args );
		$job_id_2 = $this->queue->enqueue( $job_args );

		$this->assertSame( $job_id_1, $job_id_2, 'Second enqueue should return the same job ID (dedup).' );
	}

	// ─── Test 5: Pull job enqueues and fetches correctly ───

	public function test_pull_job_enqueues_correctly(): void {
		$job_id = $this->queue->enqueue( [
			'module'      => 'crm',
			'direction'   => 'odoo_to_wp',
			'entity_type' => 'contact',
			'action'      => 'update',
			'odoo_id'     => 42,
		] );

		$this->assertIsInt( $job_id );
		$this->assertGreaterThan( 0, $job_id );

		$jobs  = $this->queue->fetch_pending( 10, gmdate( 'Y-m-d H:i:s' ) );
		$found = false;
		foreach ( $jobs as $job ) {
			if ( $job->id === $job_id ) {
				$found = true;
				$this->assertSame( 'odoo_to_wp', $job->direction );
				$this->assertSame( 'update', $job->action );
				$this->assertSame( 42, $job->odoo_id );
			}
		}
		$this->assertTrue( $found, 'Pull job should appear in fetch_pending results.' );
	}
}
