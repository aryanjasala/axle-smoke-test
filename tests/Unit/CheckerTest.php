<?php
/**
 * Tests for the server tier.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Attribution;
use AryanX\Axle\SmokeTest\Checker;
use AryanX\Axle\SmokeTest\ErrorCollector;
use AryanX\Axle\SmokeTest\Settings;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class CheckerTest extends SmokeTestCase {

	/** @var array Requests made. */
	private $requests = array();

	/** @var array|\WP_Error What the next GET returns. */
	private $response;

	/** @var array<string, int> HEAD statuses by URL. */
	private $heads = array();

	protected function setUp(): void {
		parent::setUp();
		$this->requests = array();
		$this->heads    = array();
		$this->response = $this->reply( 200, '<html><head><script src="/app.js"></script></head><body><main><h1>Hi</h1><p>Plenty of visible text on this page for sure.</p></main></body></html>' );

		Functions\when( 'wp_rand' )->alias( 'mt_rand' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) {
				$this->requests[] = compact( 'url', 'args' );
				// The page's error collector saves what it caught under the request token.
				$token = $args['headers'][ ErrorCollector::HEADER ] ?? '';
				if ( $token && isset( $this->transients[ ErrorCollector::token_key( $token ) ] ) ) {
					$this->transients[ ErrorCollector::errors_key( $token ) ] = array(
						array(
							'type'    => 'warning',
							'message' => 'Undefined array key "x"',
							'file'    => WP_PLUGIN_DIR . '/shop/shop.php',
							'line'    => 9,
						),
					);
				}
				if ( is_array( $this->response ) && isset( $this->response['scrape'] ) ) {
					parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
					$key                    = $query['wp_scrape_key'];
					$this->response['body'] .= "\n###### wp_scraping_result_start:{$key} ######" . $this->response['scrape'] . "###### wp_scraping_result_end:{$key} ######";
				}
				return $this->response;
			}
		);
		Functions\when( 'wp_remote_head' )->alias(
			function ( $url ) {
				return $this->reply( $this->heads[ $url ] ?? 200, '' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $r ) {
				return $r['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $r ) {
				return $r['body'];
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $r, $name ) {
				return $r['headers'][ $name ] ?? '';
			}
		);
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/wp-content/plugins' );
	}

	private function reply( int $code, string $body, array $headers = array() ): array {
		return array(
			'code'    => $code,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	private function checker(): Checker {
		return new Checker(
			new Settings(),
			new Attribution(
				array(
					'plugin' => array( WP_PLUGIN_DIR, 'https://example.com/wp-content/plugins' ),
				)
			)
		);
	}

	private function url( array $extra = array() ): array {
		return array_merge(
			array(
				'key'      => 'home',
				'label'    => 'Home page',
				'url'      => 'https://example.com/',
				'expected' => array( 200 ),
				'browser'  => true,
				'auth'     => false,
				'kind'     => 'html',
			),
			$extra
		);
	}

	public function test_a_page_becomes_a_result_with_collected_warnings(): void {
		$this->heads['https://example.com/app.js'] = 404;

		$result = $this->checker()->check( $this->url(), 0 );

		$this->assertSame( 200, $result['http_status'] );
		$this->assertSame( 1, $result['landmarks']['main'] );
		$this->assertSame( 'plugin:shop', $result['php_errors'][0]['source'] );
		$this->assertSame( array( 'url' => 'https://example.com/app.js', 'status' => 404, 'source' => 'unknown' ), $result['missing_assets'][0] );
		$this->assertSame( 'public', $result['meta']['auth_mode'] );
		// Tokens and scrape keys are gone after the request.
		$this->assertSame( array(), array_filter( array_keys( $this->transients ), static function ( $key ) {
			return 0 === strpos( $key, 'scrape_key_' ) || 0 === strpos( $key, 'axle_smoke_test_r' );
		} ) );
		$request = $this->requests[0];
		$this->assertStringContainsString( 'wp_scrape_key=', $request['url'] );
		$this->assertStringContainsString( 'axle_smoke_nocache=', $request['url'] );
		$this->assertSame( 'no-cache', $request['args']['headers']['Cache-Control'] );
		$this->assertSame( 0, $request['args']['redirection'] );
	}

	public function test_core_scrape_marker_reports_the_fatal(): void {
		$this->response           = $this->reply( 500, '<html><body>broken' );
		$this->response['scrape'] = json_encode(
			array(
				'type'    => E_ERROR,
				'message' => "Uncaught Error: Call to undefined function x() in /p/shop.php:3\nStack trace:\n#0 {main}\n  thrown",
				'file'    => 'wp-content/plugins/shop/shop.php',
				'line'    => 3,
			)
		);

		$result = $this->checker()->check( $this->url(), 0 );

		$this->assertSame( 'Uncaught Error: Call to undefined function x()', $result['meta']['fatal']['message'] );
		$this->assertSame( ABSPATH . 'wp-content/plugins/shop/shop.php', $result['meta']['fatal']['file'] );
		$this->assertSame( 'fatal error', $result['meta']['fatal']['type'] );
		$this->assertStringNotContainsString( 'wp_scraping_result', $result['html_hash'] );
	}

	public function test_request_args_cache_bust_and_ignored_errors_filters(): void {
		Filters\expectApplied( 'axle_smoke_test_cache_bust' )->once()->andReturn( false );
		Filters\expectApplied( 'axle_smoke_test_request_args' )->once()->andReturnUsing(
			static function ( $args ) {
				$args['headers']['X-Test'] = 'yes';
				return $args;
			}
		);
		Filters\expectApplied( 'axle_smoke_test_ignored_php_errors' )->once()->andReturn( array( '/undefined array key/i' ) );

		$result = $this->checker()->check( $this->url(), 0 );

		$this->assertSame( array(), $result['php_errors'] );
		$this->assertSame( 'yes', $this->requests[0]['args']['headers']['X-Test'] );
		$this->assertArrayNotHasKey( 'Cache-Control', $this->requests[0]['args']['headers'] );
		$this->assertStringNotContainsString( 'axle_smoke_nocache', $this->requests[0]['url'] );
	}

	public function test_wp_admin_url_without_a_login_checks_the_login_redirect(): void {
		Filters\expectApplied( 'axle_smoke_test_admin_loopback_enabled' )->once()->andReturn( false );
		$this->response = $this->reply( 302, '', array( 'location' => 'https://example.com/wp-login.php?redirect_to=x' ) );

		$result = $this->checker()->check( $this->url( array( 'key' => 'dashboard', 'url' => 'https://example.com/wp-admin/', 'auth' => true ) ), 1 );

		$this->assertSame( 'dashboard-signed-out', $result['url_key'] );
		$this->assertSame( 'login-page', $result['meta']['auth_mode'] );
		$this->assertSame( array( 302 ), $result['meta']['expected'] );
		$this->assertArrayNotHasKey( 'invalid', $result['meta'] );
	}

	public function test_transport_error_and_broken_json(): void {
		$this->response = new \WP_Error( 'http_request_failed', 'cURL error 7' );
		$this->assertSame( 'cURL error 7', $this->checker()->check( $this->url(), 0 )['meta']['error'] );

		$this->assertSame( '', Checker::invalid_body( 'json', '{"ok":true}' ) );
		$this->assertNotSame( '', Checker::invalid_body( 'json', '<html>' ) );
		$this->assertNotSame( '', Checker::invalid_body( 'feed', '<html>' ) );
		$this->assertSame( '', Checker::invalid_body( 'feed', '<?xml version="1.0"?><rss>' ) );
	}

	public function test_helpers(): void {
		$this->assertTrue( Checker::matches_any( 'Deprecated: foo', array( 'deprecated' ) ) );
		$this->assertTrue( Checker::matches_any( 'Key 12 missing', array( '/key \d+/i' ) ) );
		$this->assertFalse( Checker::matches_any( 'x', array( '/[/' ) ) );
		$this->assertSame( 'Call to x()', Checker::clean_message( "Call to x() in /a/b.php on line 4\nStack trace:\n#0" ) );
		$this->assertNull( Checker::scrape_result( 'no marker', 'abc' ) );
		$this->assertNull( Checker::scrape_result( '###### wp_scraping_result_start:abc ######true###### wp_scraping_result_end:abc ######', 'abc' ) );
	}

	public function test_loopback_check_reports_basic_auth(): void {
		$this->response = $this->reply( 401, '' );
		$error          = $this->checker()->loopback_works();
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'axle_smoke_test_basic_auth', $error->get_error_code() );
	}
}
