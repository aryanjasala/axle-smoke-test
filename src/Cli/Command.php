<?php
/**
 * `wp axle-smoke-test` commands.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Cli;

use AryanX\Axle\SmokeTest\Admin\Display;
use AryanX\Axle\SmokeTest\Backups;
use AryanX\Axle\SmokeTest\Cleanup;
use AryanX\Axle\SmokeTest\Presets;
use AryanX\Axle\SmokeTest\RoleUser;
use AryanX\Axle\SmokeTest\Rollback;
use AryanX\Axle\SmokeTest\Runner;
use AryanX\Axle\SmokeTest\Urls;
use AryanX\Axle\SmokeTest\Verdict;

defined( 'ABSPATH' ) || exit;

/**
 * Runs smoke tests from deploy pipelines, reads the results, manages the key
 * URLs, baselines and backups, and rolls back.
 */
final class Command {

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
	 * The clean-up.
	 *
	 * @var Cleanup
	 */
	private Cleanup $cleanup;

	/**
	 * Sets up the command.
	 *
	 * @param Runner   $runner   The runner.
	 * @param Rollback $rollback Rollbacks.
	 * @param Cleanup  $cleanup  The clean-up.
	 */
	public function __construct( Runner $runner, Rollback $rollback, Cleanup $cleanup ) {
		$this->runner   = $runner;
		$this->rollback = $rollback;
		$this->cleanup  = $cleanup;
	}

	/**
	 * Runs a smoke test now (server tier) and prints the verdict. Exits with 1 on red.
	 *
	 * When an update or deploy left a queued run (for example right after
	 * `wp plugin update`), that run starts now, so it can name and roll back
	 * what changed.
	 *
	 * ## OPTIONS
	 *
	 * [--urls=<keys>]
	 * : Only these URL keys, comma separated (see `wp axle-smoke-test urls list`).
	 *
	 * [--reason=<text>]
	 * : Why this run, shown with it. For example the deploy's commit.
	 *
	 * [--wait]
	 * : Also wait for the browser tier, which runs on the next admin page view, for up to --timeout seconds.
	 *
	 * [--timeout=<seconds>]
	 * : How long --wait waits. Default 300.
	 *
	 * [--format=<format>]
	 * : table, json or yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-smoke-test run --reason="Deploy 4f2a9c1" || ./rollback.sh
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function run( $args, $assoc_args ): void {
		$keys   = array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $assoc_args['urls'] ?? '' ) ) ) );
		$wait   = ! empty( $assoc_args['wait'] );
		$reason = sanitize_text_field( (string) ( $assoc_args['reason'] ?? '' ) );
		$queued = $keys ? null : $this->runner->runs()->latest( array( 'queued' ) );

		if ( $queued ) {
			// Right after `wp plugin update`, run the queued run now: it knows what changed, so it can roll back.
			$context            = $queued['context'];
			$context['browser'] = $wait;
			if ( '' !== $reason ) {
				$context['reason'] = $reason;
			}
			$this->runner->runs()->update( (int) $queued['id'], array( 'context' => $context ) );
			$run = $this->runner->execute( (int) $queued['id'], true );
		} else {
			$run = $this->runner->start(
				'cli',
				array(
					'urls'    => array_values( $keys ),
					'reason'  => $reason,
					'browser' => $wait,
				),
				get_current_user_id()
			);
		}
		if ( is_wp_error( $run ) ) {
			\WP_CLI::error( $run->get_error_message() );
		}

		if ( $wait && 'waiting_browser' === $run['status'] ) {
			$timeout  = max( 1, (int) ( $assoc_args['timeout'] ?? 300 ) );
			$deadline = time() + $timeout;
			\WP_CLI::log( 'Waiting for the browser tier: open any wp-admin page as an administrator.' );
			while ( time() < $deadline ) {
				sleep( 5 );
				$fresh = $this->runner->runs()->get( (int) $run['id'] );
				if ( $fresh && 'waiting_browser' !== $fresh['status'] ) {
					$run = $fresh;
					break;
				}
			}
			if ( 'waiting_browser' === $run['status'] ) {
				\WP_CLI::warning( 'No browser picked the run up in time. The verdict below is from the server tier.' );
			}
		}

		$this->print_run( $run, (string) ( $assoc_args['format'] ?? 'table' ) );
		$verdict = (string) $run['verdict'];
		if ( 'failed' === $run['status'] ) {
			\WP_CLI::error( 'The smoke test could not run: ' . (string) ( $run['summary']['error'] ?? '' ) );
		}
		if ( Verdict::RED === $verdict ) {
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( 'Smoke test ' . ( '' !== $verdict ? $verdict : 'finished' ) . '.' );
	}

	/**
	 * Lists runs, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--verdict=<verdict>]
	 * : red, amber or green.
	 *
	 * [--limit=<number>]
	 * : Default 20.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count. Default table.
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( $args, $assoc_args ): void {
		$runs = $this->runner->runs()->list(
			array(
				'verdict'  => (string) ( $assoc_args['verdict'] ?? '' ),
				'per_page' => (int) ( $assoc_args['limit'] ?? 20 ),
			)
		);
		$rows = array();
		foreach ( $runs as $run ) {
			$counts = (array) ( $run['summary']['counts'] ?? array() );
			$rows[] = array(
				'id'      => $run['id'],
				'created' => $run['created_gmt'],
				'trigger' => $run['trigger_type'],
				'change'  => Display::change( $run ),
				'status'  => $run['status'],
				'verdict' => $run['verdict'],
				'red'     => (int) ( $counts['red'] ?? 0 ),
				'amber'   => (int) ( $counts['amber'] ?? 0 ),
				'green'   => (int) ( $counts['green'] ?? 0 ),
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'created', 'trigger', 'change', 'status', 'verdict', 'red', 'amber', 'green' ) );
	}

	/**
	 * Shows one run and its results.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Run ID.
	 *
	 * [--format=<format>]
	 * : table, json or yaml. Default table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function show( $args, $assoc_args ): void {
		$run = $this->runner->runs()->get( (int) ( $args[0] ?? 0 ) );
		if ( ! $run ) {
			\WP_CLI::error( 'No such run.' );
		}
		$this->print_run( $run, (string) ( $assoc_args['format'] ?? 'table' ) );
	}

	/**
	 * Lists, adds or removes key URLs.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, add or remove.
	 *
	 * [<url-or-key>]
	 * : The URL or path to add, or the key to remove.
	 *
	 * [--expect=<status>]
	 * : Expected statuses for add, comma separated. Default 200.
	 *
	 * [--label=<label>]
	 * : Label for add.
	 *
	 * [--no-browser]
	 * : Leave the URL out of the browser tier.
	 *
	 * [--format=<format>]
	 * : For list: table, csv, json or yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-smoke-test urls add /checkout/ --expect=200,302
	 *     wp axle-smoke-test urls remove url-1a2b3c4d
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function urls( $args, $assoc_args ): void {
		$action = (string) ( $args[0] ?? 'list' );
		$urls   = $this->runner->urls();

		if ( 'add' === $action ) {
			$added = $urls->add( (string) ( $args[1] ?? '' ), Urls::statuses( (string) ( $assoc_args['expect'] ?? '200' ) ), \WP_CLI\Utils\get_flag_value( $assoc_args, 'browser', true ), (string) ( $assoc_args['label'] ?? '' ) );
			if ( is_wp_error( $added ) ) {
				\WP_CLI::error( $added->get_error_message() );
			}
			\WP_CLI::success( 'Added ' . $added['key'] . '.' );
			return;
		}
		if ( 'remove' === $action ) {
			if ( ! $urls->remove( sanitize_key( (string) ( $args[1] ?? '' ) ) ) ) {
				\WP_CLI::error( 'No URL you added has that key. Pages picked from the site are switched off in the settings.' );
			}
			\WP_CLI::success( 'Removed.' );
			return;
		}
		if ( 'list' !== $action ) {
			\WP_CLI::error( 'Use list, add or remove.' );
		}

		$rows = array();
		foreach ( $urls->all() as $url ) {
			$rows[] = array(
				'key'      => $url['key'],
				'label'    => $url['label'],
				'url'      => $url['url'],
				'expected' => implode( ',', $url['expected'] ),
				'browser'  => $url['browser'] ? 'yes' : 'no',
				'source'   => $url['source'],
				'rules'    => count( $url['rules']['must'] ) + count( $url['rules']['must_not'] ),
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'key', 'label', 'url', 'expected', 'browser', 'source', 'rules' ) );
	}

	/**
	 * Lists, sets or clears the rules of a key URL: text or CSS selectors the page must or must not contain.
	 *
	 * A page that breaks a rule turns red. A rule is plain text, or a simple
	 * CSS selector such as .buy-button or form#contact. Start a rule with
	 * "text:" to force text, for example text:[woocommerce_cart].
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, set or clear.
	 *
	 * [<url-key>]
	 * : The key of the URL (see `wp axle-smoke-test urls list`). Needed for set and clear.
	 *
	 * [--must=<rules>]
	 * : For set: rules the page must contain, separated by a pipe (|).
	 *
	 * [--must-not=<rules>]
	 * : For set: rules the page must not contain, separated by a pipe (|).
	 *
	 * [--format=<format>]
	 * : For list: table, csv, json or yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-smoke-test rules set url-1a2b3c4d --must="form#contact|Send message" --must-not="Fatal error"
	 *     wp axle-smoke-test rules clear url-1a2b3c4d
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function rules( $args, $assoc_args ): void {
		$action = (string) ( $args[0] ?? 'list' );
		$key    = sanitize_key( (string) ( $args[1] ?? '' ) );
		$urls   = $this->runner->urls();

		if ( 'set' === $action || 'clear' === $action ) {
			$saved = $urls->set_rules(
				$key,
				'clear' === $action ? array() : array(
					'must'     => explode( '|', (string) ( $assoc_args['must'] ?? '' ) ),
					'must_not' => explode( '|', (string) ( $assoc_args['must-not'] ?? '' ) ),
				)
			);
			if ( is_wp_error( $saved ) ) {
				\WP_CLI::error( $saved->get_error_message() );
			}
			\WP_CLI::success( sprintf( '%d rules saved for %s.', count( $saved['must'] ) + count( $saved['must_not'] ), $key ) );
			return;
		}
		if ( 'list' !== $action ) {
			\WP_CLI::error( 'Use list, set or clear.' );
		}

		$rows = array();
		foreach ( $urls->all() as $url ) {
			foreach ( array(
				'must'     => 'must contain',
				'must_not' => 'must not contain',
			) as $kind => $label ) {
				foreach ( (array) ( $url['rules'][ $kind ] ?? array() ) as $rule ) {
					$rows[] = array(
						'url'  => $url['key'],
						'kind' => $label,
						'rule' => $rule,
					);
				}
			}
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'url', 'kind', 'rule' ) );
	}

	/**
	 * Accepts a finding of the structure check for a URL, or takes it back.
	 *
	 * The signature is the `sig` of the finding, shown by `wp axle-smoke-test show <id> --format=json`.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : accept or restore.
	 *
	 * <run-id>
	 * : Run ID.
	 *
	 * <url-key>
	 * : The URL's key.
	 *
	 * <sig>
	 * : The finding's signature, for example landmark:footer.
	 *
	 * @param array $args Positional arguments.
	 */
	public function findings( $args ): void {
		$action = (string) ( $args[0] ?? '' );
		if ( ! in_array( $action, array( 'accept', 'restore' ), true ) ) {
			\WP_CLI::error( 'Use: wp axle-smoke-test findings accept|restore <run-id> <url-key> <sig>' );
		}
		$run_id = (int) ( $args[1] ?? 0 );
		$key    = sanitize_key( (string) ( $args[2] ?? '' ) );
		$sig    = sanitize_text_field( (string) ( $args[3] ?? '' ) );
		$run    = 'accept' === $action ? $this->runner->accept_finding( $run_id, $key, $sig ) : $this->runner->restore_finding( $run_id, $key, $sig );
		if ( is_wp_error( $run ) ) {
			\WP_CLI::error( $run->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Finding %s. Run #%d is now %s.', 'accept' === $action ? 'accepted' : 'restored', $run_id, '' !== $run['verdict'] ? $run['verdict'] : $run['status'] ) );
	}

	/**
	 * Lists or adds the pages a shop or a multilingual site depends on.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list or add.
	 *
	 * [<keys>]
	 * : For add: suggestion keys, comma separated. Default: all of them.
	 *
	 * [--format=<format>]
	 * : For list: table, csv, json or yaml. Default table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function suggestions( $args, $assoc_args ): void {
		$action = (string) ( $args[0] ?? 'list' );
		$urls   = $this->runner->urls();

		if ( 'add' === $action ) {
			$added = Presets::add( $urls, array_filter( array_map( 'trim', explode( ',', (string) ( $args[1] ?? '' ) ) ) ) );
			if ( is_wp_error( $added ) ) {
				\WP_CLI::error( $added->get_error_message() );
			}
			\WP_CLI::success( sprintf( 'Added %d pages.', count( $added ) ) );
			return;
		}
		if ( 'list' !== $action ) {
			\WP_CLI::error( 'Use list or add.' );
		}

		$rows = array();
		foreach ( Presets::suggestions( $urls ) as $suggestion ) {
			$rows[] = array(
				'key'    => $suggestion['key'],
				'label'  => $suggestion['label'],
				'url'    => $suggestion['url'],
				'group'  => $suggestion['group'],
				'reason' => $suggestion['reason'],
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'key', 'label', 'url', 'group', 'reason' ) );
	}

	/**
	 * Shows, switches on or switches off checks as a role.
	 *
	 * Opens the dashboard, profile and account pages as a test user with the
	 * chosen role. The user is made by the plugin, has no password and cannot
	 * sign in. It is removed on off, when another role is chosen and on
	 * uninstall. Pages are only viewed.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status, on or off. Default status.
	 *
	 * [--role=<role>]
	 * : For on: the role slug, for example editor or customer. Default: the saved role.
	 *
	 * [--pages=<pages>]
	 * : For on: comma separated pages to check: dashboard, profile, account. Default: the saved pages.
	 *
	 * [--format=<format>]
	 * : For status: table, json or yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-smoke-test role-checks on --role=editor --pages=dashboard,profile
	 *     wp axle-smoke-test role-checks off
	 *
	 * @subcommand role-checks
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function role_checks( $args, $assoc_args ): void {
		$action   = (string) ( $args[0] ?? 'status' );
		$settings = $this->runner->settings();

		if ( 'on' === $action || 'off' === $action ) {
			$pages = isset( $assoc_args['pages'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['pages'] ) ) ) : null;
			$saved = RoleUser::configure( $settings, 'on' === $action, (string) ( $assoc_args['role'] ?? '' ), $pages );
			if ( is_wp_error( $saved ) ) {
				\WP_CLI::error( $saved->get_error_message() );
			}
			\WP_CLI::success( 'on' === $action ? 'Checking as ' . $saved['role'] . '.' : 'Switched off. The test user was removed.' );
			return;
		}
		if ( 'status' !== $action ) {
			\WP_CLI::error( 'Use status, on or off.' );
		}

		$status = array(
			'enabled' => $settings->get( 'role_checks' ) ? 'yes' : 'no',
			'role'    => (string) $settings->get( 'role_check_role' ),
			'pages'   => implode( ',', (array) $settings->get( 'role_check_pages' ) ),
			'users'   => count( RoleUser::ids() ),
		);
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), array( $status ), array_keys( $status ) );
	}

	/**
	 * Accepts a run's results as the new baseline.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : accept.
	 *
	 * <run-id>
	 * : Run ID.
	 *
	 * [--url=<key>]
	 * : Only this URL key.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function baseline( $args, $assoc_args ): void {
		if ( 'accept' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Use: wp axle-smoke-test baseline accept <run-id> [--url=<key>]' );
		}
		$accepted = $this->runner->accept_baseline( (int) ( $args[1] ?? 0 ), sanitize_key( (string) ( $assoc_args['url'] ?? '' ) ) );
		if ( is_wp_error( $accepted ) ) {
			\WP_CLI::error( $accepted->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Accepted %d results as the baseline.', $accepted ) );
	}

	/**
	 * Rolls back a plugin or theme a run updated, then runs the smoke test again.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : The run that offered the rollback.
	 *
	 * [--item=<plugin|theme>]
	 * : Plugin file or folder, or theme folder. Needed when the run updated more than one.
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function rollback( $args, $assoc_args ): void {
		$run_id = (int) ( $args[0] ?? 0 );
		$run    = $this->runner->runs()->get( $run_id );
		if ( ! $run ) {
			\WP_CLI::error( 'No such run.' );
		}
		$item = (string) ( $assoc_args['item'] ?? '' );
		foreach ( $this->rollback->candidates( $run ) as $candidate ) {
			if ( '' === $item || in_array( $item, array( $candidate['id'], $candidate['slug'] ), true ) ) {
				\WP_CLI::confirm( sprintf( 'Roll back %s from %s to %s? Database changes are not undone.', $candidate['name'], $candidate['current'], $candidate['version'] ), $assoc_args );
				break;
			}
		}

		$result = $this->rollback->rollback( $run_id, $item, get_current_user_id(), true );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		foreach ( (array) $result['db_versions'] as $version ) {
			\WP_CLI::warning( sprintf( 'Option %s changed from %s to %s during the update and was not changed back.', $version['option'], $version['before'], $version['after'] ) );
		}
		$new = $result['run'] ?? null;
		\WP_CLI::success( sprintf( 'Rolled back %s to %s.%s', $result['item']['name'], $result['item']['version'], is_array( $new ) ? sprintf( ' Run #%d after the rollback: %s.', $new['id'], '' !== $new['verdict'] ? $new['verdict'] : $new['status'] ) : '' ) );
	}

	/**
	 * Lists or deletes the backups made before updates.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list or purge.
	 *
	 * [--all]
	 * : For purge: delete every backup, not just those older than 14 days.
	 *
	 * [--format=<format>]
	 * : For list: table, csv, json or yaml. Default table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function backups( $args, $assoc_args ): void {
		$action = (string) ( $args[0] ?? 'list' );
		if ( 'purge' === $action ) {
			\WP_CLI::success( sprintf( 'Deleted %d backups.', Backups::purge( ! empty( $assoc_args['all'] ) ) ) );
			return;
		}
		if ( 'list' !== $action ) {
			\WP_CLI::error( 'Use list or purge.' );
		}
		$rows = array();
		foreach ( Backups::all() as $entry ) {
			$rows[] = array(
				'id'      => $entry['id'],
				'type'    => $entry['type'],
				'item'    => $entry['item'],
				'version' => $entry['version'],
				'size'    => size_format( (int) $entry['size'] ),
				'created' => gmdate( 'Y-m-d H:i:s', (int) $entry['created'] ),
				'file'    => $entry['file'],
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'type', 'item', 'version', 'size', 'created', 'file' ) );
	}

	/**
	 * Deletes old runs (baselines are kept) and old backups.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Delete runs older than this. Default: the retention setting (30).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function purge( $args, $assoc_args ): void {
		$counts = $this->cleanup->run( isset( $assoc_args['older-than'] ) ? (int) $assoc_args['older-than'] : null );
		\WP_CLI::success( sprintf( 'Deleted %d runs and %d backups.', $counts['runs'], $counts['backups'] ) );
	}

	/**
	 * Prints a run.
	 *
	 * @param array  $run    The run.
	 * @param string $format table, json or yaml.
	 */
	private function print_run( array $run, string $format ): void {
		$results = $this->runner->runs()->results( (int) $run['id'] );
		if ( 'table' !== $format ) {
			$run['results'] = $results;
			\WP_CLI::print_value( $run, array( 'format' => $format ) );
			return;
		}

		\WP_CLI::log( sprintf( 'Run #%d (%s): %s', $run['id'], Display::trigger( (string) $run['trigger_type'] ), Display::change( $run ) ) );
		$rows = array();
		foreach ( $results as $result ) {
			$reasons = array();
			foreach ( (array) ( $result['meta']['reasons'] ?? array() ) as $reason ) {
				$reasons[] = (string) $reason['message'] . ( ! empty( $reason['source'] ) && 'unknown' !== $reason['source'] ? ' [' . $reason['source'] . ']' : '' );
			}
			$rows[] = array(
				'url'     => $result['url_key'],
				'tier'    => $result['tier'],
				'status'  => $result['http_status'],
				'ms'      => $result['ttfb_ms'],
				'verdict' => $result['verdict'],
				'reasons' => implode( '; ', $reasons ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'url', 'tier', 'status', 'ms', 'verdict', 'reasons' ) );
		\WP_CLI::log( sprintf( 'Verdict: %s (%s)', '' !== $run['verdict'] ? $run['verdict'] : '-', $run['status'] ) );
	}
}
