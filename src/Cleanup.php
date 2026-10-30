<?php
/**
 * The daily clean-up.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes old runs (30 days or 200 runs by default; baselines are kept),
 * backups older than 14 days, and finishes runs that waited a day for a
 * browser.
 */
class Cleanup implements Service {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The runner.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Sets up the clean-up.
	 *
	 * @param Settings $settings Settings.
	 * @param Runner   $runner   The runner.
	 */
	public function __construct( Settings $settings, Runner $runner ) {
		$this->settings = $settings;
		$this->runner   = $runner;
	}

	/**
	 * Hooks the schedule.
	 */
	public function register(): void {
		add_action( Schema::CLEANUP_HOOK, array( $this, 'scheduled' ) );
	}

	/**
	 * The daily event.
	 */
	public function scheduled(): void {
		$this->run();
	}

	/**
	 * Cleans up.
	 *
	 * @param int|null $days Delete runs older than this many days. Default: the retention setting.
	 * @return array{runs: int, backups: int, expired: int}
	 */
	public function run( $days = null ): array {
		$runs = $this->runner->runs();

		$expired = 0;
		foreach ( $runs->list(
			array(
				'status'     => array( 'waiting_browser' ),
				'before_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'per_page'   => 100,
			)
		) as $run ) {
			$this->runner->complete( (int) $run['id'], 'browser' );
			++$expired;
		}

		/**
		 * Filters how many days runs are kept.
		 *
		 * @param int $days Default from the settings, 30.
		 */
		$days = null === $days ? Settings::filtered_int( 'retention_days', (int) $this->settings->get( 'retention_days' ), 1, 3650 ) : max( 0, (int) $days );
		/**
		 * Filters how many runs are kept at most.
		 *
		 * @param int $runs Default from the settings, 200.
		 */
		$keep = Settings::filtered_int( 'retention_runs', (int) $this->settings->get( 'retention_runs' ), 1, 100000 );

		$deleted = 0;
		for ( $batch = 0; $batch < 20; $batch++ ) {
			$ids = $runs->purgeable( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), $keep, 200 );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				foreach ( $runs->results( $id ) as $result ) {
					if ( '' !== $result['thumb_path'] && ! $result['is_baseline'] ) {
						wp_delete_file( Storage::thumbs_dir() . '/' . basename( $result['thumb_path'] ) );
					}
				}
			}
			$deleted += $runs->delete( $ids );
		}

		$backups = Backups::purge();
		$counts  = array(
			'runs'    => $deleted,
			'backups' => $backups,
			'expired' => $expired,
		);

		/**
		 * Fires after old runs and backups were deleted.
		 *
		 * @param array $counts runs, backups and expired (runs that stopped waiting for a browser).
		 */
		do_action( 'axle_smoke_test_purged', $counts );

		return $counts;
	}
}
