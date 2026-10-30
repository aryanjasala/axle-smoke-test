<?php
/**
 * Tests for the clean-up, the admin login sessions, privacy, abilities and the panel.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Abilities;
use AryanX\Axle\SmokeTest\Admin\Panel;
use AryanX\Axle\SmokeTest\AdminSession;
use AryanX\Axle\SmokeTest\Attribution;
use AryanX\Axle\SmokeTest\Browser\Injector;
use AryanX\Axle\SmokeTest\Checker;
use AryanX\Axle\SmokeTest\Cleanup;
use AryanX\Axle\SmokeTest\Privacy;
use AryanX\Axle\SmokeTest\Rollback;
use AryanX\Axle\SmokeTest\Runner;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Urls;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ServicesTest extends SmokeTestCase {

	/** @var FakeRuns */
	private $runs;

	protected function setUp(): void {
		parent::setUp();
		$this->runs = new FakeRuns();
	}

	private function runner(): Runner {
		$settings = new Settings();
		return new Runner( $settings, $this->runs, new Urls( $settings ), new Checker( $settings, new Attribution( array() ) ), new Attribution( array() ) );
	}

	public function test_cleanup_uses_retention_filters_and_announces_the_purge(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Filters\expectApplied( 'axle_smoke_test_retention_days' )->once()->with( 30 )->andReturn( 7 );
		Filters\expectApplied( 'axle_smoke_test_retention_runs' )->once()->with( 200 )->andReturn( 50 );
		Actions\expectDone( 'axle_smoke_test_purged' )->once()->with(
			array(
				'runs'    => 2,
				'backups' => 0,
				'expired' => 0,
			)
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'basedir' => WP_CONTENT_DIR . '/uploads' ) );
		$this->runs->create( 'manual', array(), 1, 'done' );
		$this->runs->create( 'manual', array(), 1, 'done' );
		$this->runs->purgeable = array( 1, 2 );

		$counts = ( new Cleanup( new Settings(), $this->runner() ) )->run();
		$this->assertSame( 2, $counts['runs'] );
	}

	public function test_admin_session_rules(): void {
		$this->assertTrue( AdminSession::allowed( 'GET', '/wp-admin/', '/wp-admin' ) );
		$this->assertFalse( AdminSession::allowed( 'POST', '/wp-admin/', '/wp-admin/' ) );
		$this->assertFalse( AdminSession::allowed( 'GET', '/wp-admin/plugins.php', '/wp-admin/' ) );
		$this->assertFalse( AdminSession::allowed( 'GET', '/wp-admin/admin-post.php', '/wp-admin/admin-post.php' ) );
		$this->assertFalse( AdminSession::allowed( 'GET', '/wp-json/wp/v2/users', '/wp-json/wp/v2/users' ) );
	}

	public function test_admin_session_cookies_and_the_enabled_filter(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'wp_generate_auth_cookie' )->alias(
			static function ( $user, $expiration, $scheme, $token ) {
				return "{$user}|{$scheme}|{$token}";
			}
		);

		// The session is marked with its one path while it is created.
		Filters\expectAdded( 'attach_session_information' )->once()->whenHappen(
			function ( $callback ) {
				$this->assertSame( array( 'axle_smoke_test' => array( 'path' => '/wp-admin/plugins.php' ) ), $callback( array() ) );
			}
		);
		$session = AdminSession::create( 1, 'https://example.com/wp-admin/plugins.php' );
		$this->assertSame( array( AUTH_COOKIE, LOGGED_IN_COOKIE ), array_keys( $session['cookies'] ) );
		$this->assertSame( '1|auth|' . $session['token'], $session['cookies'][ AUTH_COOKIE ] );
		$this->assertLessThanOrEqual( time() + 60, \WP_Session_Tokens::$sessions[1][ $session['token'] ]['expiration'] );

		AdminSession::destroy( 1, $session['token'] );
		$this->assertSame( array(), \WP_Session_Tokens::$sessions[1] );

		Filters\expectApplied( 'axle_smoke_test_admin_loopback_enabled' )->once()->andReturn( false );
		$this->assertNull( AdminSession::create( 1, 'https://example.com/wp-admin/' ) );
	}

	public function test_a_marked_session_used_elsewhere_signs_nobody_in(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/wp-admin/admin-post.php';
		Functions\when( 'wp_unslash' )->returnArg();
		\WP_Session_Tokens::$sessions[1]['tok'] = array( 'axle_smoke_test' => array( 'path' => '/wp-admin/' ) );

		$session = new AdminSession();
		$session->check( array( 'token' => 'tok' ), new \WP_User( 1 ) );

		$this->assertFalse( $session->refuse_user( 1 ) );
		$session->destroy_used();
		$this->assertArrayNotHasKey( 'tok', \WP_Session_Tokens::$sessions[1] );
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] );
	}

	public function test_browser_tokens_and_injection(): void {
		$token = Injector::issue( 5, 1 );
		$this->assertSame( array( 'run' => 5, 'user' => 1 ), Injector::check( $token ) );
		$this->assertNull( Injector::check( 'nope' ) );
		$this->assertNull( Injector::check( str_repeat( 'a', 32 ) ) );

		Functions\when( 'wp_get_inline_script_tag' )->alias(
			static function ( $code ) {
				return '<script>' . strlen( $code ) . '</script>';
			}
		);
		$html = Injector::inject( '<html><head data-x="1"><script src="a.js"></script></head></html>' );
		$this->assertMatchesRegularExpression( '#<head data-x="1">\s*<script>\d+</script>\s*<script src="a.js">#', $html );
		$this->assertSame( 'no head', Injector::inject( 'no head' ) );
	}

	public function test_privacy_export_and_erase(): void {
		Functions\when( 'get_user_by' )->justReturn( new \WP_User( 4 ) );
		$this->options['axle_smoke_test_settings'] = array( 'emails' => 'ops@example.com, me@example.com' );
		$this->runs->create( 'manual', array(), 4, 'done' );
		$this->runs->create( 'manual', array(), 9, 'done' );
		$settings = new Settings();
		$privacy  = new Privacy( $this->runs, $settings );

		$export = $privacy->export( 'me@example.com' );
		$this->assertCount( 2, $export['data'] );
		$this->assertSame( 'axle-smoke-test-alerts', $export['data'][0]['group_id'] );
		$this->assertTrue( $export['done'] );

		$erased = $privacy->erase( 'me@example.com' );
		$this->assertSame( 2, $erased['items_removed'] );
		$this->assertSame( 0, $this->runs->get( 1 )['triggered_by'] );
		$this->assertSame( 9, $this->runs->get( 2 )['triggered_by'] );
		$this->assertSame( 'ops@example.com', $settings->get( 'emails' ) );
	}

	public function test_abilities_register_with_annotations_and_the_capability(): void {
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ) {
				$registered[ $name ] = $args;
			}
		);
		$runner    = $this->runner();
		$abilities = new Abilities( $runner, new Rollback( new Settings(), $runner ) );
		$abilities->register_abilities();

		$this->assertSame(
			array( 'run-smoke-test', 'list-runs', 'get-run', 'list-urls', 'accept-baseline', 'rollback-update' ),
			array_map(
				static function ( $name ) {
					return substr( $name, strlen( 'axle-smoke-test/' ) );
				},
				array_keys( $registered )
			)
		);
		$this->assertTrue( $registered['axle-smoke-test/list-runs']['meta']['annotations']['readonly'] );
		$this->assertTrue( $registered['axle-smoke-test/rollback-update']['meta']['annotations']['destructive'] );
		$this->assertTrue( $registered['axle-smoke-test/accept-baseline']['meta']['annotations']['idempotent'] );
		$this->assertFalse( $registered['axle-smoke-test/run-smoke-test']['meta']['annotations']['readonly'] );
		$this->assertTrue( $registered['axle-smoke-test/run-smoke-test']['meta']['mcp']['public'] );

		$this->assertTrue( $abilities->can() );
		$this->caps = array();
		$this->assertFalse( $abilities->can() );
		$this->assertInstanceOf( \WP_Error::class, $abilities->get_run( array( 'id' => 99 ) ) );
	}

	public function test_panel_config_reads_the_layout_selectors_and_settle_filters(): void {
		Filters\expectApplied( 'axle_smoke_test_layout_selectors' )->once()->andReturn( array( 'main', '.hero', '' ) );
		Filters\expectApplied( 'axle_smoke_test_settle_ms' )->once()->andReturn( 99999 );
		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/wp-content/plugins/axle-smoke-test/assets/x' );
		$inline = '';
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $code ) use ( &$inline ) {
				$inline = $code;
			}
		);

		Panel::enqueue_runner();

		$config = json_decode( substr( $inline, strlen( 'window.axleSmokeTestRunner = ' ), -1 ), true );
		$this->assertSame( array( 'main', '.hero' ), $config['selectors'] );
		$this->assertSame( 30000, $config['settleMs'] );
		$this->assertSame( 0, $config['runId'] );
	}
}
