<?php
/**
 * File access for backups, thumbnails and the early must-use plugin.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the WordPress filesystem API and closes the plugin's folders to the web.
 */
final class Storage {

	/**
	 * The filesystem, when WordPress can write files directly.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	public static function fs(): ?\WP_Filesystem_Base {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			if ( 'direct' !== get_filesystem_method() ) {
				return null;
			}
			WP_Filesystem();
		}

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Adds an empty index.php and deny rules for Apache and IIS to a folder.
	 * Nginx ignores both: docs/REFERENCE.md has the location block.
	 *
	 * @param string $dir Folder.
	 */
	public static function protect( string $dir ): void {
		$fs = self::fs();
		if ( ! $fs ) {
			return;
		}

		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Axle Smoke Test: nothing in this folder is public.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
		);
		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! $fs->exists( $path ) ) {
				$fs->put_contents( $path, $contents, FS_CHMOD_FILE );
			}
		}
	}

	/**
	 * The thumbnails folder in uploads.
	 *
	 * @return string
	 */
	public static function thumbs_dir(): string {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'axle-smoke-test';
	}

	/**
	 * Deletes a folder and everything in it.
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	public static function delete_dir( string $dir ): bool {
		$fs = self::fs();
		return $fs && $fs->exists( $dir ) ? (bool) $fs->delete( $dir, true ) : false;
	}
}
