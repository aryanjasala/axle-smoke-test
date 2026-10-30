<?php
/**
 * Works out which plugin or theme an error came from.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a file path (PHP errors) or a script URL (JavaScript errors, missing
 * files) to its source: plugin:<folder>, mu-plugin:<file>, theme:<stylesheet>,
 * core, or unknown.
 */
final class Attribution {

	/**
	 * Folders and URLs to match against, by source type.
	 *
	 * @var array<string, string[]>
	 */
	private array $roots;

	/**
	 * Sets up the matcher.
	 *
	 * @param array<string, string[]>|null $roots Paths and URLs per type: plugin, mu-plugin, theme, core. Default: this site's.
	 */
	public function __construct( ?array $roots = null ) {
		$this->roots = $roots ?? self::site_roots();
	}

	/**
	 * This site's plugin, theme and core folders and URLs.
	 *
	 * @return array<string, string[]>
	 */
	public static function site_roots(): array {
		$themes = array( get_theme_root(), get_theme_root_uri() );
		foreach ( (array) ( $GLOBALS['wp_theme_directories'] ?? array() ) as $dir ) {
			$themes[] = (string) $dir;
		}

		return array(
			'plugin'    => array( WP_PLUGIN_DIR, plugins_url() ),
			'mu-plugin' => array( WPMU_PLUGIN_DIR, content_url( basename( WPMU_PLUGIN_DIR ) ) ),
			'theme'     => array_values( array_unique( $themes ) ),
			'core'      => array( ABSPATH . 'wp-includes', ABSPATH . 'wp-admin', includes_url(), admin_url() ),
		);
	}

	/**
	 * The source of a file path or URL.
	 *
	 * @param string $location File path or URL.
	 * @param array  $error    The error, for the filter.
	 * @return string plugin:<folder>, mu-plugin:<file>, theme:<stylesheet>, core or unknown.
	 */
	public function source( string $location, array $error = array() ): string {
		$source = $this->match( $location );

		/**
		 * Filters which plugin or theme an error, JavaScript error or missing file is blamed on.
		 *
		 * @param string $source   plugin:<folder>, mu-plugin:<file>, theme:<stylesheet>, core or unknown.
		 * @param string $location The file path or URL it came from.
		 * @param array  $error    The error.
		 */
		$filtered = (string) apply_filters( 'axle_smoke_test_attribute_error', $source, $location, $error );

		return '' !== $filtered ? $filtered : $source;
	}

	/**
	 * The unfiltered match.
	 *
	 * @param string $location File path or URL.
	 * @return string
	 */
	private function match( string $location ): string {
		$location = str_replace( '\\', '/', (string) preg_replace( '/[?#].*$/s', '', $location ) );
		if ( '' === $location ) {
			return 'unknown';
		}

		foreach ( array( 'plugin', 'mu-plugin', 'theme', 'core' ) as $type ) {
			foreach ( $this->roots[ $type ] ?? array() as $root ) {
				$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
				if ( '' === $root ) {
					continue;
				}
				$end = self::after( $location, $root );
				if ( null === $end ) {
					continue;
				}
				if ( 'core' === $type ) {
					return 'core';
				}

				$rest = ltrim( substr( $location, $end ), '/' );
				$part = explode( '/', $rest )[0];
				if ( '' === $part ) {
					continue;
				}

				return $type . ':' . $part;
			}
		}

		// A path that only shares the wp-content layout with this site, e.g. from a debug.log of another install.
		if ( preg_match( '#/wp-content/(plugins|mu-plugins|themes)/([^/]+)#', $location, $match ) ) {
			$types = array(
				'plugins'    => 'plugin',
				'mu-plugins' => 'mu-plugin',
				'themes'     => 'theme',
			);
			return $types[ $match[1] ] . ':' . $match[2];
		}
		if ( preg_match( '#/(wp-includes|wp-admin)/#', $location ) ) {
			return 'core';
		}

		return 'unknown';
	}

	/**
	 * Where a location continues after a root it starts with, matching URLs
	 * with or without their scheme.
	 *
	 * @param string $location Path or URL.
	 * @param string $root     Folder or URL.
	 * @return int|null Offset after the root, or null when it does not start with it.
	 */
	private static function after( string $location, string $root ): ?int {
		if ( 0 === strpos( $location, $root . '/' ) ) {
			return strlen( $root );
		}

		$bare_root = (string) preg_replace( '#^https?:#i', '', $root );
		$bare_loc  = (string) preg_replace( '#^https?:#i', '', $location );
		if ( $bare_root !== $root && 0 === strpos( $bare_loc, $bare_root . '/' ) ) {
			return strlen( $location ) - strlen( $bare_loc ) + strlen( $bare_root );
		}

		return null;
	}

	/**
	 * A readable name for a source: the plugin or theme name.
	 *
	 * @param string $source Source from source().
	 * @return string
	 */
	public static function label( string $source ): string {
		if ( 'core' === $source ) {
			return __( 'WordPress core', 'axle-smoke-test' );
		}
		if ( 'unknown' === $source || false === strpos( $source, ':' ) ) {
			return __( 'Unknown source', 'axle-smoke-test' );
		}

		list( $type, $name ) = explode( ':', $source, 2 );
		if ( 'plugin' === $type ) {
			$plugin = self::plugin_file( $name );
			if ( $plugin ) {
				$plugins = get_plugins();
				return isset( $plugins[ $plugin ]['Name'] ) ? (string) $plugins[ $plugin ]['Name'] : $name;
			}
			return $name;
		}
		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $name );
			return $theme->exists() ? (string) $theme->get( 'Name' ) : $name;
		}

		/* translators: %s: must-use plugin file name. */
		return sprintf( __( 'Must-use plugin %s', 'axle-smoke-test' ), $name );
	}

	/**
	 * The plugin file (folder/file.php) for a plugin folder or single file.
	 *
	 * @param string $folder Folder name, or a single-file plugin's file name.
	 * @return string Empty when not installed.
	 */
	public static function plugin_file( string $folder ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( $file === $folder || 0 === strpos( $file, $folder . '/' ) ) {
				return $file;
			}
		}

		return '';
	}
}
