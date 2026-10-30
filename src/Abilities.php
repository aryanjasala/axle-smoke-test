<?php
/**
 * Abilities for agents and other tools (WordPress 6.9+).
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Axle Smoke Test's abilities: run a smoke test (server tier),
 * list and read runs, list the key URLs, accept a baseline and roll back an
 * update. Each needs the same capability as the screen.
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-smoke-test';

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
	 * Sets up the abilities.
	 *
	 * @param Runner   $runner   The runner.
	 * @param Rollback $rollback Rollbacks.
	 */
	public function __construct( Runner $runner, Rollback $rollback ) {
		$this->runner   = $runner;
		$this->rollback = $rollback;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle Smoke Test', 'axle-smoke-test' ),
				'description' => __( 'Check key pages after updates and deploys, read the verdicts and roll back what broke.', 'axle-smoke-test' ),
			)
		);
	}

	/**
	 * Whether the current user may use the abilities.
	 *
	 * @return bool
	 */
	public function can(): bool {
		return current_user_can( Settings::capability() );
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$run = self::run_schema();
		$can = array( $this, 'can' );

		$this->ability(
			'run-smoke-test',
			__( 'Run a smoke test', 'axle-smoke-test' ),
			__( 'Checks the key URLs from the server now (status, fatal errors, PHP warnings, blank pages, missing files, landmarks) and returns the run with its verdict. Server tier only: JavaScript errors and layout need a browser, so they are not part of this run.', 'axle-smoke-test' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'urls'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Only these URL keys (see list-urls). Empty for all.', 'axle-smoke-test' ),
					),
					'reason' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			$run,
			array( $this, 'run_smoke_test' ),
			$can,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'list-runs',
			__( 'List smoke test runs', 'axle-smoke-test' ),
			__( 'Runs newest first, with trigger, change, verdict and counts.', 'axle-smoke-test' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'verdict' => array(
						'type' => 'string',
						'enum' => array( 'red', 'amber', 'green' ),
					),
					'limit'   => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'  => 'array',
				'items' => $run,
			),
			array( $this, 'list_runs' ),
			$can,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-run',
			__( 'Get a smoke test run', 'axle-smoke-test' ),
			__( 'One run with every result: per URL and tier the verdict, status, timing and the reasons (PHP errors, JavaScript errors, missing files, layout and text changes, each with the plugin or theme blamed).', 'axle-smoke-test' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			$run,
			array( $this, 'get_run' ),
			$can,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-urls',
			__( 'List key URLs', 'axle-smoke-test' ),
			__( 'The URLs each run checks, with their key, expected statuses and whether the browser tier checks them.', 'axle-smoke-test' ),
			array(),
			array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'key'      => array( 'type' => 'string' ),
						'label'    => array( 'type' => 'string' ),
						'url'      => array( 'type' => 'string' ),
						'expected' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'browser'  => array( 'type' => 'boolean' ),
						'source'   => array( 'type' => 'string' ),
					),
				),
			),
			array( $this, 'list_urls' ),
			$can,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'accept-baseline',
			__( 'Accept a run as the baseline', 'axle-smoke-test' ),
			__( 'Makes a run\'s results (or one URL\'s) the baseline the next runs compare with. Use it when a change was intended, like a redesign.', 'axle-smoke-test' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'url_key' => array(
						'type'        => 'string',
						'description' => __( 'Only this URL. Empty for the whole run.', 'axle-smoke-test' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array( 'accepted' => array( 'type' => 'integer' ) ),
			),
			array( $this, 'accept_baseline' ),
			$can,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'rollback-update',
			__( 'Roll back an update', 'axle-smoke-test' ),
			__( 'Puts a plugin or theme a run updated back to the version before the update, from the zip made before it, then runs the smoke test again. Refused when there is no backup, for core, and while Axle Read-Only Mode has the site frozen. Database changes are not undone.', 'axle-smoke-test' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'item' => array(
						'type'        => 'string',
						'description' => __( 'Plugin file or folder, or theme folder. Optional when the run updated one item.', 'axle-smoke-test' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'item'        => array( 'type' => 'object' ),
					'db_versions' => array( 'type' => 'array' ),
					'run'         => array( 'type' => array( 'object', 'null' ) ),
				),
			),
			array( $this, 'rollback_update' ),
			$can,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);
	}

	/**
	 * Runs the server tier.
	 *
	 * @param array|null $input urls, reason.
	 * @return array|\WP_Error
	 */
	public function run_smoke_test( $input = null ) {
		$input = (array) $input;
		$run   = $this->runner->start(
			'rest',
			array(
				'urls'    => array_map( 'sanitize_key', (array) ( $input['urls'] ?? array() ) ),
				'reason'  => sanitize_text_field( (string) ( $input['reason'] ?? '' ) ),
				'browser' => false,
			),
			get_current_user_id()
		);
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$run['results'] = $this->runner->runs()->results( (int) $run['id'] );
		$run['note']    = __( 'Server tier only. For JavaScript errors and layout, press Run now under Tools > Smoke Test in a browser.', 'axle-smoke-test' );

		return $run;
	}

	/**
	 * Lists runs.
	 *
	 * @param array|null $input verdict, limit.
	 * @return array
	 */
	public function list_runs( $input = null ): array {
		$input = (array) $input;

		return $this->runner->runs()->list(
			array(
				'verdict'  => (string) ( $input['verdict'] ?? '' ),
				'per_page' => (int) ( $input['limit'] ?? 20 ),
			)
		);
	}

	/**
	 * One run with its results.
	 *
	 * @param array|null $input id.
	 * @return array|\WP_Error
	 */
	public function get_run( $input = null ) {
		$id  = (int) ( ( (array) $input )['id'] ?? 0 );
		$run = $this->runner->runs()->get( $id );
		if ( ! $run ) {
			return new \WP_Error( 'axle_smoke_test_not_found', __( 'No such run.', 'axle-smoke-test' ) );
		}
		$run['results'] = $this->runner->runs()->results( $id );

		return $run;
	}

	/**
	 * The key URLs.
	 *
	 * @return array
	 */
	public function list_urls(): array {
		return $this->runner->urls()->all();
	}

	/**
	 * Accepts a baseline.
	 *
	 * @param array|null $input id, url_key.
	 * @return array|\WP_Error
	 */
	public function accept_baseline( $input = null ) {
		$input    = (array) $input;
		$accepted = $this->runner->accept_baseline( (int) ( $input['id'] ?? 0 ), sanitize_key( (string) ( $input['url_key'] ?? '' ) ) );

		return is_wp_error( $accepted ) ? $accepted : array( 'accepted' => $accepted );
	}

	/**
	 * Rolls back.
	 *
	 * @param array|null $input id, item.
	 * @return array|\WP_Error
	 */
	public function rollback_update( $input = null ) {
		$input = (array) $input;

		return $this->rollback->rollback( (int) ( $input['id'] ?? 0 ), sanitize_text_field( (string) ( $input['item'] ?? '' ) ), get_current_user_id(), true );
	}

	/**
	 * Output schema of a run.
	 *
	 * @return array
	 */
	private static function run_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'created_gmt'  => array( 'type' => 'string' ),
				'trigger_type' => array( 'type' => 'string' ),
				'status'       => array(
					'type' => 'string',
					'enum' => Runs::STATUSES,
				),
				'verdict'      => array(
					'type'        => 'string',
					'description' => 'red, amber, green, or empty before the first tier finishes.',
				),
				'context'      => array( 'type' => 'object' ),
				'summary'      => array( 'type' => 'object' ),
				'results'      => array( 'type' => 'array' ),
			),
		);
	}

	/**
	 * Registers one ability.
	 *
	 * @param string   $name        Name without the category.
	 * @param string   $label       Label.
	 * @param string   $description Description.
	 * @param array    $input       Input schema, empty for none.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Callback.
	 * @param callable $permission  Permission check.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'output_schema'       => $output,
			'execute_callback'    => $execute,
			'permission_callback' => $permission,
			'meta'                => array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				// Clients such as the MCP adapter only list abilities marked public:
				// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			),
		);
		if ( $input ) {
			$args['input_schema'] = $input;
		}

		wp_register_ability( self::CATEGORY . '/' . $name, $args );
	}
}
