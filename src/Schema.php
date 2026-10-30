<?php
/**
 * Tables, schedules, activation and deactivation.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the runs and results tables and schedules the deploy
 * check and the daily clean-up.
 *
 * The version check runs on every load, so sites added to a network after
 * the plugin was network activated get their tables on their first request.
 */
final class Schema implements Service {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'axle_smoke_test_db_version';

	public const RUN_HOOK     = 'axle_smoke_test_run';
	public const DEPLOY_HOOK  = 'axle_smoke_test_deploy_check';
	public const CLEANUP_HOOK = 'axle_smoke_test_cleanup';
	public const DAILY_HOOK   = 'axle_smoke_test_daily';
	public const SCHEDULE     = 'axle_smoke_test_5min';

	/**
	 * The runs table.
	 *
	 * @return string
	 */
	public static function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'axle_smoke_runs';
	}

	/**
	 * The results table.
	 *
	 * @return string
	 */
	public static function results_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'axle_smoke_results';
	}

	/**
	 * Keeps the tables and schedules current.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minutes is the deploy check the plugin is about.
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * The five-minute schedule for the deploy check.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ): array {
		$schedules                   = (array) $schedules;
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every five minutes (Axle Smoke Test)',
		);

		return $schedules;
	}

	/**
	 * Schedules the recurring events that are missing.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::DEPLOY_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, self::DEPLOY_HOOK );
		}
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Activation: tables and schedules.
	 */
	public static function activate(): void {
		self::install();
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- See register().
		self::schedule();
	}

	/**
	 * Deactivation: stops every schedule and removes the early must-use
	 * plugin. Runs, results, settings and backups stay until uninstall.
	 */
	public static function deactivate(): void {
		foreach ( array( self::RUN_HOOK, self::DEPLOY_HOOK, self::CLEANUP_HOOK, self::DAILY_HOOK ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_unschedule_hook( self::RUN_HOOK );
		MuPlugin::remove();
	}

	/**
	 * Creates or upgrades the tables.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$runs    = self::runs_table();
		$results = self::results_table();

		dbDelta(
			"CREATE TABLE {$runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_gmt datetime NOT NULL,
				started_gmt datetime NULL,
				finished_gmt datetime NULL,
				trigger_type varchar(20) NOT NULL DEFAULT 'manual',
				context longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'queued',
				verdict varchar(10) NOT NULL DEFAULT '',
				server_done tinyint(1) NOT NULL DEFAULT 0,
				browser_done tinyint(1) NOT NULL DEFAULT 0,
				worker_done tinyint(1) NOT NULL DEFAULT 0,
				summary longtext NULL,
				triggered_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_gmt (created_gmt)
			) {$charset};
			CREATE TABLE {$results} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				url_key varchar(64) NOT NULL,
				url text NOT NULL,
				tier varchar(10) NOT NULL DEFAULT 'server',
				http_status smallint(5) unsigned NOT NULL DEFAULT 0,
				ttfb_ms int(10) unsigned NOT NULL DEFAULT 0,
				body_bytes int(10) unsigned NOT NULL DEFAULT 0,
				php_errors longtext NULL,
				js_errors longtext NULL,
				missing_assets longtext NULL,
				html_hash varchar(64) NOT NULL DEFAULT '',
				text_fingerprint text NULL,
				landmarks text NULL,
				layout longtext NULL,
				thumb_path varchar(255) NOT NULL DEFAULT '',
				verdict varchar(10) NOT NULL DEFAULT '',
				is_baseline tinyint(1) NOT NULL DEFAULT 0,
				meta longtext NULL,
				created_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY run_id (run_id),
				KEY baseline (url_key, tier, is_baseline)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}
}
