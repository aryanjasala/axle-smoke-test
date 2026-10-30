<?php
/**
 * Tests for alerts.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Alerts;
use AryanX\Axle\SmokeTest\Crypto;
use AryanX\Axle\SmokeTest\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class AlertsTest extends SmokeTestCase {

	/** @var array Emails sent. */
	private $mails = array();

	/** @var array Webhook posts. */
	private $posts = array();

	protected function setUp(): void {
		parent::setUp();
		$this->mails = array();
		$this->posts = array();
		$this->options['blogname']    = 'Bean There';
		$this->options['admin_email'] = 'owner@example.com';
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->mails[] = compact( 'to', 'subject', 'body' );
				return true;
			}
		);
		Functions\when( 'wp_safe_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = compact( 'url', 'args' );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	private function a_run( string $verdict = 'red' ): array {
		return array(
			'id'           => 7,
			'verdict'      => $verdict,
			'trigger_type' => 'update',
			'summary'      => array(
				'change' => 'Shop 1.0 to 1.1',
				'counts' => array( 'red' => 1 ),
			),
		);
	}

	private function results( string $verdict = 'red' ): array {
		return array(
			array(
				'url_key' => 'home',
				'tier'    => 'server',
				'verdict' => $verdict,
				'meta'    => array(
					'label'   => 'Home page',
					'reasons' => array(
						array(
							'message' => 'HTTP 500, expected 200.',
							'source'  => '',
						),
					),
				),
			),
		);
	}

	public function test_red_run_sends_email_notice_and_is_rate_limited(): void {
		Actions\expectDone( 'axle_smoke_test_alert_sent' )->twice();
		$alerts = new Alerts( new Settings() );

		$this->assertSame( array( 'notice', 'email' ), $alerts->finished( $this->a_run(), $this->results() ) );
		$this->assertSame( array( 'owner@example.com' ), $this->mails[0]['to'] );
		$this->assertSame( '[Bean There] Smoke test red after Shop 1.0 to 1.1', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'RED Home page (server): HTTP 500, expected 200.', $this->mails[0]['body'] );
		$this->assertSame( 7, $this->options[ Alerts::NOTICE_OPTION ] );

		// The same URL and verdict again within the hour: quiet.
		$this->assertSame( array(), $alerts->finished( $this->a_run(), $this->results() ) );
		$this->assertCount( 1, $this->mails );
	}

	public function test_rate_limit_filter_zero_alerts_every_time(): void {
		Filters\expectApplied( 'axle_smoke_test_alert_rate_limit' )->twice()->andReturn( 0 );
		$alerts = new Alerts( new Settings() );

		$this->assertTrue( $alerts->claim( array( 'home|red' ) ) );
		$this->assertTrue( $alerts->claim( array( 'home|red' ) ) );
	}

	public function test_amber_only_alerts_when_asked_and_green_clears_the_notice(): void {
		$this->options['axle_smoke_test_settings'] = array( 'alert_on' => 'red' );
		$alerts                                    = new Alerts( new Settings() );
		$this->assertSame( array(), $alerts->finished( $this->a_run( 'amber' ), $this->results( 'amber' ) ) );

		$this->options[ Alerts::NOTICE_OPTION ] = 3;
		$alerts->finished( $this->a_run( 'green' ), array() );
		$this->assertArrayNotHasKey( Alerts::NOTICE_OPTION, $this->options );

		$this->options['axle_smoke_test_settings'] = array(
			'alert_on' => 'amber',
			'notice'   => false,
		);
		$this->assertSame( array( 'email' ), ( new Alerts( new Settings() ) )->finished( $this->a_run( 'amber' ), $this->results( 'amber' ) ) );
	}

	public function test_recipients_message_and_webhook_filters(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'Needs libsodium.' );
		}
		$this->options['axle_smoke_test_settings'] = array(
			'emails'         => 'a@example.com, not-an-email, b@example.com',
			'webhook_url'    => Crypto::encrypt( 'https://hooks.example.com/x' ),
			'webhook_format' => 'slack',
		);
		Filters\expectApplied( 'axle_smoke_test_alert_recipients' )->once()->with( array( 'a@example.com', 'b@example.com' ), \Mockery::type( 'array' ) )->andReturn( array( 'ops@example.com' ) );
		Filters\expectApplied( 'axle_smoke_test_alert_message' )->once()->andReturnUsing(
			static function ( $message ) {
				$message['subject'] = 'Custom subject';
				return $message;
			}
		);
		Filters\expectApplied( 'axle_smoke_test_webhook_payload' )->once()->with( \Mockery::on( static function ( $payload ) {
			return 0 === strpos( $payload['text'], '*Custom subject*' );
		} ), \Mockery::type( 'array' ), 'slack' )->andReturn( array( 'text' => 'hi' ) );
		Actions\expectDone( 'axle_smoke_test_alert_sent' )->times( 3 );

		$sent = ( new Alerts( new Settings() ) )->finished( $this->a_run(), $this->results() );

		$this->assertSame( array( 'notice', 'email', 'webhook' ), $sent );
		$this->assertSame( array( 'ops@example.com' ), $this->mails[0]['to'] );
		$this->assertSame( 'Custom subject', $this->mails[0]['subject'] );
		$this->assertSame( 'https://hooks.example.com/x', $this->posts[0]['url'] );
		$this->assertSame( '{"text":"hi"}', $this->posts[0]['args']['body'] );
	}
}
