<?php
/**
 * Puts a plugin or theme back to the version before the update.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Restores a plugin or theme from the zip made before its update: unzips it
 * to a temp folder, swaps the folders, clears OPcache for the files and runs
 * the smoke test again. Database changes the new version made are not undone;
 * the run lists the version options that changed so you know.
 *
 * Auto rollback (off by default) only fires for a red run whose errors are
 * blamed on the updated item, or when one item was updated. Core is never
 * rolled back.
 */
class Rollback implements Service {

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
	 * Sets up rollbacks.
	 *
	 * @param Settings $settings Settings.
	 * @param Runner   $runner   The runner.
	 */
	public function __construct( Settings $settings, Runner $runner ) {
		$this->settings = $settings;
		$this->runner   = $runner;
	}

	/**
	 * Hooks auto rollback.
	 */
	public function register(): void {
		add_action( 'axle_smoke_test_run_finished', array( $this, 'maybe_auto' ), 20 );
	}

	/**
	 * What a run can roll back: each updated plugin or theme with a backup of
	 * the version it had before.
	 *
	 * @param array $run The run.
	 * @return array[] Each: type, id, name, current, version (restored), backup.
	 */
	public function candidates( array $run ): array {
		$candidates = array();
		foreach ( (array) ( $run['context']['items'] ?? array() ) as $item ) {
			$item = (array) $item;
			$type = (string) ( $item['type'] ?? '' );
			if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || '' === (string) ( $item['from'] ?? '' ) ) {
				continue;
			}
			$id     = (string) ( $item['id'] ?? '' );
			$backup = Backups::find( $type, $id, (string) $item['from'] );
			if ( ! $backup ) {
				continue;
			}
			$current      = 'plugin' === $type ? Triggers::plugin_version( $id ) : (string) wp_get_theme( $id )->get( 'Version' );
			$candidates[] = array(
				'type'    => $type,
				'id'      => $id,
				'slug'    => 'plugin' === $type ? (string) preg_replace( '/\.php$/', '', (string) strtok( $id, '/' ) ) : $id,
				'name'    => (string) ( $item['name'] ?? $id ),
				'current' => $current,
				'version' => (string) $backup['version'],
				'backup'  => $backup,
			);
		}

		return $candidates;
	}

	/**
	 * Rolls back an item of a run and runs the smoke test again.
	 *
	 * @param int    $run_id  Run ID.
	 * @param string $item    Plugin file, plugin folder or theme folder. Empty when the run has one candidate.
	 * @param int    $user_id Who asked.
	 * @param bool   $rerun   Run the smoke test again afterwards.
	 * @return array|\WP_Error item, db_versions and, with $rerun, run (the new run).
	 */
	public function rollback( int $run_id, string $item = '', int $user_id = 0, bool $rerun = true ) {
		$run = $this->runner->runs()->get( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'axle_smoke_test_no_run', __( 'No such run.', 'axle-smoke-test' ), array( 'status' => 404 ) );
		}
		if ( Family::frozen() ) {
			return new \WP_Error( 'axle_smoke_test_frozen', __( 'The site is in read-only mode (Axle Read-Only Mode). Unfreeze it first, then roll back.', 'axle-smoke-test' ), array( 'status' => 423 ) );
		}

		$candidates = $this->candidates( $run );
		if ( ! $candidates ) {
			return new \WP_Error( 'axle_smoke_test_no_backup', __( 'Nothing to roll back: this run has no plugin or theme update with a backup. Core updates are never rolled back.', 'axle-smoke-test' ), array( 'status' => 409 ) );
		}
		$chosen = null;
		foreach ( $candidates as $candidate ) {
			if ( '' === $item ? 1 === count( $candidates ) : in_array( $item, array( $candidate['id'], $candidate['slug'] ), true ) ) {
				$chosen = $candidate;
				break;
			}
		}
		if ( ! $chosen ) {
			return new \WP_Error( 'axle_smoke_test_pick_item', __( 'Say which plugin or theme to roll back.', 'axle-smoke-test' ), array( 'status' => 400 ) );
		}

		/**
		 * Fires before a plugin or theme is rolled back.
		 *
		 * @param array $candidate type, id, name, current, version, backup.
		 * @param array $run       The run that offered it.
		 */
		do_action( 'axle_smoke_test_before_rollback', $chosen, $run );

		$restored = $this->restore( $chosen );
		if ( is_wp_error( $restored ) ) {
			/**
			 * Fires when a rollback failed. The plugin or theme was left as it was.
			 *
			 * @param array     $candidate What was to be rolled back.
			 * @param \WP_Error $error     Why it failed.
			 * @param array     $run       The run.
			 */
			do_action( 'axle_smoke_test_rollback_failed', $chosen, $restored, $run );
			return $restored;
		}

		$summary                  = $run['summary'];
		$summary['rolled_back']   = (array) ( $summary['rolled_back'] ?? array() );
		$summary['rolled_back'][] = array(
			'id'   => $chosen['id'],
			'name' => $chosen['name'],
			'from' => $chosen['current'],
			'to'   => $chosen['version'],
			'user' => $user_id,
			'time' => time(),
		);
		$this->runner->runs()->update( $run_id, array( 'summary' => $summary ) );

		/**
		 * Fires after a plugin or theme was rolled back.
		 *
		 * @param array $candidate type, id, name, current (the broken version), version (now installed), backup.
		 * @param array $run       The run that offered it.
		 */
		do_action( 'axle_smoke_test_rolled_back', $chosen, $run );

		$out = array(
			'item'        => array_diff_key( $chosen, array( 'backup' => true ) ),
			'db_versions' => (array) ( $run['summary']['db_versions'] ?? array() ),
		);
		if ( $rerun ) {
			$new = $this->runner->start(
				'rollback',
				array(
					'items'  => array(
						array(
							'type' => $chosen['type'],
							'id'   => $chosen['id'],
							'name' => $chosen['name'],
							'from' => $chosen['current'],
							'to'   => $chosen['version'],
						),
					),
					/* translators: 1: plugin or theme name, 2: version. */
					'reason' => sprintf( __( 'After rolling back %1$s to %2$s', 'axle-smoke-test' ), $chosen['name'], $chosen['version'] ),
					'after'  => $run_id,
				),
				$user_id
			);
			$out['run'] = is_wp_error( $new ) ? null : $new;
		}

		return $out;
	}

	/**
	 * Swaps the item's folder for the one in the backup.
	 *
	 * @param array $candidate Candidate.
	 * @return true|\WP_Error
	 */
	public function restore( array $candidate ) {
		$fs = Storage::fs();
		if ( ! $fs ) {
			return new \WP_Error( 'axle_smoke_test_no_fs', __( 'WordPress cannot write files directly on this server, so it cannot roll back. Restore the backup zip by hand.', 'axle-smoke-test' ) );
		}

		$zip    = Backups::path( $candidate['backup'] );
		$target = Backups::source_path( $candidate['type'], $candidate['id'] );
		if ( '' === $target ) {
			$target = 'theme' === $candidate['type'] ? get_theme_root() . '/' . $candidate['id'] : WP_PLUGIN_DIR . '/' . dirname( $candidate['id'] );
		}
		if ( ! file_exists( $zip ) ) {
			return new \WP_Error( 'axle_smoke_test_no_backup', __( 'The backup zip is gone.', 'axle-smoke-test' ) );
		}

		$temp = trailingslashit( $fs->wp_content_dir() ) . 'upgrade/axle-smoke-test-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $temp ) ) {
			return new \WP_Error( 'axle_smoke_test_no_temp', __( 'Could not create a temp folder in wp-content/upgrade.', 'axle-smoke-test' ) );
		}

		// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- A temp folder in wp-content/upgrade, as core's upgrader uses, deleted right after the swap.
		$unzipped = unzip_file( $zip, $temp );
		if ( is_wp_error( $unzipped ) ) {
			$fs->delete( $temp, true );
			return $unzipped;
		}

		$extracted = $temp . '/' . basename( $target );
		if ( ! $fs->exists( $extracted ) ) {
			$fs->delete( $temp, true );
			return new \WP_Error( 'axle_smoke_test_bad_backup', __( 'The backup zip does not hold the expected folder.', 'axle-smoke-test' ) );
		}

		$old = $target . '.axle-old-' . wp_generate_password( 6, false );
		if ( $fs->exists( $target ) && ! $fs->move( $target, $old ) ) {
			$fs->delete( $temp, true );
			return new \WP_Error( 'axle_smoke_test_move_failed', __( 'Could not move the current files aside.', 'axle-smoke-test' ) );
		}
		if ( ! $fs->move( $extracted, $target ) ) {
			if ( $fs->exists( $old ) ) {
				$fs->move( $old, $target );
			}
			$fs->delete( $temp, true );
			return new \WP_Error( 'axle_smoke_test_move_failed', __( 'Could not put the backup in place. The current version was kept.', 'axle-smoke-test' ) );
		}
		$fs->delete( $old, true );
		$fs->delete( $temp, true );

		self::clear_opcache( $target );
		self::clear_web_opcache( $target );
		if ( 'plugin' === $candidate['type'] ) {
			wp_clean_plugins_cache( true );
		} else {
			wp_clean_themes_cache( true );
		}

		return true;
	}

	/**
	 * Drops the restored PHP files from OPcache, so the old code runs at once.
	 *
	 * @param string $path Folder or file.
	 */
	public static function clear_opcache( string $path ): void {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}
		if ( is_file( $path ) ) {
			opcache_invalidate( $path, true ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.opcache_opcache_invalidate -- Only the files a rollback just put back, so the old code runs at once.
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				opcache_invalidate( $file->getPathname(), true ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.opcache_opcache_invalidate -- See above.
			}
		}
	}

	/**
	 * OPcache belongs to the web server's PHP, not to WP-CLI or a cron
	 * process. A loopback request with a one-time token asks the web server to
	 * drop the restored files too.
	 *
	 * @param string $path Folder or file.
	 */
	public static function clear_web_opcache( string $path ): void {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::opcache_key( $token ), $path, MINUTE_IN_SECONDS );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- A loopback to this site.
		wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- One loopback right after a rollback.
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter.
				'headers'     => array( 'X-Axle-Smoke-Opcache' => $token ),
			)
		);
		delete_transient( self::opcache_key( $token ) );
	}

	/**
	 * Runs early in every request: with a valid token from
	 * clear_web_opcache(), drops the restored files from this server's OPcache.
	 */
	public static function maybe_clear_opcache_from_request(): void {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- Checked against a stored hash below.
		$token = isset( $_SERVER['HTTP_X_AXLE_SMOKE_OPCACHE'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_AXLE_SMOKE_OPCACHE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only [a-f0-9] is accepted.
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) || ! function_exists( 'get_transient' ) ) {
			return;
		}
		$path = get_transient( self::opcache_key( $token ) );
		if ( is_string( $path ) && '' !== $path ) {
			delete_transient( self::opcache_key( $token ) );
			self::clear_opcache( $path );
		}
	}

	/**
	 * The transient for an OPcache token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function opcache_key( string $token ): string {
		return 'axle_smoke_test_oc_' . substr( hash( 'sha256', $token ), 0, 32 );
	}

	/**
	 * Auto rollback after a red run, when switched on.
	 *
	 * @param array $run The run.
	 */
	public function maybe_auto( $run ): void {
		$run = (array) $run;
		if ( Verdict::RED !== ( $run['verdict'] ?? '' ) || 'rollback' === ( $run['trigger_type'] ?? '' ) || ! empty( $run['summary']['auto_rollback'] ) ) {
			return;
		}

		$items = (array) ( $run['context']['items'] ?? array() );
		foreach ( $this->candidates( $run ) as $candidate ) {
			$prefix   = 'plugin' === $candidate['type'] ? 'plugin:' . $candidate['slug'] : 'theme:' . $candidate['id'];
			$blamed   = isset( $run['summary']['sources'][ $prefix ] );
			$eligible = (bool) $this->settings->get( 'auto_rollback' ) && ( $blamed || 1 === count( $items ) );

			/**
			 * Filters whether a red run rolls back an updated plugin or theme by itself.
			 *
			 * @param bool  $rollback  Default: the auto rollback setting (off), and the
			 *                         errors are blamed on the item or it was the only update.
			 * @param array $candidate type, id, name, current, version, backup.
			 * @param array $run       The red run.
			 */
			if ( ! apply_filters( 'axle_smoke_test_auto_rollback', $eligible, $candidate, $run ) ) {
				continue;
			}

			$summary                  = $run['summary'];
			$summary['auto_rollback'] = true;
			$this->runner->runs()->update( (int) $run['id'], array( 'summary' => $summary ) );
			$run['summary'] = $summary;
			$this->rollback( (int) $run['id'], $candidate['id'], 0, true );
			$run = $this->runner->runs()->get( (int) $run['id'] ) ?? $run;
		}
	}
}
