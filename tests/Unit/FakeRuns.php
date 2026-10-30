<?php
/**
 * In-memory runs and results.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Runs;

/**
 * Runs without a database, for the runner, rollback and clean-up tests.
 */
class FakeRuns extends Runs {

	/** @var array<int, array> */
	public $runs = array();

	/** @var array<int, array> */
	public $results = array();

	public function create( string $trigger, array $context, int $user_id, string $status = 'queued' ): int {
		$id                = count( $this->runs ) + 1;
		$this->runs[ $id ] = array(
			'id'           => $id,
			'created_gmt'  => '2026-10-30 10:00:00',
			'started_gmt'  => null,
			'finished_gmt' => null,
			'trigger_type' => $trigger,
			'context'      => $context,
			'status'       => $status,
			'verdict'      => '',
			'server_done'  => 0,
			'browser_done' => 0,
			'worker_done'  => 0,
			'summary'      => array(),
			'triggered_by' => $user_id,
		);
		return $id;
	}

	public function get( int $id ): ?array {
		return $this->runs[ $id ] ?? null;
	}

	public function update( int $id, array $fields ): bool {
		$this->runs[ $id ] = array_merge( $this->runs[ $id ], $fields );
		return true;
	}

	public function transition( int $id, string $from, string $to ): bool {
		if ( ( $this->runs[ $id ]['status'] ?? '' ) !== $from ) {
			return false;
		}
		$this->runs[ $id ]['status'] = $to;
		return true;
	}

	public function list( array $args = array() ): array {
		$runs = array_reverse( array_values( $this->runs ) );
		foreach ( array( 'status', 'verdict' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$runs = array_values(
					array_filter(
						$runs,
						static function ( $run ) use ( $args, $key ) {
							return in_array( $run[ $key ], (array) $args[ $key ], true );
						}
					)
				);
			}
		}
		return array_slice( $runs, 0, (int) ( $args['per_page'] ?? 20 ) );
	}

	public function count( array $args = array() ): int {
		return count( $this->list( array( 'per_page' => 1000 ) + $args ) );
	}

	public function add_result( array $result ): int {
		$id                   = count( $this->results ) + 1;
		$result['id']         = $id;
		$result['is_baseline'] = (int) ( $result['is_baseline'] ?? 0 );
		$result['thumb_path'] = (string) ( $result['thumb_path'] ?? '' );
		$this->results[ $id ] = $result;
		return $id;
	}

	public function result( int $id ): ?array {
		return $this->results[ $id ] ?? null;
	}

	public function results( int $run_id, ?string $tier = null ): array {
		return array_values(
			array_filter(
				$this->results,
				static function ( $result ) use ( $run_id, $tier ) {
					return (int) $result['run_id'] === $run_id && ( null === $tier || $result['tier'] === $tier );
				}
			)
		);
	}

	public function baseline( string $url_key, string $tier ): ?array {
		foreach ( array_reverse( $this->results ) as $result ) {
			if ( $result['url_key'] === $url_key && $result['tier'] === $tier && $result['is_baseline'] ) {
				return $result;
			}
		}
		return null;
	}

	public function set_baseline( array $result ): void {
		foreach ( $this->results as $id => $existing ) {
			if ( $existing['url_key'] === $result['url_key'] && $existing['tier'] === $result['tier'] ) {
				$this->results[ $id ]['is_baseline'] = 0;
			}
		}
		$this->results[ (int) $result['id'] ]['is_baseline'] = 1;
	}

	public function delete( array $ids ): int {
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( isset( $this->runs[ $id ] ) ) {
				unset( $this->runs[ $id ] );
				++$deleted;
			}
		}
		return $deleted;
	}

	/** @var int[] What purgeable() returns. */
	public $purgeable = array();

	public function purgeable( string $before_gmt, int $keep, int $limit = 500 ): array {
		$ids             = $this->purgeable;
		$this->purgeable = array();
		return $ids;
	}

	public function by_user( int $user_id, int $limit, int $offset ): array {
		return array_values(
			array_filter(
				$this->runs,
				static function ( $run ) use ( $user_id ) {
					return $run['triggered_by'] === $user_id;
				}
			)
		);
	}

	public function forget_user( int $user_id ): int {
		$changed = 0;
		foreach ( $this->runs as $id => $run ) {
			if ( $run['triggered_by'] === $user_id ) {
				$this->runs[ $id ]['triggered_by'] = 0;
				++$changed;
			}
		}
		return $changed;
	}

	public function tables_exist(): bool {
		return true;
	}
}
