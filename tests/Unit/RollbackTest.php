<?php
/**
 * Tests for backups before updates and rollbacks.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Attribution;
use AryanX\Axle\SmokeTest\Backups;
use AryanX\Axle\SmokeTest\Checker;
use AryanX\Axle\SmokeTest\Rollback;
use AryanX\Axle\SmokeTest\Runner;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Urls;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class RollbackTest extends SmokeTestCase {

	/** @var FakeRuns */
	private $runs;

	protected function setUp(): void {
		parent::setUp();
		$this->runs = new FakeRuns();
		$this->clean();
		$this->write_plugin( '1.1' );

		$GLOBALS['wp_filesystem'] = new \WP_Filesystem_Base();
		Functions\when( 'WP_Filesystem' )->justReturn( true );
		Functions\when( 'get_filesystem_method' )->justReturn( 'direct' );
		Functions\when( 'wp_mkdir_p' )->alias(
			static function ( $dir ) {
				return is_dir( $dir ) || mkdir( $dir, 0777, true );
			}
		);
		Functions\when( 'wp_is_writable' )->alias( 'is_writable' );
		Functions\when( 'trailingslashit' )->alias(
			static function ( $path ) {
				return rtrim( $path, '/' ) . '/';
			}
		);
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'wp_generate_password' )->alias(
			static function ( $length ) {
				return substr( bin2hex( random_bytes( $length ) ), 0, $length );
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);
		Functions\when( 'get_file_data' )->alias(
			static function ( $file ) {
				preg_match( '/Version: ([\d.]+)/', (string) file_get_contents( $file ), $match );
				return array(
					'Name'    => 'Shop',
					'Version' => $match[1] ?? '',
				);
			}
		);
		Functions\when( 'unzip_file' )->alias(
			static function ( $file, $to ) {
				$zip = new \ZipArchive();
				$zip->open( $file );
				$zip->extractTo( $to );
				$zip->close();
				return true;
			}
		);
		Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
		Functions\when( 'wp_remote_get' )->justReturn( array() );
	}

	protected function tearDown(): void {
		$this->clean();
		unset( $GLOBALS['wp_filesystem'] );
		parent::tearDown();
	}

	private function clean(): void {
		( new \WP_Filesystem_Base() )->delete( WP_CONTENT_DIR, true );
	}

	private function write_plugin( string $version ): void {
		@mkdir( WP_PLUGIN_DIR . '/shop', 0777, true );
		file_put_contents( WP_PLUGIN_DIR . '/shop/shop.php', "<?php\n/* Plugin Name: Shop\n * Version: {$version}\n */\n" );
	}

	private function rollback( array $settings = array() ): Rollback {
		$this->options['axle_smoke_test_settings'] = $settings + array( 'auto_pick' => array() );
		$settings                                  = new Settings();
		$runner                                    = new Runner( $settings, $this->runs, new Urls( $settings ), new Checker( $settings, new Attribution( array() ) ), new Attribution( array() ) );
		return new Rollback( $settings, $runner );
	}

	private function red_run(): int {
		$id = $this->runs->create(
			'update',
			array(
				'items' => array(
					array(
						'type' => 'plugin',
						'id'   => 'shop/shop.php',
						'name' => 'Shop',
						'from' => '1.1',
						'to'   => '1.2',
					),
				),
			),
			1,
			'done'
		);
		$this->runs->update(
			$id,
			array(
				'verdict' => 'red',
				'summary' => array( 'sources' => array( 'plugin:shop' => 3 ) ),
			)
		);
		return $id;
	}

	public function test_backup_before_update_then_rollback_restores_the_files(): void {
		Actions\expectDone( 'axle_smoke_test_backup_created' )->once();
		Actions\expectDone( 'axle_smoke_test_before_rollback' )->once();
		Actions\expectDone( 'axle_smoke_test_rolled_back' )->once();

		( new Backups() )->before_update( true, array( 'plugin' => 'shop/shop.php' ) );
		$this->assertFileExists( Backups::dir() . '/.htaccess' );
		$this->assertFileExists( Backups::dir() . '/index.php' );
		$this->write_plugin( '1.2' );

		$run    = $this->red_run();
		$result = $this->rollback()->rollback( $run, '', 1, false );

		$this->assertSame( array( 'type' => 'plugin', 'id' => 'shop/shop.php', 'slug' => 'shop', 'name' => 'Shop', 'current' => '1.2', 'version' => '1.1' ), $result['item'] );
		$this->assertStringContainsString( 'Version: 1.1', (string) file_get_contents( WP_PLUGIN_DIR . '/shop/shop.php' ) );
		$this->assertSame( array( 'shop' ), array_values( array_diff( scandir( WP_PLUGIN_DIR ), array( '.', '..' ) ) ) );
		$this->assertSame( '1.2', $this->runs->get( $run )['summary']['rolled_back'][0]['from'] );
	}

	public function test_no_backup_no_rollback(): void {
		Actions\expectDone( 'axle_smoke_test_before_rollback' )->never();
		$this->assertSame( 'axle_smoke_test_no_backup', $this->rollback()->rollback( $this->red_run() )->get_error_code() );
	}

	public function test_backup_size_filter(): void {
		Filters\expectApplied( 'axle_smoke_test_backup_max_bytes' )->once()->with( 100 * MB_IN_BYTES, 'plugin', 'shop/shop.php' )->andReturn( 1 );
		$this->assertSame( 'axle_smoke_test_too_big', ( new Backups() )->create( 'plugin', 'shop/shop.php', '1.1' )->get_error_code() );
	}

	public function test_backups_to_keep_filter(): void {
		Filters\expectApplied( 'axle_smoke_test_backups_to_keep' )->times( 3 )->andReturn( 1 );
		$backups = new Backups();
		$backups->create( 'plugin', 'shop/shop.php', '1.0' );
		$backups->create( 'plugin', 'shop/shop.php', '1.1' );
		$backups->create( 'plugin', 'shop/shop.php', '1.2' );
		$this->assertSame( array( '1.2' ), array_column( Backups::all(), 'version' ) );
		$this->assertSame( 1, Backups::purge( true ) );
	}

	public function test_a_failed_restore_fires_rollback_failed(): void {
		Actions\expectDone( 'axle_smoke_test_rollback_failed' )->once();
		( new Backups() )->create( 'plugin', 'shop/shop.php', '1.1' );
		Functions\when( 'unzip_file' )->justReturn( new \WP_Error( 'incompatible_archive', 'Bad zip' ) );

		$this->assertSame( 'incompatible_archive', $this->rollback()->rollback( $this->red_run() )->get_error_code() );
	}

	public function test_auto_rollback_needs_the_setting_and_the_filter_decides(): void {
		( new Backups() )->create( 'plugin', 'shop/shop.php', '1.1' );
		$this->write_plugin( '1.2' );
		Actions\expectDone( 'axle_smoke_test_rolled_back' )->once();

		// Off by default.
		$off = $this->rollback();
		$off->maybe_auto( $this->runs->get( $this->red_run() ) );
		$this->assertStringContainsString( 'Version: 1.2', (string) file_get_contents( WP_PLUGIN_DIR . '/shop/shop.php' ) );

		Filters\expectApplied( 'axle_smoke_test_auto_rollback' )->once()->with( true, \Mockery::type( 'array' ), \Mockery::type( 'array' ) )->andReturn( true );
		// The run after the rollback cannot reach the site here; it fails, which is fine for this test.
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'offline', 'offline' ) );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		$on  = $this->rollback( array( 'auto_rollback' => true ) );
		$run = $this->red_run();
		$on->maybe_auto( $this->runs->get( $run ) );
		$this->assertStringContainsString( 'Version: 1.1', (string) file_get_contents( WP_PLUGIN_DIR . '/shop/shop.php' ) );
		$this->assertTrue( $this->runs->get( $run )['summary']['auto_rollback'] );
	}
}
