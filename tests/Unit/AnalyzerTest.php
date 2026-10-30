<?php
/**
 * Tests for the HTML analysis.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Analyzer;
use Brain\Monkey\Filters;

final class AnalyzerTest extends SmokeTestCase {

	private const PAGE = '<!doctype html><html><head><title>Bean &amp; Co</title>'
		. '<link rel="stylesheet" href="/wp-content/themes/tt5/style.css?ver=1.2">'
		. '<link rel="stylesheet" href="https://cdn.other.com/x.css">'
		. '<script src="https://example.com/wp-content/plugins/shop/app.js?ver=3"></script>'
		. '<script id="wp-api-fetch-js-after">wp.apiFetch.nonceMiddleware = "abcdef1234";</script>'
		. '</head><body><header>Logo</header><nav>Menu</nav><main><h1>Hello</h1><p>We roast coffee in small batches.</p>'
		. '<form><button>Go</button></form></main><footer>Footer</footer></body></html>';

	public function test_analyse_finds_landmarks_assets_and_text(): void {
		$signals = Analyzer::analyse( self::PAGE, 'https://example.com/' );

		$this->assertSame( 'Bean & Co', $signals['title'] );
		$this->assertSame( 1, $signals['landmarks']['main'] );
		$this->assertSame( 1, $signals['landmarks']['h1'] );
		$this->assertSame( 1, $signals['landmarks']['button'] );
		$this->assertSame( array( 'https://example.com/wp-content/plugins/shop/app.js?ver=3', 'https://example.com/wp-content/themes/tt5/style.css?ver=1.2' ), $signals['assets'] );
		$this->assertStringContainsString( 'We roast coffee', $signals['text'] );
		$this->assertStringNotContainsString( 'nonceMiddleware', $signals['text'] );
		$this->assertCount( Analyzer::FINGERPRINT_SIZE, $signals['fingerprint'] );
		$this->assertFalse( $signals['critical_error'] );
		$this->assertSame( 64, strlen( $signals['html_hash'] ) );
	}

	public function test_normalise_ignores_nonces_versions_and_generated_ids(): void {
		$a = '<div class="wp-container-core-group-is-layout-1a2b3c4d" data-nonce="0123456789"><a href="/x?_wpnonce=abcdef0123&ver=1">x</a></div>';
		$b = '<div class="wp-container-core-group-is-layout-9f8e7d6c" data-nonce="9876543210"><a href="/x?_wpnonce=0123abcdef&ver=2">x</a></div>';

		$this->assertSame( Analyzer::normalise( $a ), Analyzer::normalise( $b ) );
		$this->assertNotSame( Analyzer::normalise( $a ), Analyzer::normalise( '<div>other</div>' ) );
	}

	public function test_normalise_html_filter_changes_the_hash_input(): void {
		Filters\expectApplied( 'axle_smoke_test_normalise_html' )->once()->andReturn( 'filtered' );

		$this->assertSame( 'filtered', Analyzer::normalise( '<p>x</p>' ) );
	}

	public function test_similarity_of_fingerprints(): void {
		$text = 'We roast coffee in small batches in Pune and ship it fresh across India every single week';
		$same = Analyzer::fingerprint( $text );

		$this->assertSame( 1.0, Analyzer::similarity( $same, Analyzer::fingerprint( $text ) ) );
		$this->assertLessThan( 0.3, Analyzer::similarity( $same, Analyzer::fingerprint( 'Totally different words about something else entirely, nothing shared here at all' ) ) );
		$this->assertSame( 1.0, Analyzer::similarity( array(), array() ) );
		$this->assertSame( 0.0, Analyzer::similarity( $same, array() ) );
	}

	public function test_critical_error_page_and_scrape_marker(): void {
		$this->assertTrue( Analyzer::is_critical_error( '<p>There has been a critical error on this website.</p>' ) );
		$this->assertSame( '<p>x</p>', Analyzer::strip_scrape_marker( "<p>x</p>\n###### wp_scraping_result_start:abc123 ######\ntrue\n###### wp_scraping_result_end:abc123 ######" ) );
	}

	public function test_resolve_relative_links(): void {
		$this->assertSame( 'https://example.com/a/b.js', Analyzer::resolve( 'b.js', 'https://example.com/a/page' ) );
		$this->assertSame( 'https://cdn.com/x.js', Analyzer::resolve( '//cdn.com/x.js', 'https://example.com/' ) );
		$this->assertSame( '', Analyzer::resolve( 'data:image/png;base64,xx', 'https://example.com/' ) );
		$this->assertSame( 'https://example.com:8080/x.css', Analyzer::resolve( '/x.css', 'https://example.com:8080/' ) );
	}
}
