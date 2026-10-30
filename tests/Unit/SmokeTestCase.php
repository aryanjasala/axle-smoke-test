<?php
/**
 * Base class for Axle Smoke Test unit tests.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Stubs/axle-smoke-test.php';
require_once __DIR__ . '/FakeRuns.php';

/**
 * Stubs the WordPress functions most services call: transients, URLs,
 * capabilities, JSON and time.
 */
abstract class SmokeTestCase extends TestCase {

	/** @var array<string, mixed> Transients. */
	protected $transients = array();

	/** @var array<string, bool> Capabilities of the current user. */
	protected $caps = array( 'update_plugins' => true );

	/** @var int Current user. */
	protected $user_id = 1;

	protected function setUp(): void {
		parent::setUp();
		$this->transients               = array();
		$this->caps                     = array( 'update_plugins' => true );
		$this->user_id                  = 1;
		\WP_Session_Tokens::$sessions  = array();
		\WP_Theme::$themes             = array();

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'get_user_meta' )->justReturn( array() );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'get_feed_link' )->justReturn( 'https://example.com/feed/' );
		Functions\when( 'get_rest_url' )->justReturn( 'https://example.com/wp-json/' );
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.com/wp-login.php' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $value = '', $url = '' ) {
				if ( is_string( $args ) ) {
					$args = array( $args => $value );
				} else {
					$url = $value;
				}
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-10-30 10:00:00' );
		Functions\when( 'get_current_user_id' )->alias(
			function () {
				return $this->user_id;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return ! empty( $this->caps[ $cap ] );
			}
		);
		Functions\when( 'user_can' )->alias(
			function ( $user, $cap ) {
				return ! empty( $this->caps[ $cap ] );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_email' )->alias( 'trim' );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_list_pluck' )->alias(
			static function ( $list, $field ) {
				return array_map(
					static function ( $item ) use ( $field ) {
						return is_array( $item ) ? $item[ $field ] : $item->$field;
					},
					$list
				);
			}
		);
	}
}
