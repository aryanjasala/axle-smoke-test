<?php
/**
 * Tests for the verdict rules.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Analyzer;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Verdict;
use Brain\Monkey\Filters;

final class VerdictTest extends SmokeTestCase {

	private const T = array(
		'text'   => 0.6,
		'layout' => 40,
		'blank'  => 40,
	);

	private function server( array $extra = array(), array $meta = array() ): array {
		return array_merge(
			array(
				'url_key'          => 'home',
				'tier'             => 'server',
				'http_status'      => 200,
				'php_errors'       => array(),
				'missing_assets'   => array(),
				'landmarks'        => array( 'main' => 1 ),
				'text_fingerprint' => Analyzer::fingerprint( 'We roast coffee in small batches in Pune and ship it fresh' ),
				'meta'             => array_merge(
					array(
						'expected'   => array( 200 ),
						'kind'       => 'html',
						'text_bytes' => 500,
					),
					$meta
				),
			),
			$extra
		);
	}

	private function codes( array $verdict ): array {
		return array_column( $verdict['reasons'], 'code' );
	}

	public function test_same_as_baseline_is_green(): void {
		$verdict = Verdict::evaluate( $this->server(), $this->server(), self::T );

		$this->assertSame( 'green', $verdict['verdict'] );
		$this->assertSame( array(), $verdict['reasons'] );
	}

	public function test_fatal_error_is_red_and_blamed(): void {
		$verdict = Verdict::evaluate(
			$this->server(
				array( 'http_status' => 500 ),
				array(
					'fatal' => array(
						'message' => 'Call to undefined function x()',
						'file'    => ABSPATH . 'wp-content/plugins/shop/shop.php',
						'line'    => 3,
						'source'  => 'plugin:shop',
					),
				)
			),
			$this->server(),
			self::T
		);

		$this->assertSame( 'red', $verdict['verdict'] );
		$this->assertSame( array( 'fatal', 'status' ), $this->codes( $verdict ) );
		$this->assertSame( 'plugin:shop', $verdict['reasons'][0]['source'] );
		$this->assertStringContainsString( 'in wp-content/plugins/shop/shop.php on line 3', $verdict['reasons'][0]['message'] );
	}

	public function test_expected_404_is_green_and_unexpected_200_is_amber(): void {
		$this->assertSame( 'green', Verdict::evaluate( $this->server( array( 'http_status' => 404 ), array( 'expected' => array( 404 ) ) ), null, self::T )['verdict'] );
		$this->assertSame( 'amber', Verdict::evaluate( $this->server( array(), array( 'expected' => array( 404 ) ) ), null, self::T )['verdict'] );
		$this->assertSame( 'red', Verdict::evaluate( $this->server( array( 'http_status' => 403 ) ), null, self::T )['verdict'] );
	}

	public function test_no_response_critical_error_and_blank_page_are_red(): void {
		$this->assertSame( array( 'no_response' ), $this->codes( Verdict::evaluate( $this->server( array(), array( 'error' => 'timed out' ) ), null, self::T ) ) );
		$this->assertContains( 'critical_error', $this->codes( Verdict::evaluate( $this->server( array(), array( 'critical_error' => true ) ), null, self::T ) ) );
		$blank = Verdict::evaluate( $this->server( array(), array( 'text_bytes' => 3 ) ), $this->server(), self::T );
		$this->assertSame( 'red', $blank['verdict'] );
		$this->assertSame( array( 'blank' ), $this->codes( $blank ) );
	}

	public function test_new_php_warning_and_missing_file_are_amber_but_known_ones_are_not(): void {
		$warning  = array(
			'type'    => 'warning',
			'message' => 'Undefined array key 3',
			'source'  => 'plugin:shop',
		);
		$asset    = array(
			'url'    => 'https://example.com/a.js?ver=1',
			'status' => 404,
		);
		$current  = $this->server(
			array(
				'php_errors'     => array( $warning ),
				'missing_assets' => array( $asset ),
			)
		);
		$verdict = Verdict::evaluate( $current, $this->server(), self::T );
		$this->assertSame( 'amber', $verdict['verdict'] );
		$this->assertSame( array( 'php_error', 'missing_asset' ), $this->codes( $verdict ) );

		// The same warning with another number, and the same file with another version, are not new.
		$baseline = $this->server(
			array(
				'php_errors'     => array( array( 'message' => 'Undefined array key 7' ) + $warning ),
				'missing_assets' => array( array( 'url' => 'https://example.com/a.js?ver=2' ) + $asset ),
			)
		);
		$this->assertSame( 'green', Verdict::evaluate( $current, $baseline, self::T )['verdict'] );
	}

	public function test_lost_landmark_and_changed_text_are_amber(): void {
		$current = $this->server(
			array(
				'landmarks'        => array( 'main' => 0 ),
				'text_fingerprint' => Analyzer::fingerprint( 'Completely new words that share nothing with before at all here' ),
			)
		);
		$this->assertSame( array( 'landmark', 'text' ), $this->codes( Verdict::evaluate( $current, $this->server(), self::T ) ) );
	}

	public function test_browser_tier_js_errors_and_layout(): void {
		$base    = array(
			'tier'      => 'browser',
			'js_errors' => array(),
			'layout'    => array(
				'main'   => array( 'x' => 0, 'y' => 100, 'w' => 800, 'h' => 600, 'visible' => true ),
				'footer' => array( 'x' => 0, 'y' => 700, 'w' => 800, 'h' => 100, 'visible' => true ),
			),
		);
		$current = array(
			'tier'      => 'browser',
			'js_errors' => array(
				array(
					'message' => 'Uncaught TypeError: x is undefined',
					'source'  => 'plugin:shop',
				),
			),
			'layout'    => array(
				'main'   => array( 'x' => 0, 'y' => 100, 'w' => 800, 'h' => 600, 'visible' => false ),
				'footer' => array( 'x' => 0, 'y' => 900, 'w' => 800, 'h' => 100, 'visible' => true ),
			),
		);

		$verdict = Verdict::evaluate( $current, $base, self::T );
		$this->assertSame( 'amber', $verdict['verdict'] );
		$this->assertSame( array( 'js_error', 'layout', 'layout' ), $this->codes( $verdict ) );
		$this->assertSame( array( 'key' => 'footer', 'change' => 'moved', 'delta' => 200 ), Verdict::layout_changes( $base['layout'], $current['layout'], 40 )[1] );
	}

	public function test_verdict_filter_runs_per_url_and_per_run(): void {
		Filters\expectApplied( 'axle_smoke_test_verdict' )->twice()->andReturnUsing(
			static function ( $verdict, $reasons, $subject, $level ) {
				return 'url' === $level ? 'amber' : 'red';
			}
		);

		$this->assertSame( 'amber', Verdict::evaluate( $this->server(), null, self::T )['verdict'] );
		$this->assertSame( 'red', Verdict::for_run( array( 'id' => 1 ), array( 'green' ) ) );
	}

	public function test_invalid_filtered_verdict_falls_back(): void {
		Filters\expectApplied( 'axle_smoke_test_verdict' )->once()->andReturn( 'purple' );

		$this->assertSame( 'amber', Verdict::for_run( array(), array( 'green', 'amber' ) ) );
	}

	public function test_thresholds_come_from_settings_and_filters(): void {
		$this->options['axle_smoke_test_settings'] = array(
			'text_threshold'   => 30,
			'layout_threshold' => 10,
			'blank_bytes'      => 5,
		);
		Filters\expectApplied( 'axle_smoke_test_text_change_threshold' )->once()->with( 30 )->andReturn( 50 );
		Filters\expectApplied( 'axle_smoke_test_layout_threshold' )->once()->with( 10 )->andReturn( 'garbage' );
		Filters\expectApplied( 'axle_smoke_test_blank_page_bytes' )->once()->with( 5 )->andReturn( -3 );

		$this->assertSame(
			array(
				'text'   => 0.5,
				'layout' => 10,
				'blank'  => 0,
			),
			Verdict::thresholds( new Settings() )
		);
	}
}
