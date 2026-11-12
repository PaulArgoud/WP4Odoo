<?php
/**
 * Minimal wpdb stub for PHPUnit tests.
 *
 * Records method calls and returns configurable values.
 *
 * @package WP4Odoo\Tests
 */

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

class WP_DB_Stub {

	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public int $insert_id = 0;
	public string $last_error = '';

	/** @var int|false Return value of insert() (false simulates a failed write). */
	public int|false $insert_return = 1;

	/** @var \Throwable|null Optional exception to throw from insert(). */
	public ?\Throwable $insert_throws = null;

	/** @var string Table name pattern to match for insert_throws (empty = all tables). */
	public string $insert_throws_table = '';

	/** @var string Return value for GET_LOCK/RELEASE_LOCK queries ('1' = success, '0' = failure). */
	public string $lock_return = '1';

	/** @var mixed */
	public $get_var_return = null;
	/** @var array|object|null */
	public $get_row_return = null;
	public array $get_results_return = [];

	/**
	 * Queue of successive get_results() return values.
	 *
	 * When non-empty, get_results() shifts one value per call.
	 * When exhausted, falls back to get_results_return.
	 *
	 * @var array<int, array>
	 */
	public array $get_results_sequence = [];
	public array $get_col_return = [];
	public int $delete_return = 1;
	public int $query_return = 1;

	/**
	 * Queue of successive query() return values.
	 *
	 * When non-empty, query() shifts one value per call.
	 * When exhausted, falls back to query_return.
	 *
	 * @var array<int, int>
	 */
	public array $query_return_sequence = [];

	/** @var array<int, array{method: string, args: array}> */
	public array $calls = [];

	public function esc_like( string $text ): string {
		return $text;
	}

	public function prepare( string $query, ...$args ): string {
		$this->calls[] = [ 'method' => 'prepare', 'args' => [ $query, ...$args ] ];
		return $query;
	}

	/** @return mixed */
	public function get_var( $query ) {
		$this->calls[] = [ 'method' => 'get_var', 'args' => [ $query ] ];

		// Advisory locks return configurable value (default: success).
		if ( is_string( $query ) && ( str_contains( $query, 'GET_LOCK' ) || str_contains( $query, 'RELEASE_LOCK' ) ) ) {
			return $this->lock_return;
		}

		return $this->get_var_return;
	}

	/** @return array|object|null */
	public function get_row( $query, $output = OBJECT, $y = 0 ) {
		$this->calls[] = [ 'method' => 'get_row', 'args' => [ $query, $output ] ];
		return $this->get_row_return;
	}

	public function get_col( $query ): array {
		$this->calls[] = [ 'method' => 'get_col', 'args' => [ $query ] ];
		return $this->get_col_return;
	}

	public function get_results( $query ): array {
		$this->calls[] = [ 'method' => 'get_results', 'args' => [ $query ] ];

		if ( ! empty( $this->get_results_sequence ) ) {
			return array_shift( $this->get_results_sequence );
		}

		// Auto-drain: return the configured results once, then empty.
		// Simulates real DB behavior where claimed/processed rows
		// are no longer returned by subsequent fetch_pending() calls.
		$result                   = $this->get_results_return;
		$this->get_results_return = [];

		return $result;
	}

	/** @return int|false */
	public function insert( string $table, array $data ) {
		$this->calls[] = [ 'method' => 'insert', 'args' => [ $table, $data ] ];

		if ( $this->insert_throws && ( '' === $this->insert_throws_table || str_contains( $table, $this->insert_throws_table ) ) ) {
			throw $this->insert_throws;
		}

		return $this->insert_return;
	}

	/** @return int|false */
	public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ) {
		$this->calls[] = [ 'method' => 'update', 'args' => [ $table, $data, $where ] ];
		return 1;
	}

	/** @return int|false */
	public function replace( string $table, array $data ) {
		$this->calls[] = [ 'method' => 'replace', 'args' => [ $table, $data ] ];
		return 1;
	}

	/** @return int|false */
	public function delete( string $table, array $where, ?array $format = null ) {
		$this->calls[] = [ 'method' => 'delete', 'args' => [ $table, $where, $format ] ];
		return $this->delete_return;
	}

	/** @return int|false */
	public function query( string $query ) {
		$this->calls[] = [ 'method' => 'query', 'args' => [ $query ] ];

		if ( ! empty( $this->query_return_sequence ) ) {
			return array_shift( $this->query_return_sequence );
		}

		return $this->query_return;
	}

	/** @return bool Previous suppress state (always false for stub). */
	public function suppress_errors( bool $suppress = true ): bool {
		$this->calls[] = [ 'method' => 'suppress_errors', 'args' => [ $suppress ] ];
		return false;
	}

	public function reset(): void {
		$this->calls              = [];
		$this->lock_return        = '1';
		$this->get_var_return     = null;
		$this->get_row_return     = null;
		$this->get_col_return     = [];
		$this->get_results_return   = [];
		$this->get_results_sequence = [];
		$this->delete_return          = 1;
		$this->query_return           = 1;
		$this->query_return_sequence  = [];
		$this->insert_id              = 0;
	}
}
