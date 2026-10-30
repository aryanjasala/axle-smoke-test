<?php
/**
 * Tests for the triggers and the deploy fingerprint.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Attribution;
use AryanX\Axle\SmokeTest\Checker;
use AryanX\Axle\SmokeTest\Fingerprint;
use AryanX\Axle\SmokeTest\Runner;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Triggers;
use AryanX\Axle\SmokeTest\Urls;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class TriggersTest extends SmokeTestCase {

	/** @var FakeRuns */
	private $runs;

	/** @var array<string, string> Plugin file => version on disk. */
	private $versions = array();

	protected function setUp(): void {
		parent::setUp();
		$this->runs     = new FakeRuns();
		$this->versions = array( 'shop/shop.php' => '1.0' );
		@mkdir( WP_PLUGIN_DIR . '/shop', 0777, true );
		touch( WP_PLUGIN_DIR . '/shop/shop.php' );

		Functions\when( 'get_file_data' )->alias(
			function ( $file ) {
				$key = substr( $file, strlen( WP_PLUGIN_DIR ) + 1 );
				return array(
					'Name'    => 'Shop',
					'Version' => $this->versions[ $key ] ?? '',
				);
			}
		);
		Functions\when( 'plugin_basename' )->justReturn( 'axle-smoke-test/axle-smoke-test.php' );
		Functions\when( 'wp_load_alloptions' )->alias(
			function () {
				return $this->options;
			}
		);
		Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
		Functions\when( 'get_plugins' )->alias(
			function () {
				$plugins = array();
				foreach ( $this->versions as $file => $version ) {
					$plugins[ $file ] = array(
						'Name'    => 'Shop',
						'Version' => $version,
					);
				}
				return $plugins;
			}
		);
		Functions\when( 'wp_get_themes' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( '7.1' );
		Functions\when( 'wp_get_theme' )->alias(
			static function ( $stylesheet ) {
				return new \WP_Theme( $stylesheet );
			}
		);
	}

	private function triggers(): Triggers {
		$settings = new Settings();
		return new Triggers( new Runner( $settings, $this->runs, new Urls( $settings ), new Checker( $settings, new Attribution( array() ) ), new Attribution( array() ) ) );
	}

	public function test_a_plugin_update_queues_a_run_with_versions_and_version_options(): void {
		Actions\expectDone( 'axle_smoke_test_run_queued' )->once();
		$this->options['shop_db_version'] = '3';
		$triggers                         = $this->triggers();

		$triggers->remember( true, array( 'plugin' => 'shop/shop.php' ) );
		$this->versions['shop/shop.php'] = '1.1';
		$triggers->updated(
			null,
			array(
				'type'    => 'plugin',
				'action'  => 'update',
				'plugins' => array( 'shop/shop.php' ),
			)
		);

		$run = $this->runs->get( 1 );
		$this->assertSame( 'update', $run['trigger_type'] );
		$this->assertSame( array( array( 'type' => 'plugin', 'id' => 'shop/shop.php', 'name' => 'Shop', 'from' => '1.0', 'to' => '1.1' ) ), $run['context']['items'] );
		$this->assertSame( array( 'shop_db_version' => '3' ), $run['context']['versions_before'] );
	}

	public function test_activation_theme_switch_and_own_activation(): void {
		$triggers = $this->triggers();
		$triggers->activated( 'axle-smoke-test/axle-smoke-test.php' );
		$this->assertSame( array(), $this->runs->runs );

		$triggers->activated( 'shop/shop.php' );
		$this->assertSame( 'install', $this->runs->get( 1 )['trigger_type'] );

		\WP_Theme::$themes['old'] = array( 'Name' => 'Old Theme' );
		$triggers->switched( 'New Theme', new \WP_Theme( 'new' ), new \WP_Theme( 'old' ) );
		// It joins the waiting run.
		$this->assertCount( 1, $this->runs->runs );
		$this->assertSame( 'Shop 1.0, Theme Old Theme to New Theme', Runner::describe_change( $this->runs->get( 1 )['context']['items'] ) );
	}

	public function test_deploy_check_compares_the_fingerprint(): void {
		Actions\expectDone( 'axle_smoke_test_deploy_detected' )->once()->with( array( 'plugin:shop/shop.php' ), \Mockery::type( 'array' ) );
		$triggers = $this->triggers();

		$this->assertSame( 0, $triggers->deploy_check(), 'The first check only saves the fingerprint.' );
		$this->assertSame( 0, $triggers->deploy_check(), 'Nothing changed.' );

		$this->versions['shop/shop.php'] = '2.0';
		$id                              = $triggers->deploy_check();
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'deploy', $this->runs->get( $id )['trigger_type'] );
		$this->assertSame( 'Shop 1.0 to 2.0', Runner::describe_change( $this->runs->get( $id )['context']['items'] ) );
	}

	public function test_fingerprint_parts_filter(): void {
		Filters\expectApplied( 'axle_smoke_test_fingerprint_parts' )->once()->andReturnUsing(
			static function ( $parts ) {
				$parts['release'] = 42;
				return $parts;
			}
		);

		$parts = Fingerprint::parts();
		$this->assertSame( '42', $parts['release'] );
		$this->assertArrayHasKey( 'plugin:shop/shop.php', $parts );
		$this->assertSame( array( 'release' ), Fingerprint::diff( array( 'release' => '41' ) + $parts, $parts ) );
	}
}
