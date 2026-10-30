<?php
/**
 * Tests for the key URL list.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Urls;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class UrlsTest extends SmokeTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->options['page_on_front'] = 0;
		\WP_Query::$queue = array( array( 'posts' => array( 11 ) ), array( 'posts' => array( 22 ) ) );
		Functions\when( 'get_permalink' )->alias(
			static function ( $id ) {
				return 'https://example.com/?p=' . $id;
			}
		);
		Functions\when( 'get_terms' )->justReturn( array() );
	}

	private function urls(): Urls {
		return new Urls( new Settings() );
	}

	public function test_auto_picked_urls_with_expected_statuses(): void {
		$urls = $this->urls()->all();
		$keys = array_column( $urls, 'key' );

		$this->assertSame( array( 'home', 'latest-post', 'latest-page', 'search', 'not-found', 'feed', 'rest', 'login', 'dashboard', 'plugins-screen' ), $keys );
		$by_key = array_column( $urls, null, 'key' );
		$this->assertSame( array( 404 ), $by_key['not-found']['expected'] );
		$this->assertTrue( $by_key['dashboard']['auth'] );
		$this->assertFalse( $by_key['rest']['browser'] );
		$this->assertSame( 'json', $by_key['rest']['kind'] );
	}

	public function test_auto_urls_urls_max_and_expected_status_filters(): void {
		Filters\expectApplied( 'axle_smoke_test_auto_urls' )->once()->andReturn(
			array(
				array( 'key' => 'home', 'url' => 'https://example.com/' ),
				array( 'key' => 'elsewhere', 'url' => 'https://other.example.org/' ),
			)
		);
		Filters\expectApplied( 'axle_smoke_test_urls' )->once()->andReturnUsing(
			static function ( $urls ) {
				$urls[] = array( 'key' => 'checkout', 'url' => '/checkout/', 'expected' => '200,302' );
				$urls[] = array( 'key' => 'extra', 'url' => '/extra/' );
				return $urls;
			}
		);
		Filters\expectApplied( 'axle_smoke_test_max_urls' )->once()->andReturn( 2 );
		Filters\expectApplied( 'axle_smoke_test_expected_status' )->times( 3 )->andReturnUsing(
			static function ( $expected, $entry ) {
				return 'checkout' === $entry['key'] ? array( 302 ) : $expected;
			}
		);

		$urls = $this->urls()->all();
		// The other site is dropped; the list is capped at two.
		$this->assertSame( array( 'home', 'checkout' ), array_column( $urls, 'key' ) );
		$this->assertSame( array( 302 ), $urls[1]['expected'] );
		$this->assertSame( 'https://example.com/checkout/', $urls[1]['url'] );
	}

	public function test_add_and_remove_custom_urls(): void {
		$this->options['axle_smoke_test_settings'] = array( 'auto_pick' => array() );
		$urls                                      = $this->urls();

		$added = $urls->add( '/shop/', array( 200, 302 ), false, 'Shop' );
		$this->assertSame( 'Shop', $added['label'] );
		$this->assertSame( 'custom', $added['source'] );
		$this->assertSame( 'axle_smoke_test_duplicate_url', $urls->add( '/shop/' )->get_error_code() );
		$this->assertSame( 'axle_smoke_test_bad_url', $urls->add( 'https://elsewhere.org/' )->get_error_code() );
		$this->assertSame( array( $added['key'] ), array_column( $urls->all(), 'key' ) );

		$this->assertTrue( $urls->remove( $added['key'] ) );
		$this->assertFalse( $urls->remove( $added['key'] ) );
	}

	public function test_statuses_parsing(): void {
		$this->assertSame( array( 200, 302 ), Urls::statuses( '200, 302, 999' ) );
		$this->assertSame( array( 200 ), Urls::statuses( 'nonsense' ) );
		$this->assertSame( array( 404 ), Urls::statuses( array( '404' ) ) );
	}
}
