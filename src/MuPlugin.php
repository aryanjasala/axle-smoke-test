<?php
/**
 * The optional early must-use plugin.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and removes one small file in wp-content/mu-plugins that starts
 * the PHP error collector before other plugins load. Only ever installed by
 * an admin from the settings; removed on deactivation and uninstall.
 */
final class MuPlugin {

	public const FILE = 'axle-smoke-test-early.php';

	/**
	 * Its path.
	 *
	 * @return string
	 */
	public static function path(): string {
		return WPMU_PLUGIN_DIR . '/' . self::FILE;
	}

	/**
	 * Whether it is installed.
	 *
	 * @return bool
	 */
	public static function installed(): bool {
		return file_exists( self::path() );
	}

	/**
	 * Writes it.
	 *
	 * @return true|\WP_Error
	 */
	public static function install() {
		$fs = Storage::fs();
		if ( ! $fs ) {
			return new \WP_Error( 'axle_smoke_test_no_fs', __( 'WordPress cannot write files directly on this server.', 'axle-smoke-test' ) );
		}
		if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return new \WP_Error( 'axle_smoke_test_no_mu_dir', __( 'Could not create wp-content/mu-plugins.', 'axle-smoke-test' ) );
		}

		$early    = var_export( dirname( PLUGIN_FILE ) . '/early.php', true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writes a PHP string literal.
		$contents = "<?php\n/**\n * Plugin Name: Axle Smoke Test early collector\n * Description: Starts Axle Smoke Test's PHP error collector before other plugins load, on smoke test requests only. Added from Tools > Smoke Test > Settings and removed when Axle Smoke Test is deactivated.\n */\n\ndefined( 'ABSPATH' ) || exit;\n\nif ( is_readable( {$early} ) ) {\n\trequire_once {$early};\n}\n";

		return $fs->put_contents( self::path(), $contents, FS_CHMOD_FILE ) ? true : new \WP_Error( 'axle_smoke_test_mu_write', __( 'Could not write the must-use plugin.', 'axle-smoke-test' ) );
	}

	/**
	 * Deletes it, if it is ours.
	 *
	 * @return bool Whether it is gone.
	 */
	public static function remove(): bool {
		$path = self::path();
		if ( ! file_exists( $path ) ) {
			return true;
		}
		wp_delete_file( $path );
		clearstatcache( true, $path );

		return ! is_file( $path );
	}
}
