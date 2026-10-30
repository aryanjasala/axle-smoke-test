<?php
/**
 * Queues, runs and finishes smoke tests.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * The life of a run: queued (debounced, so a batch of updates makes one run),
 * running (the server tier checks every key URL), waiting for a browser (the
 * browser tier runs on the next admin page view), done or failed.
 *
 * Each result is compared with the baseline for its URL and tier: the last
 * green result, or one an admin accepted.
 */
class Runner implements Service {

	/** Triggers that come from changes to the site and are debounced. */
	public const AUTOMATIC = array( 'update', 'install', 'theme', 'deploy' );

	/** Most times a run waits for maintenance mode to end. */
	private const MAINTENANCE_TRIES = 20;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Storage.
	 *
	 * @var Runs
	 */
	private Runs $runs;

	/**
	 * Key URLs.
	 *
	 * @var Urls
	 */
	private Urls $urls;

	/**
	 * The server tier.
	 *
	 * @var Checker
	 */
	private Checker $checker;

	/**
	 * Error attribution.
	 *
	 * @var Attribution
	 */
	private Attribution $attribution;

	/**
	 * Runs to start at the end of this request, when WP-Cron is off.
	 *
	 * @var int[]
	 */
	private array $at_shutdown = array();

	/**
	 * Sets up the runner.
	 *
	 * @param Settings    $settings    Settings.
	 * @param Runs        $runs        Storage.
	 * @param Urls        $urls        Key URLs.
	 * @param Checker     $checker     The server tier.
	 * @param Attribution $attribution Error attribution.
	 */
	public function __construct( Settings $settings, Runs $runs, Urls $urls, Checker $checker, Attribution $attribution ) {
		$this->settings    = $settings;
		$this->runs        = $runs;
		$this->urls        = $urls;
		$this->checker     = $checker;
		$this->attribution = $attribution;
	}

	/**
	 * Hooks the scheduled runs.
	 */
	public function register(): void {
		add_action( Schema::RUN_HOOK, array( $this, 'scheduled' ) );
		add_action( Schema::DAILY_HOOK, array( $this, 'daily' ) );
		add_action( 'init', array( $this, 'schedule_daily' ) );
	}

	/**
	 * Storage, for the screens and commands.
	 *
	 * @return Runs
	 */
	public function runs(): Runs {
		return $this->runs;
	}

	/**
	 * Key URLs, for the screens and commands.
	 *
	 * @return Urls
	 */
	public function urls(): Urls {
		return $this->urls;
	}

	/**
	 * Triggers that may start a run on their own.
	 *
	 * @return string[]
	 */
	public function enabled_triggers(): array {
		$enabled = (array) $this->settings->get( 'triggers' );

		/**
		 * Filters which events start a run on their own: update, install, theme and deploy.
		 *
		 * @param string[] $triggers Default from the settings: all four.
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_triggers', $enabled );

		return array_values( array_intersect( Settings::TRIGGERS, $filtered ) );
	}

	/**
	 * Seconds to wait after the last change before a queued run starts.
	 *
	 * @return int
	 */
	public static function debounce(): int {
		/**
		 * Filters how long a run waits after the last change in a batch of updates.
		 *
		 * @param int $seconds Default 60. Clamped to 0 to 3600.
		 */
		return Settings::filtered_int( 'debounce_seconds', 60, 0, HOUR_IN_SECONDS );
	}

	/**
	 * Queues a run. Changes that come in while a run waits join that run.
	 *
	 * @param string $trigger One of Runs::TRIGGERS.
	 * @param array  $context items, reason, urls.
	 * @param int    $user_id Who caused it.
	 * @return int Run ID, 0 when the trigger is switched off.
	 */
	public function queue( string $trigger, array $context = array(), int $user_id = 0 ): int {
		if ( in_array( $trigger, self::AUTOMATIC, true ) && ! in_array( $trigger, $this->enabled_triggers(), true ) ) {
			return 0;
		}

		$delay   = in_array( $trigger, self::AUTOMATIC, true ) ? self::debounce() : 0;
		$waiting = in_array( $trigger, self::AUTOMATIC, true ) ? $this->runs->latest( array( 'queued' ) ) : null;

		if ( $waiting && in_array( $waiting['trigger_type'], self::AUTOMATIC, true ) ) {
			$id      = (int) $waiting['id'];
			$context = self::merge_context( $waiting['context'], $context );
			// An update in the batch makes it an update run.
			$this->runs->update(
				$id,
				array(
					'context'      => $context,
					'trigger_type' => 'update' === $trigger ? 'update' : $waiting['trigger_type'],
				)
			);
			wp_clear_scheduled_hook( Schema::RUN_HOOK, array( $id ) );
		} else {
			$context['user'] = $user_id;
			$id              = $this->runs->create( $trigger, $context, $user_id );
			if ( ! $id ) {
				return 0;
			}
		}

		wp_schedule_single_event( time() + $delay, Schema::RUN_HOOK, array( $id ) );
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ! in_array( $id, $this->at_shutdown, true ) ) {
			$this->at_shutdown[] = $id;
			if ( 1 === count( $this->at_shutdown ) ) {
				add_action( 'shutdown', array( $this, 'run_at_shutdown' ), 1000 );
			}
		}

		$run = $this->runs->get( $id );

		/**
		 * Fires when a run is queued, or when a change joins a queued run.
		 *
		 * @param array $run The run.
		 */
		do_action( 'axle_smoke_test_run_queued', $run );

		return $id;
	}

	/**
	 * Starts queued runs after the response was sent, for sites without WP-Cron.
	 */
	public function run_at_shutdown(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		foreach ( $this->at_shutdown as $id ) {
			wp_clear_scheduled_hook( Schema::RUN_HOOK, array( $id ) );
			$this->execute( $id );
		}
		$this->at_shutdown = array();
	}

	/**
	 * The WP-Cron callback.
	 *
	 * @param int $id Run ID.
	 */
	public function scheduled( $id ): void {
		$this->execute( (int) $id );
	}

	/**
	 * Creates a run and runs the server tier now.
	 *
	 * @param string $trigger manual, cli, rest, schedule or rollback.
	 * @param array  $context reason, urls (keys), items, browser (false to skip the browser tier).
	 * @param int    $user_id Who started it.
	 * @return array|\WP_Error The run.
	 */
	public function start( string $trigger, array $context = array(), int $user_id = 0 ) {
		$context['user'] = $user_id;
		$id              = $this->runs->create( $trigger, $context, $user_id, 'queued' );
		if ( ! $id ) {
			return new \WP_Error( 'axle_smoke_test_db', __( 'The run could not be saved. Are the plugin tables missing?', 'axle-smoke-test' ) );
		}

		/** This action is documented in src/Runner.php */
		do_action( 'axle_smoke_test_run_queued', $this->runs->get( $id ) );

		return $this->execute( $id, true );
	}

	/**
	 * Runs the server tier of a queued run.
	 *
	 * @param int  $id  Run ID.
	 * @param bool $now Skip the maintenance-mode wait (a person is waiting).
	 * @return array|\WP_Error The run.
	 */
	public function execute( int $id, bool $now = false ) {
		$run = $this->runs->get( $id );
		if ( ! $run ) {
			return new \WP_Error( 'axle_smoke_test_no_run', __( 'No such run.', 'axle-smoke-test' ), array( 'status' => 404 ) );
		}
		if ( 'queued' !== $run['status'] ) {
			return $run;
		}

		// Core puts the site in maintenance mode during updates. Check after it ends.
		if ( ! $now && file_exists( ABSPATH . '.maintenance' ) ) {
			$tries = (int) ( $run['context']['maintenance_tries'] ?? 0 );
			if ( $tries < self::MAINTENANCE_TRIES ) {
				$context                      = $run['context'];
				$context['maintenance_tries'] = $tries + 1;
				$this->runs->update( $id, array( 'context' => $context ) );
				wp_schedule_single_event( time() + 30, Schema::RUN_HOOK, array( $id ) );
				return $this->runs->get( $id ) ?? $run;
			}
		}

		if ( ! $this->runs->transition( $id, 'queued', 'running' ) ) {
			return $this->runs->get( $id ) ?? $run;
		}
		wp_clear_scheduled_hook( Schema::RUN_HOOK, array( $id ) );

		$started = microtime( true );
		$this->runs->update( $id, array( 'started_gmt' => current_time( 'mysql', true ) ) );
		$run = $this->runs->get( $id ) ?? $run;

		/**
		 * Fires when a run starts its server tier.
		 *
		 * @param array $run The run.
		 */
		do_action( 'axle_smoke_test_run_started', $run );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- Up to 25 loopback requests in one go.
		}

		$loopback = $this->checker->loopback_works();
		if ( is_wp_error( $loopback ) ) {
			return $this->fail( $run, $loopback );
		}

		$urls = $this->urls->pick( array_map( 'strval', (array) ( $run['context']['urls'] ?? array() ) ) );
		if ( ! $urls ) {
			return $this->fail( $run, new \WP_Error( 'axle_smoke_test_no_urls', __( 'There are no key URLs to check.', 'axle-smoke-test' ) ) );
		}

		$user_id    = $this->admin_user( $run );
		$thresholds = Verdict::thresholds( $this->settings );
		$this->checker->reset();
		foreach ( $urls as $url ) {
			$result = $this->checker->check( $url, $user_id );
			$this->store( $run, $result, $thresholds );
		}
		AdminSession::sweep( $user_id );

		$context                 = $run['context'];
		$context['checked']      = wp_list_pluck( $urls, 'key' );
		$context['browser_urls'] = array_values(
			wp_list_pluck(
				array_filter(
					$urls,
					static function ( $url ) {
						return ! empty( $url['browser'] );
					} 
				),
				'key' 
			) 
		);
		$context['duration_ms']  = (int) round( ( microtime( true ) - $started ) * 1000 );

		$wants_browser = $this->settings->get( 'browser_tier' ) && $context['browser_urls'] && false !== ( $context['browser'] ?? true );
		$this->runs->update(
			$id,
			array(
				'context'     => $context,
				'server_done' => 1,
				'status'      => $wants_browser ? 'waiting_browser' : 'done',
			)
		);

		return $this->evaluate( $id );
	}

	/**
	 * Marks a run failed.
	 *
	 * @param array     $run   The run.
	 * @param \WP_Error $error What went wrong.
	 * @return array The run.
	 */
	private function fail( array $run, \WP_Error $error ): array {
		$summary          = $run['summary'];
		$summary['error'] = $error->get_error_message();
		if ( 'http_request_failed' === $error->get_error_code() ) {
			$summary['loopback'] = self::core_loopback_result();
		}
		$this->runs->update(
			(int) $run['id'],
			array(
				'status'       => 'failed',
				'verdict'      => '',
				'summary'      => $summary,
				'finished_gmt' => current_time( 'mysql', true ),
			)
		);
		$run = $this->runs->get( (int) $run['id'] ) ?? $run;

		/**
		 * Fires when a run cannot check the site, for example because loopback requests are blocked.
		 *
		 * @param array     $run   The run.
		 * @param \WP_Error $error What went wrong.
		 */
		do_action( 'axle_smoke_test_run_failed', $run, $error );

		return $run;
	}

	/**
	 * What core's own loopback test in Site Health says.
	 *
	 * @return string
	 */
	public static function core_loopback_result(): string {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		$test = \WP_Site_Health::get_instance()->get_test_loopback_requests();

		return isset( $test['label'] ) ? wp_strip_all_tags( (string) $test['label'] . ' ' . (string) ( $test['description'] ?? '' ) ) : '';
	}

	/**
	 * The user the wp-admin URLs are opened as.
	 *
	 * @param array $run The run.
	 * @return int 0 to check them as signed out.
	 */
	private function admin_user( array $run ): int {
		$current = get_current_user_id();
		if ( $current && current_user_can( Settings::capability() ) ) {
			return $current;
		}
		if ( $this->settings->get( 'cron_admin_checks' ) ) {
			$user = (int) $this->settings->get( 'cron_user' );
			return $user > 0 && user_can( $user, Settings::capability() ) ? $user : 0;
		}

		return 0;
	}

	/**
	 * Saves a result with its verdict, and makes a green one the new baseline.
	 *
	 * @param array $run        The run.
	 * @param array $result     The result.
	 * @param array $thresholds Thresholds.
	 * @return array The stored result.
	 */
	private function store( array $run, array $result, array $thresholds ): array {
		$baseline = $this->runs->baseline( $result['url_key'], $result['tier'] );
		$verdict  = Verdict::evaluate( $result, $baseline, $thresholds );

		$result['run_id']                = (int) $run['id'];
		$result['verdict']               = $verdict['verdict'];
		$result['meta']                  = (array) ( $result['meta'] ?? array() );
		$result['meta']['reasons']       = $verdict['reasons'];
		$result['meta']['compared_with'] = $baseline ? (int) $baseline['id'] : 0;
		if ( $baseline ) {
			$result['meta']['baseline_status'] = (int) $baseline['http_status'];
		}
		$result['id'] = $this->runs->add_result( $result );

		if ( Verdict::GREEN === $verdict['verdict'] && $result['id'] ) {
			$this->runs->set_baseline( $result );
		}

		/**
		 * Fires after a URL was checked in one tier.
		 *
		 * @param array $result The result, with its verdict and reasons.
		 * @param array $run    The run.
		 */
		do_action( 'axle_smoke_test_url_checked', $result, $run );

		return $result;
	}

	/**
	 * Adds a browser or worker result.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $tier   browser or worker.
	 * @param array  $data   Collected data, see Rest\Controller::result_schema().
	 * @return array|\WP_Error The stored result.
	 */
	public function add_result( int $run_id, string $tier, array $data ) {
		$run = $this->runs->get( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'axle_smoke_test_no_run', __( 'No such run.', 'axle-smoke-test' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $tier, array( 'browser', 'worker' ), true ) ) {
			return new \WP_Error( 'axle_smoke_test_bad_tier', __( 'Results can only come from the browser or a worker.', 'axle-smoke-test' ), array( 'status' => 400 ) );
		}
		if ( in_array( $run['status'], array( 'queued', 'failed' ), true ) ) {
			return new \WP_Error( 'axle_smoke_test_not_running', __( 'This run is not waiting for results.', 'axle-smoke-test' ), array( 'status' => 409 ) );
		}

		$key = sanitize_key( (string) ( $data['url_key'] ?? '' ) );
		if ( ! in_array( $key, (array) ( $run['context']['checked'] ?? array() ), true ) ) {
			return new \WP_Error( 'axle_smoke_test_unknown_url', __( 'That URL is not part of this run.', 'axle-smoke-test' ), array( 'status' => 400 ) );
		}
		$url = $this->urls->find( $key );

		$result = $this->browser_result(
			$tier,
			$key,
			$url ? $url : array(
				'url'   => '',
				'label' => $key,
			),
			$data 
		);
		foreach ( $this->runs->results( $run_id, $tier ) as $existing ) {
			if ( $existing['url_key'] === $key ) {
				return new \WP_Error( 'axle_smoke_test_duplicate', __( 'This URL already has a result in this tier.', 'axle-smoke-test' ), array( 'status' => 409 ) );
			}
		}

		return $this->store( $run, $result, Verdict::thresholds( $this->settings ) );
	}

	/**
	 * A result from browser or worker data, cleaned and size-capped.
	 *
	 * @param string $tier Tier.
	 * @param string $key  URL key.
	 * @param array  $url  URL entry.
	 * @param array  $data Data.
	 * @return array
	 */
	private function browser_result( string $tier, string $key, array $url, array $data ): array {
		$text = static function ( $value, int $max = 500 ): string {
			return substr( sanitize_text_field( (string) $value ), 0, $max );
		};

		/**
		 * Filters patterns of JavaScript errors that never count, matched
		 * against the message. Plain text matches anywhere; /regex/ is a regex.
		 *
		 * @param string[] $patterns Default: none.
		 * @param array    $url      The URL entry.
		 */
		$ignored = (array) apply_filters( 'axle_smoke_test_ignored_js_errors', array(), $url );

		$js = array();
		foreach ( array_slice( (array) ( $data['js_errors'] ?? array() ), 0, 50 ) as $error ) {
			$error   = (array) $error;
			$message = $text( $error['message'] ?? '' );
			if ( '' === $message || Checker::matches_any( $message, $ignored ) ) {
				continue;
			}
			$file = esc_url_raw( (string) ( $error['url'] ?? '' ) );
			$js[] = array(
				'message' => $message,
				'url'     => $file,
				'line'    => (int) ( $error['line'] ?? 0 ),
				'kind'    => $text( $error['kind'] ?? 'error', 40 ),
				'source'  => $this->attribution->source( $file, $error ),
			);
		}

		$missing = array();
		foreach ( array_slice( (array) ( $data['failed'] ?? array() ), 0, 50 ) as $failed ) {
			$failed = (array) $failed;
			$asset  = esc_url_raw( (string) ( $failed['url'] ?? '' ) );
			if ( '' === $asset ) {
				continue;
			}
			$missing[] = array(
				'url'    => $asset,
				'status' => (int) ( $failed['status'] ?? 0 ),
				'kind'   => $text( $failed['kind'] ?? 'resource', 40 ),
				'source' => $this->attribution->source( $asset ),
			);
		}

		$layout = array();
		foreach ( array_slice( (array) ( $data['layout'] ?? array() ), 0, 60, true ) as $name => $box ) {
			$box                           = (array) $box;
			$layout[ $text( $name, 120 ) ] = array(
				'x'       => (int) round( (float) ( $box['x'] ?? 0 ) ),
				'y'       => (int) round( (float) ( $box['y'] ?? 0 ) ),
				'w'       => (int) round( (float) ( $box['w'] ?? 0 ) ),
				'h'       => (int) round( (float) ( $box['h'] ?? 0 ) ),
				'visible' => ! empty( $box['visible'] ),
			);
		}

		$meta = array(
			'label' => (string) ( $url['label'] ?? $key ),
			'csp'   => array_map( $text, array_slice( (array) ( $data['csp'] ?? array() ), 0, 20 ) ),
		);
		if ( ! empty( $data['error'] ) ) {
			$meta['error'] = $text( $data['error'] );
		}
		if ( isset( $data['load_ms'] ) ) {
			$meta['load_ms'] = max( 0, (int) $data['load_ms'] );
		}

		return array(
			'url_key'          => $key,
			'url'              => (string) ( $url['url'] ?? '' ),
			'tier'             => $tier,
			'http_status'      => max( 0, min( 599, (int) ( $data['http_status'] ?? 0 ) ) ),
			'ttfb_ms'          => max( 0, (int) ( $data['ttfb_ms'] ?? 0 ) ),
			'body_bytes'       => 0,
			'php_errors'       => array(),
			'js_errors'        => $js,
			'missing_assets'   => $missing,
			'html_hash'        => preg_match( '/^[a-f0-9]{64}$/', (string) ( $data['html_hash'] ?? '' ) ) ? (string) $data['html_hash'] : '',
			'text_fingerprint' => array(),
			'landmarks'        => array(),
			'layout'           => $layout,
			'thumb_path'       => isset( $data['thumb_path'] ) ? (string) $data['thumb_path'] : '',
			'meta'             => $meta,
		);
	}

	/**
	 * Marks the browser or worker tier finished and re-evaluates the run.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $tier   browser or worker.
	 * @return array|\WP_Error The run.
	 */
	public function complete( int $run_id, string $tier ) {
		$run = $this->runs->get( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'axle_smoke_test_no_run', __( 'No such run.', 'axle-smoke-test' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $tier, array( 'browser', 'worker' ), true ) ) {
			return new \WP_Error( 'axle_smoke_test_bad_tier', __( 'Only the browser or worker tier can be completed.', 'axle-smoke-test' ), array( 'status' => 400 ) );
		}

		$fields = array( $tier . '_done' => 1 );
		if ( 'waiting_browser' === $run['status'] ) {
			$fields['status'] = 'done';
		}
		$this->runs->update( $run_id, $fields );

		return $this->evaluate( $run_id );
	}

	/**
	 * Works out the run verdict and summary from its results, and announces it.
	 *
	 * @param int $run_id Run ID.
	 * @return array The run.
	 */
	public function evaluate( int $run_id ): array {
		$run     = $this->runs->get( $run_id );
		$results = $run ? $this->runs->results( $run_id ) : array();
		if ( ! $run ) {
			return array();
		}

		$per_url = array();
		$sources = array();
		$tiers   = array();
		foreach ( $results as $result ) {
			$key                      = $result['url_key'];
			$per_url[ $key ]          = Verdict::worst( $per_url[ $key ] ?? Verdict::GREEN, $result['verdict'] );
			$tiers[ $result['tier'] ] = true;
			foreach ( (array) ( $result['meta']['reasons'] ?? array() ) as $reason ) {
				if ( ! empty( $reason['source'] ) && 'unknown' !== $reason['source'] ) {
					$sources[ $reason['source'] ] = ( $sources[ $reason['source'] ] ?? 0 ) + 1;
				}
			}
		}
		arsort( $sources );

		$counts = array(
			Verdict::RED   => 0,
			Verdict::AMBER => 0,
			Verdict::GREEN => 0,
		);
		foreach ( $per_url as $verdict ) {
			++$counts[ $verdict ];
		}

		$verdict = $results ? Verdict::for_run( $run, array_values( $per_url ) ) : '';
		$items   = (array) ( $run['context']['items'] ?? array() );

		$summary = array_merge(
			$run['summary'],
			array(
				'counts'      => $counts,
				'urls'        => count( $per_url ),
				'tiers'       => array_keys( $tiers ),
				'sources'     => $sources,
				'change'      => self::describe_change( $items ),
				'db_versions' => self::changed_versions( (array) ( $run['context']['versions_before'] ?? array() ) ),
			)
		);

		$fields = array(
			'verdict' => $verdict,
			'summary' => $summary,
		);
		if ( in_array( $run['status'], array( 'done', 'waiting_browser' ), true ) ) {
			$fields['finished_gmt'] = current_time( 'mysql', true );
		}
		$this->runs->update( $run_id, $fields );
		$run = $this->runs->get( $run_id ) ?? $run;

		/**
		 * Fires when a run has a verdict: after its server tier, and again
		 * when the browser or a worker adds results. $run['status'] is
		 * waiting_browser or done.
		 *
		 * @param array   $run     The run, with verdict and summary.
		 * @param array[] $results Its results.
		 */
		do_action( 'axle_smoke_test_run_finished', $run, $results );

		return $this->runs->get( $run_id ) ?? $run;
	}

	/**
	 * Accepts a run's results as the new baseline, for every URL or one.
	 *
	 * @param int    $run_id  Run ID.
	 * @param string $url_key Only this URL. Empty for all.
	 * @return int|\WP_Error Results accepted.
	 */
	public function accept_baseline( int $run_id, string $url_key = '' ) {
		$run = $this->runs->get( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'axle_smoke_test_no_run', __( 'No such run.', 'axle-smoke-test' ), array( 'status' => 404 ) );
		}

		$accepted = array();
		foreach ( $this->runs->results( $run_id ) as $result ) {
			if ( '' !== $url_key && $result['url_key'] !== $url_key ) {
				continue;
			}
			$this->runs->set_baseline( $result );
			$accepted[] = $result;
		}
		if ( ! $accepted ) {
			return new \WP_Error( 'axle_smoke_test_no_results', __( 'This run has no results for that URL.', 'axle-smoke-test' ), array( 'status' => 404 ) );
		}

		/**
		 * Fires after results were accepted as the new baseline.
		 *
		 * @param array   $run      The run.
		 * @param array[] $accepted The results that are now the baseline.
		 * @param string  $url_key  The URL, or empty for the whole run.
		 */
		do_action( 'axle_smoke_test_baseline_accepted', $run, $accepted, $url_key );

		return count( $accepted );
	}

	/**
	 * Schedules or removes the daily run, following the setting.
	 */
	public function schedule_daily(): void {
		$next = wp_next_scheduled( Schema::DAILY_HOOK );
		if ( $this->settings->get( 'daily' ) && ! $next ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', Schema::DAILY_HOOK );
		} elseif ( ! $this->settings->get( 'daily' ) && $next ) {
			wp_clear_scheduled_hook( Schema::DAILY_HOOK );
		}
	}

	/**
	 * The scheduled daily run.
	 */
	public function daily(): void {
		if ( $this->settings->get( 'daily' ) ) {
			$this->start( 'schedule', array( 'reason' => __( 'Daily check', 'axle-smoke-test' ) ), 0 );
		}
	}

	/**
	 * "WooCommerce 9.8.1 to 9.9.0, Twenty Twenty-Five 1.2 to 1.3".
	 *
	 * @param array $items Changed items.
	 * @return string
	 */
	public static function describe_change( array $items ): string {
		$parts = array();
		foreach ( $items as $item ) {
			$item = (array) $item;
			$name = (string) ( $item['name'] ?? $item['id'] ?? '' );
			$from = (string) ( $item['from'] ?? '' );
			$to   = (string) ( $item['to'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( '' !== $from && '' !== $to && $from !== $to ) {
				/* translators: 1: plugin or theme name, 2: old version, 3: new version. */
				$parts[] = sprintf( __( '%1$s %2$s to %3$s', 'axle-smoke-test' ), $name, $from, $to );
			} elseif ( '' !== $to ) {
				$parts[] = $name . ' ' . $to;
			} else {
				$parts[] = $name;
			}
		}

		return implode( ', ', array_unique( $parts ) );
	}

	/**
	 * Joins the context of a change into a queued run's context.
	 *
	 * @param array $old Queued context.
	 * @param array $added New context.
	 * @return array
	 */
	public static function merge_context( array $old, array $added ): array {
		$items = array();
		foreach ( array_merge( (array) ( $old['items'] ?? array() ), (array) ( $added['items'] ?? array() ) ) as $item ) {
			$item = (array) $item;
			$id   = ( $item['type'] ?? '' ) . ':' . ( $item['id'] ?? '' );
			if ( isset( $items[ $id ] ) ) {
				// Keep the first known "from" and the last "to".
				$first        = (string) ( $items[ $id ]['from'] ?? '' );
				$item['from'] = '' !== $first ? $first : (string) ( $item['from'] ?? '' );
			}
			$items[ $id ] = $item;
		}

		$merged                    = array_merge( $old, $added );
		$merged['items']           = array_values( $items );
		$merged['versions_before'] = array_merge( (array) ( $added['versions_before'] ?? array() ), (array) ( $old['versions_before'] ?? array() ) );
		$merged['user']            = (int) ( $old['user'] ?? 0 ) ? (int) $old['user'] : (int) ( $added['user'] ?? 0 );

		return $merged;
	}

	/**
	 * Options ending in _version or _db_version that changed since the
	 * snapshot taken before an update.
	 *
	 * @param array $before Option name => value.
	 * @return array[] Each: option, before, after.
	 */
	public static function changed_versions( array $before ): array {
		$changed = array();
		foreach ( $before as $option => $value ) {
			$now = get_option( (string) $option );
			if ( is_scalar( $now ) && (string) $now !== (string) $value ) {
				$changed[] = array(
					'option' => (string) $option,
					'before' => (string) $value,
					'after'  => (string) $now,
				);
			}
		}

		return $changed;
	}
}
