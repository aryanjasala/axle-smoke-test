<?php
/**
 * Stand-ins for the WordPress pieces Axle Smoke Test touches beyond the
 * shared stubs: paths, the filesystem API, sessions, users and themes.
 *
 * @package AryanX\Axle\SmokeTest
 */

// phpcs:ignoreFile

$axle_smoke_test_tmp = sys_get_temp_dir() . '/axle-smoke-test-unit-' . getmypid();
foreach ( array(
	'WP_CONTENT_DIR'    => $axle_smoke_test_tmp . '/wp-content',
	'WP_PLUGIN_DIR'     => $axle_smoke_test_tmp . '/wp-content/plugins',
	'WPMU_PLUGIN_DIR'   => $axle_smoke_test_tmp . '/wp-content/mu-plugins',
	'WPINC'             => 'wp-includes',
	'AUTH_COOKIE'       => 'wordpress_test',
	'SECURE_AUTH_COOKIE' => 'wordpress_sec_test',
	'LOGGED_IN_COOKIE'  => 'wordpress_logged_in_test',
	'FS_CHMOD_FILE'     => 0644,
	'ARRAY_A'           => 'ARRAY_A',
	'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' => 24,
) as $name => $value ) {
	defined( $name ) || define( $name, $value );
}

if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
	/**
	 * The direct filesystem, enough for backups and rollbacks.
	 */
	class WP_Filesystem_Base {
		public function wp_content_dir() {
			return WP_CONTENT_DIR . '/';
		}
		public function exists( $path ) {
			return file_exists( $path );
		}
		public function put_contents( $path, $contents, $mode = false ) {
			return false !== file_put_contents( $path, $contents );
		}
		public function move( $from, $to ) {
			return @rename( $from, $to );
		}
		public function delete( $path, $recursive = false ) {
			if ( is_file( $path ) ) {
				return unlink( $path );
			}
			if ( ! is_dir( $path ) ) {
				return false;
			}
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			return rmdir( $path );
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * A user with an ID.
	 */
	class WP_User {
		public $ID;
		public function __construct( $id = 0 ) {
			$this->ID = (int) $id;
		}
	}
}

if ( ! class_exists( 'WP_Session_Tokens' ) ) {
	/**
	 * In-memory sessions per user.
	 */
	class WP_Session_Tokens {
		/** @var array<int, array<string, array>> */
		public static $sessions = array();
		/** @var int */
		private $user;
		public static function get_instance( $user ) {
			$instance       = new self();
			$instance->user = (int) $user;
			return $instance;
		}
		public function create( $expiration ) {
			$token   = 'token' . count( self::$sessions[ $this->user ] ?? array() ) . bin2hex( random_bytes( 4 ) );
			$session = apply_filters( 'attach_session_information', array(), $this->user );
			self::$sessions[ $this->user ][ $token ] = array( 'expiration' => $expiration ) + (array) $session;
			return $token;
		}
		public function get( $token ) {
			return self::$sessions[ $this->user ][ $token ] ?? null;
		}
		public function destroy( $token ) {
			unset( self::$sessions[ $this->user ][ $token ] );
		}
	}
}

if ( ! class_exists( 'WP_Theme' ) ) {
	/**
	 * A theme by stylesheet.
	 */
	class WP_Theme {
		/** @var array<string, array> */
		public static $themes = array();
		/** @var string */
		private $stylesheet;
		public function __construct( $stylesheet ) {
			$this->stylesheet = $stylesheet;
		}
		public function exists() {
			return isset( self::$themes[ $this->stylesheet ] );
		}
		public function get( $field ) {
			return self::$themes[ $this->stylesheet ][ $field ] ?? '';
		}
		public function get_stylesheet() {
			return $this->stylesheet;
		}
		public function get_stylesheet_directory() {
			return WP_CONTENT_DIR . '/themes/' . $this->stylesheet;
		}
		public function cache_delete() {
		}
	}
}

defined( 'AryanX\Axle\SmokeTest\VERSION' ) || define( 'AryanX\Axle\SmokeTest\VERSION', '1.0.0' );
defined( 'AryanX\Axle\SmokeTest\PLUGIN_FILE' ) || define( 'AryanX\Axle\SmokeTest\PLUGIN_FILE', WP_PLUGIN_DIR . '/axle-smoke-test/axle-smoke-test.php' );
