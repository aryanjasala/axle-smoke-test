<?php
/**
 * Zips each plugin and theme before it is updated.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Before a plugin or theme update replaces the files, zips the current
 * folder to wp-content/axle-smoke-test/backups/, which is closed to the web.
 * Keeps the last two per item, for 14 days, and skips folders over 100 MB.
 */
class Backups implements Service {

	public const OPTION = 'axle_smoke_test_backups';

	/**
	 * Hooks into updates.
	 */
	public function register(): void {
		add_filter( 'upgrader_pre_install', array( $this, 'before_update' ), 10, 2 );
	}

	/**
	 * The backups folder.
	 *
	 * @return string
	 */
	public static function dir(): string {
		return WP_CONTENT_DIR . '/axle-smoke-test/backups';
	}

	/**
	 * Zips a plugin or theme about to be updated. Never stops the update.
	 *
	 * @param mixed $response   Passed through.
	 * @param array $hook_extra plugin or theme.
	 * @return mixed
	 */
	public function before_update( $response, $hook_extra ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$hook_extra = (array) $hook_extra;
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$file = (string) $hook_extra['plugin'];
			$this->create( 'plugin', $file, Triggers::plugin_version( $file ) );
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$theme = wp_get_theme( (string) $hook_extra['theme'] );
			if ( $theme->exists() ) {
				$this->create( 'theme', (string) $hook_extra['theme'], (string) $theme->get( 'Version' ) );
			}
		}

		return $response;
	}

	/**
	 * The folder (or single file) a plugin or theme lives in.
	 *
	 * @param string $type plugin or theme.
	 * @param string $item Plugin file or theme folder.
	 * @return string
	 */
	public static function source_path( string $type, string $item ): string {
		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $item );
			return $theme->exists() ? $theme->get_stylesheet_directory() : '';
		}
		$dir = dirname( $item );

		return '.' === $dir ? WP_PLUGIN_DIR . '/' . $item : WP_PLUGIN_DIR . '/' . $dir;
	}

	/**
	 * Zips a plugin or theme.
	 *
	 * @param string $type    plugin or theme.
	 * @param string $item    Plugin file or theme folder.
	 * @param string $version Its version now.
	 * @return array|\WP_Error The backup entry.
	 */
	public function create( string $type, string $item, string $version ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'axle_smoke_test_no_zip', __( 'PHP has no ZipArchive, so no backup was made.', 'axle-smoke-test' ) );
		}

		$source = self::source_path( $type, $item );
		if ( '' === $source || ! file_exists( $source ) ) {
			return new \WP_Error( 'axle_smoke_test_no_source', __( 'The files to back up were not found.', 'axle-smoke-test' ) );
		}

		$files = self::files( $source );
		$size  = 0;
		foreach ( $files as $file ) {
			$size += (int) filesize( $file );
		}
		/**
		 * Filters the largest plugin or theme that is backed up before an update, in bytes.
		 *
		 * @param int    $bytes Default 100 MB.
		 * @param string $type  plugin or theme.
		 * @param string $item  Plugin file or theme folder.
		 */
		$max = Settings::filtered_int( 'backup_max_bytes', 100 * MB_IN_BYTES, 0, PHP_INT_MAX, $type, $item );
		if ( $size > $max ) {
			return new \WP_Error( 'axle_smoke_test_too_big', __( 'Too big to back up.', 'axle-smoke-test' ) );
		}

		if ( ! self::prepare_dir() ) {
			return new \WP_Error( 'axle_smoke_test_not_writable', __( 'The backups folder is not writable.', 'axle-smoke-test' ) );
		}

		$slug = sanitize_file_name( 'plugin' === $type ? (string) strtok( $item, '/' ) : $item );
		$slug = (string) preg_replace( '/\.php$/', '', $slug );
		$name = $slug . '-' . sanitize_file_name( '' !== $version ? $version : 'unknown' ) . '-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false ) . '.zip';
		$path = self::dir() . '/' . $name;

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'axle_smoke_test_zip_failed', __( 'The backup zip could not be created.', 'axle-smoke-test' ) );
		}
		$base = is_dir( $source ) ? dirname( $source ) : dirname( $source );
		foreach ( $files as $file ) {
			$zip->addFile( $file, ltrim( substr( $file, strlen( $base ) ), '/' ) );
		}
		$zip->close();

		$entry = array(
			'id'      => substr( md5( $name ), 0, 12 ),
			'type'    => $type,
			'item'    => $item,
			'version' => $version,
			'file'    => $name,
			'size'    => file_exists( $path ) ? (int) filesize( $path ) : 0,
			'created' => time(),
		);

		$index   = self::all();
		$index[] = $entry;
		self::save( $index );
		$this->prune( $type, $item );

		/**
		 * Fires after a plugin or theme was zipped before an update.
		 *
		 * @param array $entry id, type, item, version, file, size, created.
		 */
		do_action( 'axle_smoke_test_backup_created', $entry );

		return $entry;
	}

	/**
	 * Every backup, oldest first.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		$index = get_option( self::OPTION, array() );
		return is_array( $index ) ? array_values( array_filter( $index, 'is_array' ) ) : array();
	}

	/**
	 * Saves the index.
	 *
	 * @param array[] $index Entries.
	 */
	private static function save( array $index ): void {
		update_option( self::OPTION, array_values( $index ), false );
	}

	/**
	 * The newest backup of an item, optionally of one version.
	 *
	 * @param string $type    plugin or theme.
	 * @param string $item    Plugin file or theme folder.
	 * @param string $version Version, or empty for any.
	 * @return array|null
	 */
	public static function find( string $type, string $item, string $version = '' ): ?array {
		$found = null;
		foreach ( self::all() as $entry ) {
			if ( $entry['type'] === $type && $entry['item'] === $item && ( '' === $version || (string) $entry['version'] === $version ) && file_exists( self::dir() . '/' . $entry['file'] ) ) {
				$found = $entry;
			}
		}

		return $found;
	}

	/**
	 * The full path of a backup.
	 *
	 * @param array $entry Backup entry.
	 * @return string
	 */
	public static function path( array $entry ): string {
		return self::dir() . '/' . basename( (string) $entry['file'] );
	}

	/**
	 * Keeps the newest backups of an item.
	 *
	 * @param string $type plugin or theme.
	 * @param string $item Plugin file or theme folder.
	 */
	private function prune( string $type, string $item ): void {
		/**
		 * Filters how many backups are kept per plugin or theme.
		 *
		 * @param int $keep Default 2.
		 */
		$keep  = Settings::filtered_int( 'backups_to_keep', 2, 1, 50 );
		$index = self::all();
		$mine  = array_keys(
			array_filter(
				$index,
				static function ( $entry ) use ( $type, $item ) {
					return $entry['type'] === $type && $entry['item'] === $item;
				}
			)
		);
		$drop  = array_slice( $mine, 0, max( 0, count( $mine ) - $keep ) );
		foreach ( $drop as $position ) {
			wp_delete_file( self::path( $index[ $position ] ) );
			unset( $index[ $position ] );
		}
		self::save( $index );
	}

	/**
	 * Deletes backups older than the age limit, or all of them.
	 *
	 * @param bool $all Delete every backup.
	 * @return int Backups deleted.
	 */
	public static function purge( bool $all = false ): int {
		$cutoff  = time() - 14 * DAY_IN_SECONDS;
		$kept    = array();
		$deleted = 0;
		foreach ( self::all() as $entry ) {
			if ( $all || (int) $entry['created'] < $cutoff || ! file_exists( self::path( $entry ) ) ) {
				wp_delete_file( self::path( $entry ) );
				++$deleted;
				continue;
			}
			$kept[] = $entry;
		}
		self::save( $kept );

		return $deleted;
	}

	/**
	 * Creates the folder with its deny rules.
	 *
	 * @return bool Whether it is writable.
	 */
	public static function prepare_dir(): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		Storage::protect( dirname( $dir ) );
		Storage::protect( $dir );

		return wp_is_writable( $dir );
	}

	/**
	 * Every file under a folder (or the file itself).
	 *
	 * @param string $source Folder or file.
	 * @return string[]
	 */
	private static function files( string $source ): array {
		if ( is_file( $source ) ) {
			return array( $source );
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && $file->isFile() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}
}
