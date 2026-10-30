<?php
/**
 * A fingerprint of the site's code, to notice deploys.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Collects what changes when code is deployed outside WordPress (git, rsync,
 * a CI pipeline): the version and main-file modification time of every
 * plugin and theme, .git/HEAD and composer.lock.
 */
final class Fingerprint {

	public const OPTION = 'axle_smoke_test_fingerprint';

	/**
	 * The parts of the fingerprint, by name.
	 *
	 * @return array<string, string>
	 */
	public static function parts(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$parts = array();
		wp_clean_plugins_cache( false );
		foreach ( get_plugins() as $file => $data ) {
			$path                       = WP_PLUGIN_DIR . '/' . $file;
			$parts[ 'plugin:' . $file ] = (string) ( $data['Version'] ?? '' ) . '@' . ( file_exists( $path ) ? (int) filemtime( $path ) : 0 );
		}
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$path                            = $theme->get_stylesheet_directory() . '/style.css';
			$parts[ 'theme:' . $stylesheet ] = (string) $theme->get( 'Version' ) . '@' . ( file_exists( $path ) ? (int) filemtime( $path ) : 0 );
		}
		foreach ( array( ABSPATH . '.git/HEAD', WP_CONTENT_DIR . '/.git/HEAD', dirname( ABSPATH ) . '/.git/HEAD' ) as $head ) {
			if ( is_readable( $head ) ) {
				$parts[ 'git:' . $head ] = (string) md5_file( $head ) . '@' . (int) filemtime( $head );
			}
		}
		foreach ( array( ABSPATH . 'composer.lock', WP_CONTENT_DIR . '/composer.lock', dirname( ABSPATH ) . '/composer.lock' ) as $lock ) {
			if ( is_readable( $lock ) ) {
				$parts[ 'composer:' . $lock ] = (string) md5_file( $lock );
			}
		}
		$parts['core'] = (string) get_bloginfo( 'version' );

		/**
		 * Filters what makes up the code fingerprint the deploy check compares.
		 *
		 * @param array<string, string> $parts Name => value, e.g. "plugin:akismet/akismet.php" => "5.3@1714000000".
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_fingerprint_parts', $parts );

		return array_map( 'strval', $filtered );
	}

	/**
	 * The names of parts that differ between two fingerprints.
	 *
	 * @param array $before Old parts.
	 * @param array $after  New parts.
	 * @return string[]
	 */
	public static function diff( array $before, array $after ): array {
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $name ) {
			if ( ( $before[ $name ] ?? null ) !== ( $after[ $name ] ?? null ) ) {
				$changed[] = (string) $name;
			}
		}
		sort( $changed );

		return $changed;
	}

	/**
	 * The stored fingerprint.
	 *
	 * @return array<string, string>
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Saves a fingerprint.
	 *
	 * @param array $parts Parts.
	 */
	public static function save( array $parts ): void {
		update_option( self::OPTION, $parts, false );
	}

	/**
	 * Changed items for a run's context, from the changed part names.
	 *
	 * @param array    $before  Old parts.
	 * @param array    $after   New parts.
	 * @param string[] $changed Changed part names.
	 * @return array[]
	 */
	public static function items( array $before, array $after, array $changed ): array {
		$items = array();
		foreach ( $changed as $name ) {
			list( $type, $id ) = array_pad( explode( ':', $name, 2 ), 2, '' );
			$from              = (string) strtok( (string) ( $before[ $name ] ?? '' ), '@' );
			$to                = (string) strtok( (string) ( $after[ $name ] ?? '' ), '@' );
			$label             = $id;
			if ( 'plugin' === $type ) {
				$plugins = get_plugins();
				$label   = (string) ( $plugins[ $id ]['Name'] ?? $id );
			} elseif ( 'theme' === $type ) {
				$theme = wp_get_theme( $id );
				$label = $theme->exists() ? (string) $theme->get( 'Name' ) : $id;
			} elseif ( 'core' === $type || 'core' === $name ) {
				$type  = 'core';
				$label = 'WordPress';
				$from  = (string) ( $before['core'] ?? '' );
				$to    = (string) ( $after['core'] ?? '' );
			} else {
				$label = 'git' === $type ? __( 'Git checkout', 'axle-smoke-test' ) : __( 'Composer packages', 'axle-smoke-test' );
				$from  = '';
				$to    = '';
			}
			$items[] = array(
				'type' => $type,
				'id'   => $id,
				'name' => $label,
				'from' => $from,
				'to'   => $to,
			);
		}

		return $items;
	}
}
