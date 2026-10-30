<?php
/**
 * REST API: runs, results, baselines, rollback and the worker contract.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Rest;

use AryanX\Axle\SmokeTest\Browser\Injector;
use AryanX\Axle\SmokeTest\Rollback;
use AryanX\Axle\SmokeTest\Runner;
use AryanX\Axle\SmokeTest\Service;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /axle-smoke-test/v1. Every route needs the plugin capability.
 * An external worker uses the same routes with an application password.
 */
final class Controller implements Service {

	public const NAMESPACE = 'axle-smoke-test/v1';

	/** Largest thumbnail a worker may upload, in bytes. */
	private const MAX_THUMB = 2 * MB_IN_BYTES;

	/**
	 * The runner.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Rollbacks.
	 *
	 * @var Rollback
	 */
	private Rollback $rollback;

	/**
	 * Sets up the routes.
	 *
	 * @param Runner   $runner   The runner.
	 * @param Rollback $rollback Rollbacks.
	 */
	public function __construct( Runner $runner, Rollback $rollback ) {
		$this->runner   = $runner;
		$this->rollback = $rollback;
	}

	/**
	 * Hooks route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Whether the current user may use the API.
	 *
	 * @return bool
	 */
	public function can(): bool {
		return current_user_can( Settings::capability() );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$id   = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
		$can  = array( $this, 'can' );
		$tier = array(
			'tier' => array(
				'type'    => 'string',
				'enum'    => array( 'browser', 'worker' ),
				'default' => 'browser',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_runs' ),
					'permission_callback' => $can,
					'args'                => array(
						'verdict'  => array(
							'type' => 'string',
							'enum' => array( 'red', 'amber', 'green' ),
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_run' ),
					'permission_callback' => $can,
					'args'                => array(
						'urls'    => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
						'reason'  => array(
							'type'      => 'string',
							'maxLength' => 200,
							'default'   => '',
						),
						'browser' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/next',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'next_run' ),
				'permission_callback' => $can,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_run' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<id>\d+)/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_run' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<id>\d+)/browser',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'browser_plan' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<id>\d+)/results',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_result' ),
				'permission_callback' => $can,
				'args'                => $id + $tier + array(
					'url_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'data'    => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<id>\d+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => $can,
				'args'                => $id + $tier,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<id>\d+)/baseline',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'baseline' ),
				'permission_callback' => $can,
				'args'                => $id + array(
					'url_key' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<id>\d+)/rollback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rollback' ),
				'permission_callback' => $can,
				'args'                => $id + array(
					'item' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/runs/(?P<id>\d+)/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'report' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/results/(?P<id>\d+)/thumbnail',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'thumbnail' ),
				'permission_callback' => $can,
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/urls',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_urls' ),
				'permission_callback' => $can,
			)
		);
	}

	/**
	 * GET /runs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_runs( \WP_REST_Request $request ): \WP_REST_Response {
		$args = array(
			'verdict'  => (string) $request['verdict'],
			'per_page' => (int) $request['per_page'],
			'page'     => (int) $request['page'],
		);

		$response = new \WP_REST_Response( $this->runner->runs()->list( $args ) );
		$response->header( 'X-WP-Total', (string) $this->runner->runs()->count( $args ) );

		return $response;
	}

	/**
	 * POST /runs: runs the server tier now and, with browser=true, returns the browser plan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_run( \WP_REST_Request $request ) {
		$context = array(
			'urls'    => array_map( 'sanitize_key', (array) $request['urls'] ),
			'reason'  => sanitize_text_field( (string) $request['reason'] ),
			'browser' => (bool) $request['browser'],
		);
		$run     = $this->runner->start( $request['browser'] ? 'manual' : 'rest', $context, get_current_user_id() );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		return new \WP_REST_Response( $this->plan( $run ), 201 );
	}

	/**
	 * POST /runs/next: starts the newest queued run now (after an update on the
	 * Plugins or Themes screen), or picks up the newest run waiting for a browser.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function next_run() {
		$run = $this->runner->runs()->latest( array( 'queued', 'waiting_browser' ) );
		if ( ! $run ) {
			return new \WP_REST_Response( array( 'run' => null ) );
		}
		if ( 'queued' === $run['status'] ) {
			$run = $this->runner->execute( (int) $run['id'], true );
			if ( is_wp_error( $run ) ) {
				return $run;
			}
		}

		return new \WP_REST_Response( $this->plan( $run ) );
	}

	/**
	 * GET /runs/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_run( \WP_REST_Request $request ) {
		$run = $this->runner->runs()->get( (int) $request['id'] );
		if ( ! $run ) {
			return self::not_found();
		}
		$run['results'] = $this->runner->runs()->results( (int) $run['id'] );
		$run['urls']    = $this->plan_urls( $run );

		return new \WP_REST_Response( $run );
	}

	/**
	 * POST /runs/<id>/start: runs a queued run's server tier now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_run( \WP_REST_Request $request ) {
		$run = $this->runner->execute( (int) $request['id'], true );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		return new \WP_REST_Response( $this->plan( $run ) );
	}

	/**
	 * POST /runs/<id>/browser: a new browser token and the URLs to load.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function browser_plan( \WP_REST_Request $request ) {
		$run = $this->runner->runs()->get( (int) $request['id'] );
		if ( ! $run ) {
			return self::not_found();
		}

		return new \WP_REST_Response( $this->plan( $run ) );
	}

	/**
	 * POST /runs/<id>/results: one URL's browser or worker result.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_result( \WP_REST_Request $request ) {
		$data = (array) $request['data'];
		$tier = (string) $request['tier'];
		if ( 'worker' === $tier && ! empty( $data['thumbnail'] ) ) {
			$thumb = $this->save_thumbnail( (int) $request['id'], (string) $request['url_key'], (string) $data['thumbnail'] );
			if ( is_wp_error( $thumb ) ) {
				return $thumb;
			}
			$data['thumb_path'] = $thumb;
		}
		unset( $data['thumbnail'] );
		$data['url_key'] = (string) $request['url_key'];

		$result = $this->runner->add_result( (int) $request['id'], $tier, $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 201 );
	}

	/**
	 * POST /runs/<id>/complete.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function complete( \WP_REST_Request $request ) {
		$run = $this->runner->complete( (int) $request['id'], (string) $request['tier'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		return new \WP_REST_Response( $run );
	}

	/**
	 * POST /runs/<id>/baseline.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function baseline( \WP_REST_Request $request ) {
		$count = $this->runner->accept_baseline( (int) $request['id'], sanitize_key( (string) $request['url_key'] ) );
		if ( is_wp_error( $count ) ) {
			return $count;
		}

		return new \WP_REST_Response( array( 'accepted' => $count ) );
	}

	/**
	 * POST /runs/<id>/rollback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rollback( \WP_REST_Request $request ) {
		$result = $this->rollback->rollback( (int) $request['id'], sanitize_text_field( (string) $request['item'] ), get_current_user_id(), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * GET /runs/<id>/report: the run and every result, as a JSON download.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function report( \WP_REST_Request $request ) {
		$run = $this->runner->runs()->get( (int) $request['id'] );
		if ( ! $run ) {
			return self::not_found();
		}
		$run['results'] = $this->runner->runs()->results( (int) $run['id'] );
		$run['site']    = home_url( '/' );

		$response = new \WP_REST_Response( $run );
		$response->header( 'Content-Disposition', 'attachment; filename="smoke-test-' . (int) $run['id'] . '.json"' );

		return $response;
	}

	/**
	 * GET /results/<id>/thumbnail: a worker's screenshot, only for the capability.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_Error
	 */
	public function thumbnail( \WP_REST_Request $request ) {
		$result = $this->runner->runs()->result( (int) $request['id'] );
		$path   = $result && '' !== $result['thumb_path'] ? Storage::thumbs_dir() . '/' . basename( $result['thumb_path'] ) : '';
		if ( '' === $path || ! is_readable( $path ) ) {
			return self::not_found();
		}

		nocache_headers();
		header( 'Content-Type: image/png' );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the plugin's own thumbnail to an admin.
		exit;
	}

	/**
	 * GET /urls.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_urls(): \WP_REST_Response {
		return new \WP_REST_Response( $this->runner->urls()->all() );
	}

	/**
	 * The run, plus a browser token and URLs when it waits for a browser.
	 *
	 * @param array $run The run.
	 * @return array{run: array, token: string, urls: array[]}
	 */
	private function plan( array $run ): array {
		$plan = array(
			'run'   => $run,
			'token' => '',
			'urls'  => array(),
		);
		if ( 'waiting_browser' === $run['status'] && ! $run['browser_done'] ) {
			$plan['token'] = Injector::issue( (int) $run['id'], get_current_user_id() );
			$plan['urls']  = $this->plan_urls( $run );
		}

		return $plan;
	}

	/**
	 * The URLs of a run's browser tier.
	 *
	 * @param array $run The run.
	 * @return array[] key, url, label.
	 */
	private function plan_urls( array $run ): array {
		$urls = array();
		foreach ( (array) ( $run['context']['browser_urls'] ?? array() ) as $key ) {
			$url = $this->runner->urls()->find( (string) $key );
			if ( $url ) {
				$urls[] = array(
					'key'   => $url['key'],
					'url'   => $url['url'],
					'label' => $url['label'],
				);
			}
		}

		return $urls;
	}

	/**
	 * Saves a worker's PNG thumbnail.
	 *
	 * @param int    $run_id  Run ID.
	 * @param string $url_key URL key.
	 * @param string $base64  PNG, base64 encoded.
	 * @return string|\WP_Error File name.
	 */
	private function save_thumbnail( int $run_id, string $url_key, string $base64 ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A PNG upload from the worker.
		$png = base64_decode( (string) preg_replace( '#^data:image/png;base64,#', '', $base64 ), true );
		if ( false === $png || strlen( $png ) > self::MAX_THUMB || "\x89PNG" !== substr( $png, 0, 4 ) ) {
			return new \WP_Error( 'axle_smoke_test_bad_thumbnail', __( 'The thumbnail must be a PNG under 2 MB, base64 encoded.', 'axle-smoke-test' ), array( 'status' => 400 ) );
		}

		$dir = Storage::thumbs_dir();
		$fs  = Storage::fs();
		if ( ! $fs || ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'axle_smoke_test_no_fs', __( 'The thumbnails folder is not writable.', 'axle-smoke-test' ), array( 'status' => 500 ) );
		}
		Storage::protect( $dir );

		$name = $run_id . '-' . sanitize_key( $url_key ) . '-' . wp_generate_password( 8, false ) . '.png';

		return $fs->put_contents( $dir . '/' . $name, $png, FS_CHMOD_FILE ) ? $name : new \WP_Error( 'axle_smoke_test_no_fs', __( 'The thumbnail could not be saved.', 'axle-smoke-test' ), array( 'status' => 500 ) );
	}

	/**
	 * A 404 error.
	 *
	 * @return \WP_Error
	 */
	private static function not_found(): \WP_Error {
		return new \WP_Error( 'axle_smoke_test_not_found', __( 'Not found.', 'axle-smoke-test' ), array( 'status' => 404 ) );
	}
}
