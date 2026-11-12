<?php
/**
 * Tests for queueing, running and finishing runs.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\Attribution;
use AryanX\Axle\SmokeTest\Checker;
use AryanX\Axle\SmokeTest\Rules;
use AryanX\Axle\SmokeTest\Runner;
use AryanX\Axle\SmokeTest\Schema;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Urls;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * A checker that answers from a list instead of loading pages.
 */
class StubChecker extends Checker {

	/** @var array<string, array> Results by URL key. */
	public $answers = array();

	/** @var true|\WP_Error */
	public $loopback = true;

	public function check( array $url, int $user_id ): array {
		return array_replace_recursive(
			array(
				'url_key'          => $url['key'],
				'url'              => $url['url'],
				'tier'             => 'server',
				'http_status'      => 200,
				'ttfb_ms'          => 10,
				'body_bytes'       => 100,
				'php_errors'       => array(),
				'missing_assets'   => array(),
				'landmarks'        => array(),
				'text_fingerprint' => array(),
				'meta'             => array(
					'label'      => $url['label'],
					'expected'   => $url['expected'],
					'kind'       => $url['kind'],
					'text_bytes' => 500,
					'user'       => $user_id,
				),
			),
			$this->answers[ $url['key'] ] ?? array()
		);
	}

	public function loopback_works() {
		return $this->loopback;
	}
}

final class RunnerTest extends SmokeTestCase {

	/** @var FakeRuns */
	private $runs;

	/** @var StubChecker */
	private $checker;

	/** @var array Scheduled events. */
	private $events = array();

	protected function setUp(): void {
		parent::setUp();
		$this->runs   = new FakeRuns();
		$this->events = array();
		$this->options['axle_smoke_test_settings'] = array( 'auto_pick' => array_fill_keys( Urls::AUTO_TYPES, false ) );
		$this->options[ Urls::OPTION ]             = array(
			array( 'key' => 'home', 'label' => 'Home', 'url' => 'https://example.com/', 'source' => 'custom' ),
			array( 'key' => 'shop', 'label' => 'Shop', 'url' => 'https://example.com/shop/', 'source' => 'custom', 'browser' => false ),
		);

		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook, $args ) {
				$this->events[] = array( $time, $hook, $args );
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( false );
	}

	private function runner(): Runner {
		$settings      = new Settings();
		$this->checker = new StubChecker( $settings, new Attribution( array() ) );
		return new Runner( $settings, $this->runs, new Urls( $settings ), $this->checker, new Attribution( array() ) );
	}

	public function test_changes_in_one_batch_join_one_queued_run(): void {
		Actions\expectDone( 'axle_smoke_test_run_queued' )->twice();
		Filters\expectApplied( 'axle_smoke_test_debounce_seconds' )->twice()->andReturn( 120 );
		$runner = $this->runner();

		$first  = $runner->queue( 'install', array( 'items' => array( array( 'type' => 'plugin', 'id' => 'shop/shop.php', 'name' => 'Shop', 'from' => '', 'to' => '1.0' ) ) ), 1 );
		$second = $runner->queue( 'update', array( 'items' => array( array( 'type' => 'plugin', 'id' => 'shop/shop.php', 'name' => 'Shop', 'from' => '1.0', 'to' => '1.1' ), array( 'type' => 'theme', 'id' => 'tt5', 'name' => 'TT5', 'from' => '1.0', 'to' => '1.2' ) ) ), 1 );

		$this->assertSame( $first, $second );
		$run = $this->runs->get( $first );
		$this->assertSame( 'update', $run['trigger_type'] );
		$this->assertSame( 'Shop 1.0 to 1.1, TT5 1.0 to 1.2', Runner::describe_change( $run['context']['items'] ) );
		$this->assertSame( Schema::RUN_HOOK, $this->events[1][1] );
		$this->assertGreaterThanOrEqual( time() + 119, $this->events[1][0] );
	}

	public function test_switched_off_triggers_queue_nothing(): void {
		Filters\expectApplied( 'axle_smoke_test_triggers' )->once()->andReturn( array( 'update' ) );

		$this->assertSame( 0, $this->runner()->queue( 'deploy', array(), 0 ) );
		$this->assertSame( array(), $this->runs->runs );
	}

	public function test_a_run_checks_every_url_compares_with_the_baseline_and_announces_itself(): void {
		Actions\expectDone( 'axle_smoke_test_run_started' )->twice();
		Actions\expectDone( 'axle_smoke_test_url_checked' )->times( 4 );
		Actions\expectDone( 'axle_smoke_test_run_finished' )->twice();
		$runner = $this->runner();

		$run = $runner->start( 'manual', array( 'browser' => true ), 1 );

		$this->assertSame( 'waiting_browser', $run['status'] );
		$this->assertSame( 'green', $run['verdict'] );
		$this->assertSame( array( 'home', 'shop' ), $run['context']['checked'] );
		$this->assertSame( array( 'home' ), $run['context']['browser_urls'] );
		$this->assertSame( 1, $this->runs->results[1]['meta']['user'] );
		// Green results become the baseline.
		$this->assertSame( 1, $this->runs->results[1]['is_baseline'] );

		// Next run: the shop breaks.
		$this->checker->answers['shop'] = array( 'http_status' => 500 );
		$run2                           = $runner->start( 'cli', array( 'browser' => false ), 0 );
		$this->assertSame( 'done', $run2['status'] );
		$this->assertSame( 'red', $run2['verdict'] );
		$this->assertSame( array( 'red' => 1, 'amber' => 0, 'green' => 1 ), $run2['summary']['counts'] );
		$this->assertSame( 2, $this->runs->results[4]['meta']['compared_with'] );
		$this->assertSame( 0, $this->runs->results[4]['is_baseline'] );
	}

	public function test_blocked_loopback_fails_the_run(): void {
		Actions\expectDone( 'axle_smoke_test_run_failed' )->once();
		$runner                  = $this->runner();
		$this->checker->loopback = new \WP_Error( 'axle_smoke_test_basic_auth', 'Needs a password' );

		$run = $runner->start( 'manual', array(), 1 );

		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'Needs a password', $run['summary']['error'] );
	}

	public function test_browser_results_are_cleaned_and_compared(): void {
		Filters\expectApplied( 'axle_smoke_test_ignored_js_errors' )->twice()->andReturn( array( 'ResizeObserver loop' ) );
		$runner = $this->runner();
		$run    = $runner->start( 'manual', array(), 1 );

		$stored = $runner->add_result(
			(int) $run['id'],
			'browser',
			array(
				'url_key'   => 'home',
				'js_errors' => array(
					array( 'message' => 'ResizeObserver loop completed with undelivered notifications.' ),
					array( 'message' => '<b>Uncaught</b> TypeError', 'url' => 'https://example.com/wp-content/plugins/shop/app.js' ),
				),
				'layout'    => array( 'main' => array( 'x' => 1.4, 'y' => 2, 'w' => 3, 'h' => 4, 'visible' => 1 ) ),
			)
		);
		$this->assertSame( 'amber', $stored['verdict'] );
		$this->assertCount( 1, $stored['js_errors'] );
		$this->assertSame( 'Uncaught TypeError', $stored['js_errors'][0]['message'] );
		$this->assertSame( array( 'x' => 1, 'y' => 2, 'w' => 3, 'h' => 4, 'visible' => true ), $stored['layout']['main'] );

		$this->assertSame( 'axle_smoke_test_duplicate', $runner->add_result( (int) $run['id'], 'browser', array( 'url_key' => 'home' ) )->get_error_code() );
		$this->assertSame( 'axle_smoke_test_unknown_url', $runner->add_result( (int) $run['id'], 'browser', array( 'url_key' => 'elsewhere' ) )->get_error_code() );
		$this->assertSame( 'axle_smoke_test_bad_tier', $runner->add_result( (int) $run['id'], 'server', array( 'url_key' => 'home' ) )->get_error_code() );

		$done = $runner->complete( (int) $run['id'], 'browser' );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( 'amber', $done['verdict'] );
		$this->assertSame( 1, $done['browser_done'] );
	}

	public function test_accepting_a_baseline(): void {
		Actions\expectDone( 'axle_smoke_test_baseline_accepted' )->once();
		$runner                         = $this->runner();
		$this->checker->answers['shop'] = array( 'http_status' => 500 );
		$run                            = $runner->start( 'cli', array( 'browser' => false ), 0 );
		$this->assertSame( 0, $this->runs->results[2]['is_baseline'] );

		$this->assertSame( 1, $runner->accept_baseline( (int) $run['id'], 'shop' ) );
		$this->assertSame( 1, $this->runs->results[2]['is_baseline'] );
		$this->assertSame( 'axle_smoke_test_no_results', $runner->accept_baseline( (int) $run['id'], 'nope' )->get_error_code() );
	}

	private function red_run_with_finding(): int {
		$id = $this->runs->create( 'manual', array( 'checked' => array( 'home' ) ), 1, 'done' );
		$this->runs->add_result(
			array(
				'run_id'      => $id,
				'url_key'     => 'home',
				'url'         => 'https://example.com/',
				'tier'        => 'server',
				'verdict'     => 'red',
				'is_baseline' => 0,
				'meta'        => array(
					'reasons' => array(
						array( 'level' => 'red', 'code' => 'structure_form', 'message' => 'The page no longer has a form. It had 1.', 'source' => '', 'sig' => 'form:form:/', 'acceptable' => true ),
						array( 'level' => 'amber', 'code' => 'text', 'message' => 'About 70% of the visible text changed.', 'source' => '' ),
					),
				),
			)
		);
		return $id;
	}

	public function test_accepting_one_finding_keeps_the_others_and_can_be_taken_back(): void {
		Actions\expectDone( 'axle_smoke_test_finding_accepted' )->twice();
		$runner = $this->runner();
		$id     = $this->red_run_with_finding();

		$runner->accept_finding( $id, 'home', 'form:form:/' );

		$this->assertSame( 'amber', $this->runs->results[1]['verdict'] );
		$this->assertSame( array( 'text' ), array_column( $this->runs->results[1]['meta']['reasons'], 'code' ) );
		$this->assertSame( array( 'form:form:/' ), Rules::accepted( 'home' ) );
		$this->assertSame( 0, $this->runs->results[1]['is_baseline'] );

		$runner->restore_finding( $id, 'home', 'form:form:/' );

		$this->assertSame( 'red', $this->runs->results[1]['verdict'] );
		$this->assertSame( array(), Rules::accepted( 'home' ) );
		$this->assertSame( 'axle_smoke_test_no_finding', $runner->restore_finding( $id, 'home', 'nope' )->get_error_code() );
	}

	public function test_accepting_the_last_finding_makes_the_page_the_new_baseline(): void {
		$runner = $this->runner();
		$id     = $this->red_run_with_finding();
		$this->runs->results[1]['meta']['reasons'] = array_slice( $this->runs->results[1]['meta']['reasons'], 0, 1 );

		$runner->accept_finding( $id, 'home', 'form:form:/' );

		$this->assertSame( 'green', $this->runs->results[1]['verdict'] );
		$this->assertSame( 1, $this->runs->results[1]['is_baseline'] );
		$this->assertSame( array(), Rules::accepted( 'home' ), 'A new baseline starts with nothing accepted.' );
	}

	public function test_taking_back_the_last_finding_puts_the_old_baseline_back(): void {
		$runner = $this->runner();
		$this->runs->add_result( array( 'run_id' => 0, 'url_key' => 'home', 'url' => 'https://example.com/', 'tier' => 'server', 'verdict' => 'green', 'is_baseline' => 1, 'meta' => array() ) );
		$id = $this->red_run_with_finding();
		$this->runs->results[2]['meta']['reasons'] = array_slice( $this->runs->results[2]['meta']['reasons'], 0, 1 );

		$runner->accept_finding( $id, 'home', 'form:form:/' );
		$this->assertSame( 1, $this->runs->results[2]['is_baseline'] );
		$this->assertSame( 0, $this->runs->results[1]['is_baseline'] );

		$runner->restore_finding( $id, 'home', 'form:form:/' );
		$this->assertSame( 0, $this->runs->results[2]['is_baseline'] );
		$this->assertSame( 1, $this->runs->results[1]['is_baseline'] );
		$this->assertSame( 'red', $this->runs->results[2]['verdict'] );
	}

	public function test_a_rule_is_not_an_acceptable_finding(): void {
		$runner = $this->runner();
		$id     = $this->red_run_with_finding();
		$this->runs->results[1]['meta']['reasons'][0]['acceptable'] = false;

		$this->assertSame( 'axle_smoke_test_no_finding', $runner->accept_finding( $id, 'home', 'form:form:/' )->get_error_code() );
	}

	public function test_accepted_findings_reach_the_verdict_of_the_next_run(): void {
		$runner = $this->runner();
		Rules::accept( 'home', 'landmark:footer' );

		$runner->start( 'cli', array( 'browser' => false ), 0 );

		$this->assertSame( array( 'landmark:footer' ), $this->runs->results[1]['meta']['accepted'] );
		$this->assertArrayNotHasKey( 'accepted', $this->runs->results[2]['meta'] );
	}

	public function test_merge_context_and_version_options(): void {
		$merged = Runner::merge_context(
			array( 'items' => array( array( 'type' => 'plugin', 'id' => 'a', 'from' => '1', 'to' => '2' ) ), 'versions_before' => array( 'a_db_version' => '1' ), 'user' => 3 ),
			array( 'items' => array( array( 'type' => 'plugin', 'id' => 'a', 'from' => '2', 'to' => '3' ) ), 'versions_before' => array( 'a_db_version' => '2' ), 'user' => 0 )
		);
		$this->assertSame( array( array( 'type' => 'plugin', 'id' => 'a', 'from' => '1', 'to' => '3' ) ), $merged['items'] );
		$this->assertSame( array( 'a_db_version' => '1' ), $merged['versions_before'] );
		$this->assertSame( 3, $merged['user'] );

		$this->options['a_db_version'] = '5';
		$this->assertSame( array( array( 'option' => 'a_db_version', 'before' => '1', 'after' => '5' ) ), Runner::changed_versions( array( 'a_db_version' => '1' ) ) );
	}
}
