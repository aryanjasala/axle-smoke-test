<?php
/**
 * Tests for settings, secrets and the capability.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Crypto;
use AryanX\Axle\SmokeTest\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class SettingsTest extends SmokeTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_salt' )->justReturn( 'a-salt' );
	}

	public function test_defaults_and_clamping(): void {
		$clean = Settings::sanitize(
			array(
				'text_threshold'   => 500,
				'layout_threshold' => 'x',
				'alert_on'         => 'purple',
				'triggers'         => array( 'update', 'nonsense' ),
				'auto_rollback'    => '1',
				'webhook_url'      => 'https://plain.example.com',
				'emails'           => 'a@example.com bad b@example.com',
			)
		);

		$this->assertSame( 100, $clean['text_threshold'] );
		$this->assertSame( 40, $clean['layout_threshold'] );
		$this->assertSame( 'red', $clean['alert_on'] );
		$this->assertSame( array( 'update' ), $clean['triggers'] );
		$this->assertTrue( $clean['auto_rollback'] );
		$this->assertTrue( $clean['browser_tier'] );
		$this->assertFalse( $clean['cron_admin_checks'] );
		// Only encrypted values are kept for secrets.
		$this->assertSame( '', $clean['webhook_url'] );
		$this->assertSame( 'a@example.com, b@example.com', $clean['emails'] );
	}

	public function test_secrets_are_encrypted_kept_and_cleared(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'Needs libsodium.' );
		}
		Actions\expectDone( 'axle_smoke_test_settings_saved' )->times( 3 );
		$settings = new Settings();

		$saved = $settings->update( array( 'webhook_url' => 'https://hooks.example.com/a', 'auth_pass' => 'pw' ) );
		$this->assertStringStartsWith( 'ast1:', $saved['webhook_url'] );
		$this->assertStringNotContainsString( 'hooks.example.com', (string) json_encode( $this->options['axle_smoke_test_settings'] ) );
		$this->assertSame( 'https://hooks.example.com/a', $settings->secret( 'webhook_url' ) );

		// An empty field keeps the saved secret.
		$settings->update( array( 'webhook_url' => '' ) );
		$this->assertSame( 'https://hooks.example.com/a', $settings->secret( 'webhook_url' ) );

		$settings->update( array( 'clear_auth_pass' => '1' ) );
		$this->assertSame( '', $settings->secret( 'auth_pass' ) );
		$this->assertSame( 'https://hooks.example.com/a', $settings->secret( 'webhook_url' ) );
	}

	public function test_crypto_round_trip_and_mask(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'Needs libsodium.' );
		}
		$stored = Crypto::encrypt( 'secret' );
		$this->assertTrue( Crypto::is_encrypted( $stored ) );
		$this->assertSame( 'secret', Crypto::decrypt( $stored ) );
		$this->assertSame( '', Crypto::decrypt( 'ast1:garbage' ) );
		$this->assertSame( '', Crypto::encrypt( '' ) );
		$this->assertSame( '********cret', Crypto::mask( 'secret' ) );
	}

	public function test_capability_filter_with_fallback(): void {
		$this->assertSame( 'update_plugins', Settings::capability() );

		Filters\expectApplied( 'axle_smoke_test_capability' )->once()->andReturn( 'manage_options' );
		$this->assertSame( 'manage_options', Settings::capability() );

		Filters\expectApplied( 'axle_smoke_test_capability' )->once()->andReturn( '' );
		$this->assertSame( 'update_plugins', Settings::capability() );
	}

	public function test_filtered_int_clamps(): void {
		Filters\expectApplied( 'axle_smoke_test_max_urls' )->once()->andReturn( 9999 );

		$this->assertSame( 200, Settings::filtered_int( 'max_urls', 25, 1, 200 ) );
	}
}
