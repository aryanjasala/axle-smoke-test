<?php
/**
 * Tests for error attribution.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Attribution;
use Brain\Monkey\Filters;

final class AttributionTest extends SmokeTestCase {

	private function attribution(): Attribution {
		return new Attribution(
			array(
				'plugin'    => array( '/var/www/wp-content/plugins', 'https://example.com/wp-content/plugins' ),
				'mu-plugin' => array( '/var/www/wp-content/mu-plugins' ),
				'theme'     => array( '/var/www/wp-content/themes', 'https://example.com/wp-content/themes' ),
				'core'      => array( '/var/www/wp-includes', '/var/www/wp-admin', 'https://example.com/wp-includes/' ),
			)
		);
	}

	public function test_paths_and_urls_map_to_their_source(): void {
		$a = $this->attribution();

		$this->assertSame( 'plugin:woocommerce', $a->source( '/var/www/wp-content/plugins/woocommerce/includes/class-wc.php' ) );
		$this->assertSame( 'plugin:hello.php', $a->source( '/var/www/wp-content/plugins/hello.php' ) );
		$this->assertSame( 'theme:twentytwentyfive', $a->source( 'https://example.com/wp-content/themes/twentytwentyfive/style.css?ver=1' ) );
		$this->assertSame( 'theme:twentytwentyfive', $a->source( 'http://example.com/wp-content/themes/twentytwentyfive/a.js' ) );
		$this->assertSame( 'mu-plugin:loader.php', $a->source( '/var/www/wp-content/mu-plugins/loader.php' ) );
		$this->assertSame( 'core', $a->source( '/var/www/wp-includes/functions.php' ) );
		$this->assertSame( 'core', $a->source( 'https://example.com/wp-includes/js/jquery/jquery.min.js' ) );
		$this->assertSame( 'plugin:shop', $a->source( '/srv/other/wp-content/plugins/shop/x.php' ) );
		$this->assertSame( 'unknown', $a->source( 'https://cdn.example.net/lib.js' ) );
		$this->assertSame( 'unknown', $a->source( '' ) );
	}

	public function test_filter_can_change_the_source(): void {
		Filters\expectApplied( 'axle_smoke_test_attribute_error' )->once()->with( 'unknown', 'https://cdn.example.net/lib.js', array( 'message' => 'x' ) )->andReturn( 'plugin:cdn-loader' );

		$this->assertSame( 'plugin:cdn-loader', $this->attribution()->source( 'https://cdn.example.net/lib.js', array( 'message' => 'x' ) ) );
	}

	public function test_empty_filtered_source_falls_back(): void {
		Filters\expectApplied( 'axle_smoke_test_attribute_error' )->once()->andReturn( '' );

		$this->assertSame( 'core', $this->attribution()->source( '/var/www/wp-admin/admin.php' ) );
	}
}
