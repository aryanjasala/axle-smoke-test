<?php
/**
 * Runs and results storage.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own tables, names from Schema. Runs change on every check, so caching them would only serve stale verdicts.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from Schema, values are prepared.

/**
 * Reads and writes the runs and results tables. JSON columns come back decoded.
 */
class Runs {

	public const STATUSES = array( 'queued', 'running', 'waiting_browser', 'done', 'failed' );
	public const TRIGGERS = array( 'update', 'install', 'theme', 'deploy', 'manual', 'cli', 'rest', 'schedule', 'rollback' );
	public const TIERS    = array( 'server', 'browser', 'worker' );

	private const RUN_JSON    = array( 'context', 'summary' );
	private const RESULT_JSON = array( 'php_errors', 'js_errors', 'missing_assets', 'text_fingerprint', 'landmarks', 'layout', 'meta' );

	/**
	 * Adds a run.
	 *
	 * @param string $trigger One of TRIGGERS.
	 * @param array  $context Items changed, reason, URL keys.
	 * @param int    $user_id Who started it, 0 for the system.
	 * @param string $status  Starting status.
	 * @return int Run ID, 0 on failure.
	 */
	public function create( string $trigger, array $context, int $user_id, string $status = 'queued' ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			Schema::runs_table(),
			array(
				'created_gmt'  => current_time( 'mysql', true ),
				'trigger_type' => in_array( $trigger, self::TRIGGERS, true ) ? $trigger : 'manual',
				'context'      => wp_json_encode( $context ),
				'status'       => in_array( $status, self::STATUSES, true ) ? $status : 'queued',
				'triggered_by' => max( 0, $user_id ),
				'summary'      => '{}',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One run.
	 *
	 * @param int $id Run ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::runs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::decode_run( $row ) : null;
	}

	/**
	 * Changes fields of a run. Arrays are stored as JSON.
	 *
	 * @param int   $id     Run ID.
	 * @param array $fields Columns.
	 * @return bool
	 */
	public function update( int $id, array $fields ): bool {
		global $wpdb;
		foreach ( self::RUN_JSON as $column ) {
			if ( isset( $fields[ $column ] ) && is_array( $fields[ $column ] ) ) {
				$fields[ $column ] = wp_json_encode( $fields[ $column ] );
			}
		}

		return false !== $wpdb->update( Schema::runs_table(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Moves a run from one status to another, only if it still has the first.
	 * Two requests cannot both start the same run.
	 *
	 * @param int    $id   Run ID.
	 * @param string $from Expected status.
	 * @param string $to   New status.
	 * @return bool Whether this call made the change.
	 */
	public function transition( int $id, string $from, string $to ): bool {
		global $wpdb;
		$table = Schema::runs_table();

		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id = %d AND status = %s", $to, $id, $from ) );
	}

	/**
	 * Runs, newest first.
	 *
	 * @param array $args verdict, status, trigger, per_page (default 20), page (default 1).
	 * @return array[]
	 */
	public function list( array $args = array() ): array {
		global $wpdb;
		$table                  = Schema::runs_table();
		list( $where, $values ) = self::where( $args );
		$per_page               = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset                 = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$values[]               = $per_page;
		$values[]               = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders come from where().

		return array_map( array( self::class, 'decode_run' ), (array) $rows );
	}

	/**
	 * How many runs match.
	 *
	 * @param array $args verdict, status, trigger.
	 * @return int
	 */
	public function count( array $args = array() ): int {
		global $wpdb;
		$table                  = Schema::runs_table();
		list( $where, $values ) = self::where( $args );
		$sql                    = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		return (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $sql, $values ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built from fixed parts and placeholders.
	}

	/**
	 * The newest run with one of these statuses.
	 *
	 * @param string[] $statuses Statuses. Empty for any.
	 * @return array|null
	 */
	public function latest( array $statuses = array() ): ?array {
		$runs = $this->list(
			array(
				'status'   => $statuses,
				'per_page' => 1,
			)
		);

		return $runs ? $runs[0] : null;
	}

	/**
	 * The newest finished run.
	 *
	 * @return array|null
	 */
	public function latest_finished(): ?array {
		return $this->latest( array( 'done', 'waiting_browser', 'failed' ) );
	}

	/**
	 * Builds the WHERE clause for list() and count().
	 *
	 * @param array $args Filters.
	 * @return array{0: string, 1: array}
	 */
	private static function where( array $args ): array {
		$where  = array( '1=1' );
		$values = array();
		foreach ( array( 'verdict', 'status', 'trigger' ) as $key ) {
			if ( empty( $args[ $key ] ) ) {
				continue;
			}
			$list    = array_values( array_filter( array_map( 'strval', (array) $args[ $key ] ) ) );
			$column  = 'trigger' === $key ? 'trigger_type' : $key;
			$where[] = $column . ' IN (' . implode( ', ', array_fill( 0, count( $list ), '%s' ) ) . ')';
			$values  = array_merge( $values, $list );
		}
		if ( ! empty( $args['before_gmt'] ) ) {
			$where[]  = 'created_gmt < %s';
			$values[] = (string) $args['before_gmt'];
		}

		return array( implode( ' AND ', $where ), $values );
	}

	/**
	 * Adds a result.
	 *
	 * @param array $result Columns; arrays are stored as JSON.
	 * @return int Result ID.
	 */
	public function add_result( array $result ): int {
		global $wpdb;
		$row                = self::encode_result( $result );
		$row['created_gmt'] = current_time( 'mysql', true );
		$ok                 = $wpdb->insert( Schema::results_table(), $row );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Changes fields of a result.
	 *
	 * @param int   $id     Result ID.
	 * @param array $fields Columns.
	 * @return bool
	 */
	public function update_result( int $id, array $fields ): bool {
		global $wpdb;
		return false !== $wpdb->update( Schema::results_table(), self::encode_result( $fields ), array( 'id' => $id ) );
	}

	/**
	 * One result.
	 *
	 * @param int $id Result ID.
	 * @return array|null
	 */
	public function result( int $id ): ?array {
		global $wpdb;
		$table = Schema::results_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::decode_result( $row ) : null;
	}

	/**
	 * A run's results, in the order they were checked.
	 *
	 * @param int         $run_id Run ID.
	 * @param string|null $tier   Only this tier.
	 * @return array[]
	 */
	public function results( int $run_id, ?string $tier = null ): array {
		global $wpdb;
		$table = Schema::results_table();
		$rows  = null === $tier
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC LIMIT 1000", $run_id ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d AND tier = %s ORDER BY id ASC LIMIT 1000", $run_id, $tier ), ARRAY_A );

		return array_map( array( self::class, 'decode_result' ), (array) $rows );
	}

	/**
	 * The baseline for a URL and tier.
	 *
	 * @param string $url_key URL key.
	 * @param string $tier    Tier.
	 * @return array|null
	 */
	public function baseline( string $url_key, string $tier ): ?array {
		global $wpdb;
		$table = Schema::results_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE url_key = %s AND tier = %s AND is_baseline = 1 ORDER BY id DESC LIMIT 1", $url_key, $tier ), ARRAY_A );

		return $row ? self::decode_result( $row ) : null;
	}

	/**
	 * Makes a result the baseline for its URL and tier.
	 *
	 * @param array $result Result.
	 */
	public function set_baseline( array $result ): void {
		global $wpdb;
		$table = Schema::results_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_baseline = 0 WHERE url_key = %s AND tier = %s AND is_baseline = 1", $result['url_key'], $result['tier'] ) );
		$wpdb->update( $table, array( 'is_baseline' => 1 ), array( 'id' => (int) $result['id'] ) );
	}

	/**
	 * Every baseline result.
	 *
	 * @return array[]
	 */
	public function baselines(): array {
		global $wpdb;
		$table = Schema::results_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_baseline = 1 ORDER BY id DESC LIMIT 1000", ARRAY_A );

		return array_map( array( self::class, 'decode_result' ), (array) $rows );
	}

	/**
	 * Deletes runs and their results. Runs holding a baseline keep it: the
	 * baseline result moves to no run (run_id 0).
	 *
	 * @param int[] $ids Run IDs.
	 * @return int Runs deleted.
	 */
	public function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}

		$runs         = Schema::runs_table();
		$results      = Schema::results_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are built from the ID count.
		$wpdb->query( $wpdb->prepare( "UPDATE {$results} SET run_id = 0 WHERE is_baseline = 1 AND run_id IN ({$placeholders})", $ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$results} WHERE run_id IN ({$placeholders})", $ids ) );
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$runs} WHERE id IN ({$placeholders})", $ids ) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared

		return $deleted;
	}

	/**
	 * IDs of runs to purge: older than a date, or beyond the newest N.
	 *
	 * @param string $before_gmt MySQL date.
	 * @param int    $keep       Runs to keep.
	 * @param int    $limit      Most IDs returned.
	 * @return int[]
	 */
	public function purgeable( string $before_gmt, int $keep, int $limit = 500 ): array {
		global $wpdb;
		$table = Schema::runs_table();
		$old   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE created_gmt < %s AND status IN ('done', 'failed') ORDER BY id ASC LIMIT %d", $before_gmt, $limit ) );
		$extra = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status IN ('done', 'failed') ORDER BY id DESC LIMIT %d, %d", max( 0, $keep ), $limit ) );

		return array_slice( array_values( array_unique( array_map( 'intval', array_merge( (array) $old, (array) $extra ) ) ) ), 0, $limit );
	}

	/**
	 * Runs started by a user, for the privacy exporter and eraser.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Most runs.
	 * @param int $offset  Offset.
	 * @return array[]
	 */
	public function by_user( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$table = Schema::runs_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE triggered_by = %d ORDER BY id ASC LIMIT %d OFFSET %d", $user_id, $limit, $offset ), ARRAY_A );

		return array_map( array( self::class, 'decode_run' ), (array) $rows );
	}

	/**
	 * Takes a user off their runs.
	 *
	 * @param int $user_id User ID.
	 * @return int Runs changed.
	 */
	public function forget_user( int $user_id ): int {
		global $wpdb;
		$table = Schema::runs_table();

		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET triggered_by = 0 WHERE triggered_by = %d", $user_id ) );
	}

	/**
	 * Whether both tables exist.
	 *
	 * @return bool
	 */
	public function tables_exist(): bool {
		global $wpdb;
		foreach ( array( Schema::runs_table(), Schema::results_table() ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A run row with its JSON decoded and types fixed.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function decode_run( array $row ): array {
		foreach ( self::RUN_JSON as $column ) {
			$decoded        = json_decode( (string) ( $row[ $column ] ?? '' ), true );
			$row[ $column ] = is_array( $decoded ) ? $decoded : array();
		}
		foreach ( array( 'id', 'server_done', 'browser_done', 'worker_done', 'triggered_by' ) as $column ) {
			$row[ $column ] = (int) ( $row[ $column ] ?? 0 );
		}

		return $row;
	}

	/**
	 * A result row with its JSON decoded and types fixed.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function decode_result( array $row ): array {
		foreach ( self::RESULT_JSON as $column ) {
			$decoded        = json_decode( (string) ( $row[ $column ] ?? '' ), true );
			$row[ $column ] = is_array( $decoded ) ? $decoded : array();
		}
		foreach ( array( 'id', 'run_id', 'http_status', 'ttfb_ms', 'body_bytes', 'is_baseline' ) as $column ) {
			$row[ $column ] = (int) ( $row[ $column ] ?? 0 );
		}

		return $row;
	}

	/**
	 * Result fields ready for the table.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	private static function encode_result( array $result ): array {
		$columns = array( 'run_id', 'url_key', 'url', 'tier', 'http_status', 'ttfb_ms', 'body_bytes', 'php_errors', 'js_errors', 'missing_assets', 'html_hash', 'text_fingerprint', 'landmarks', 'layout', 'thumb_path', 'verdict', 'is_baseline', 'meta' );
		$row     = array();
		foreach ( $columns as $column ) {
			if ( ! array_key_exists( $column, $result ) ) {
				continue;
			}
			$row[ $column ] = in_array( $column, self::RESULT_JSON, true ) ? wp_json_encode( (array) $result[ $column ] ) : $result[ $column ];
		}

		return $row;
	}
}
