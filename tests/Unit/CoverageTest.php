<?php
/**
 * Tests for the coverage set.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Coverage;
use AryanX\Axle\SmokeTest\Settings;
use Brain\Monkey\Functions;

final class CoverageTest extends SmokeTestCase {

	private const SHORTCODES = '\[(\[?)(gallery|contact-form)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';

	protected function setUp(): void {
		parent::setUp();
		$this->options = array();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	public function test_it_finds_blocks_shortcodes_and_the_template(): void {
		$content = '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' . "\n" . '<!-- wp:gallery {"ids":[1]} /-->' . "\n" . '<!-- wp:acme/slider -->[gallery ids="1"] and [unknown]<!-- /wp:acme/slider -->';

		$keys = Coverage::scan_content( $content, 'page', 'landing.php', self::SHORTCODES );

		$this->assertEqualsCanonicalizing(
			array( 'block:core/paragraph', 'block:core/gallery', 'block:acme/slider', 'shortcode:gallery', 'template:landing.php' ),
			$keys
		);
	}

	public function test_the_default_template_is_the_post_type_single(): void {
		$this->assertSame( array( 'template:single-post' ), Coverage::scan_content( 'Plain text', 'post', '', '' ) );
		$this->assertSame( array( 'template:single-page' ), Coverage::scan_content( 'Plain text', 'page', 'default', '' ) );
	}

	public function test_the_newest_published_item_wins(): void {
		$picks = array();
		Coverage::merge( $picks, 5, '2026-01-01 10:00:00', array( 'block:core/gallery' ) );
		Coverage::merge( $picks, 9, '2026-03-01 10:00:00', array( 'block:core/gallery', 'block:core/table' ) );
		Coverage::merge( $picks, 7, '2026-02-01 10:00:00', array( 'block:core/gallery', 'block:core/table' ) );

		$this->assertSame( 9, $picks['block:core/gallery']['post'] );
		$this->assertSame( 9, $picks['block:core/table']['post'] );
	}

	public function test_excluded_keys_are_never_picked(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'axle_smoke_test_coverage_excluded' === $hook ? array( 'block:core/gallery' ) : $value;
			}
		);
		$picks = array();
		Coverage::merge( $picks, 5, '2026-01-01 10:00:00', array( 'block:core/gallery', 'block:core/table' ) );

		$this->assertSame( array( 'block:core/table' ), array_keys( $picks ) );
	}

	public function test_the_summary_counts_pages_and_what_they_cover(): void {
		$this->options[ Coverage::OPTION ] = array(
			'picks'   => array(
				'block:core/gallery' => array( 'post' => 5, 'date' => 'a' ),
				'block:core/table'   => array( 'post' => 5, 'date' => 'a' ),
				'shortcode:gallery'  => array( 'post' => 8, 'date' => 'b' ),
				'template:landing'   => array( 'post' => 9, 'date' => 'c' ),
			),
			'scanned' => 1000,
		);

		$summary = ( new Coverage( new Settings() ) )->summary();

		$this->assertSame( 3, $summary['pages'] );
		$this->assertSame( 2, $summary['blocks'] );
		$this->assertSame( 1, $summary['shortcodes'] );
		$this->assertSame( 1, $summary['templates'] );
		$this->assertFalse( $summary['scanning'] );
	}

	public function test_a_run_of_chosen_urls_skips_coverage(): void {
		$coverage = new Coverage( new Settings() );

		$this->assertTrue( $coverage->wanted( array( 'context' => array() ) ) );
		$this->assertFalse( $coverage->wanted( array( 'context' => array( 'urls' => array( 'home' ) ) ) ) );
		$this->assertFalse( $coverage->wanted( array( 'context' => array( 'coverage' => false ) ) ) );
	}
}
