<?php
/**
 * Removes everything Axle Smoke Test stored, on every site: both tables,
 * options, transients, cron events, the backups and thumbnails folders and
 * the early must-use plugin.
 *
 * @package AryanX\Axle\SmokeTest
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes a folder and everything in it.
 *
 * @param string $dir Folder.
 */
function axle_smoke_test_uninstall_dir( string $dir ): void {
	global $wp_filesystem;

	if ( ! is_dir( $dir ) ) {
		return;
	}
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! $wp_filesystem && 'direct' === get_filesystem_method() ) {
		WP_Filesystem();
	}
	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $dir, true );
	}
}

/**
 * Removes Axle Smoke Test data from the current site.
 */
function axle_smoke_test_uninstall_site(): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-off cleanup on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}axle_smoke_runs" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}axle_smoke_results" );

	foreach ( array( 'settings', 'urls', 'fingerprint', 'db_version', 'backups', 'alert_log', 'notice' ) as $name ) {
		delete_option( 'axle_smoke_test_' . $name );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_axle_smoke_test_' ) . '%', $wpdb->esc_like( '_transient_timeout_axle_smoke_test_' ) . '%' ) );
	// phpcs:enable

	foreach ( array( 'axle_smoke_test_run', 'axle_smoke_test_deploy_check', 'axle_smoke_test_cleanup', 'axle_smoke_test_daily' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}

	$uploads = wp_get_upload_dir();
	axle_smoke_test_uninstall_dir( trailingslashit( $uploads['basedir'] ) . 'axle-smoke-test' );
}

if ( is_multisite() ) {
	$axle_smoke_test_offset = 0;
	do {
		$axle_smoke_test_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_smoke_test_offset,
			)
		);

		foreach ( $axle_smoke_test_sites as $axle_smoke_test_site ) {
			switch_to_blog( $axle_smoke_test_site );
			axle_smoke_test_uninstall_site();
			restore_current_blog();
		}

		$axle_smoke_test_offset += 100;
	} while ( $axle_smoke_test_sites );
} else {
	axle_smoke_test_uninstall_site();
}

// Shared by every site: the backups folder and the early must-use plugin.
axle_smoke_test_uninstall_dir( WP_CONTENT_DIR . '/axle-smoke-test' );
if ( file_exists( WPMU_PLUGIN_DIR . '/axle-smoke-test-early.php' ) ) {
	wp_delete_file( WPMU_PLUGIN_DIR . '/axle-smoke-test-early.php' );
}
