<?php
/**
 * Tools > Smoke Test.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Admin;

use AryanX\Axle\SmokeTest\Backups;
use AryanX\Axle\SmokeTest\Family;
use AryanX\Axle\SmokeTest\Presets;
use AryanX\Axle\SmokeTest\Rules;
use AryanX\Axle\SmokeTest\MuPlugin;
use AryanX\Axle\SmokeTest\Rollback;
use AryanX\Axle\SmokeTest\Runner;
use AryanX\Axle\SmokeTest\Service;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Urls;
use AryanX\Axle\SmokeTest\Verdict;
use const AryanX\Axle\SmokeTest\PLUGIN_FILE;
use const AryanX\Axle\SmokeTest\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The screen: the latest run, every run, one run's results with rollback and
 * baseline buttons, and the settings. Every form posts to admin-post.php with
 * a nonce and a capability check.
 */
final class Page implements Service {

	public const SLUG = 'axle-smoke-test';

	private const ACTIONS = array( 'save_settings', 'add_url', 'remove_url', 'baseline', 'rollback', 'run', 'mu_plugin', 'dismiss', 'save_rules', 'accept_finding', 'add_suggestions', 'dismiss_steps', 'undo' );

	/** The user meta that hides the Get started card. */
	public const STEPS_META = 'axle_smoke_test_steps_dismissed';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

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
	 * The screen's hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Sets up the screen.
	 *
	 * @param Settings $settings Settings.
	 * @param Runner   $runner   The runner.
	 * @param Rollback $rollback Rollbacks.
	 */
	public function __construct( Settings $settings, Runner $runner, Rollback $rollback ) {
		$this->settings = $settings;
		$this->runner   = $runner;
		$this->rollback = $rollback;
	}

	/**
	 * Hooks the menu, assets and form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_axle_smoke_test_' . $action, array( $this, 'handle_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * The screen URL.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'tools.php?page=' . self::SLUG ) );
	}

	/**
	 * Adds Tools > Smoke Test.
	 */
	public function menu(): void {
		$this->hook = (string) add_management_page(
			__( 'Smoke Test', 'axle-smoke-test' ),
			__( 'Smoke Test', 'axle-smoke-test' ),
			Settings::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
		if ( '' !== $this->hook ) {
			add_action( 'load-' . $this->hook, array( $this, 'help' ) );
		}
	}

	/**
	 * The Help tab: what the screen is for, and one short tab for each job it does.
	 */
	public function help(): void {
		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-smoke-test' ),
					'content' => '<p>' . esc_html__( 'Smoke Test checks your key pages after every update, theme switch or deploy. It compares each page with how it looked when it last passed, and tells you what broke and which plugin or theme probably did it.', 'axle-smoke-test' ) . '</p><p>' . esc_html__( 'Red means something is broken. Amber means a page changed in a way worth a look. Green means the page looks as it did.', 'axle-smoke-test' ) . '</p>',
				),
				array(
					'id'      => 'structure',
					'title'   => __( 'Missing parts', 'axle-smoke-test' ),
					'content' => '<p>' . esc_html__( 'A page can answer "200 OK" and still be empty. Smoke Test learns what each page is made of: its header, menu, footer, forms and Add to cart buttons. When one of them goes missing, the page turns red and says what is gone.', 'axle-smoke-test' ) . '</p><p>' . esc_html__( 'If the change was meant, press Accept next to that finding. Later runs stop reporting it.', 'axle-smoke-test' ) . '</p>',
				),
				array(
					'id'      => 'rules',
					'title'   => __( 'Page rules', 'axle-smoke-test' ),
					'content' => '<p>' . esc_html__( 'Under Settings, each key URL can have rules. "Must contain" and "must not contain" take plain text, or a simple CSS selector such as .buy-button or form#contact. Put one rule on each line.', 'axle-smoke-test' ) . '</p><p>' . esc_html__( 'A page that breaks a rule turns red, even on the first run.', 'axle-smoke-test' ) . '</p>',
				),
				array(
					'id'      => 'rollback',
					'title'   => __( 'Rolling back', 'axle-smoke-test' ),
					'content' => '<p>' . esc_html__( 'Before a plugin or theme is updated, its folder is zipped. When a run turns red after an update, you can put the old files back from that zip and the smoke test runs again.', 'axle-smoke-test' ) . '</p><p>' . esc_html__( 'Database changes made by the update stay. The run lists the version options that changed so you know.', 'axle-smoke-test' ) . '</p>',
				),
			)
		);
	}

	/**
	 * A Smoke Test link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$links = (array) $links;
		if ( current_user_can( Settings::capability() ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Runs', 'axle-smoke-test' ) . '</a>', '<a href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Settings', 'axle-smoke-test' ) . '</a>' );
		}

		return $links;
	}

	/**
	 * Styles and scripts for the screen.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}
		AxleUi::enqueue();
		wp_enqueue_style( 'axle-smoke-test-admin' );
		wp_enqueue_script( 'axle-smoke-test-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-a11y' ), VERSION, true );
	}

	/**
	 * Draws the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to use the smoke test.', 'axle-smoke-test' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only picks what to show.
		$tab    = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'runs';
		$run_id = isset( $_GET['run'] ) ? absint( $_GET['run'] ) : 0;
		// phpcs:enable

		$latest = $this->runner->runs()->latest();

		echo '<div class="wrap axle-ui ast-wrap">';
		echo '<h1>' . esc_html__( 'Smoke Test', 'axle-smoke-test' ) . '</h1>';
		if ( $latest ) {
			echo '<span class="axle-status">' . Display::badge( (string) $latest['verdict'], (string) $latest['status'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
		}
		echo '<hr class="wp-header-end">';
		$this->messages();

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Smoke Test sections', 'axle-smoke-test' ) . '">';
		printf( '<a href="%s" class="nav-tab%s"%s>%s</a>', esc_url( self::url() ), 'runs' === $tab ? ' nav-tab-active' : '', 'runs' === $tab ? ' aria-current="page"' : '', esc_html__( 'Runs', 'axle-smoke-test' ) );
		printf( '<a href="%s" class="nav-tab%s"%s>%s</a>', esc_url( self::url( array( 'tab' => 'settings' ) ) ), 'settings' === $tab ? ' nav-tab-active' : '', 'settings' === $tab ? ' aria-current="page"' : '', esc_html__( 'Settings', 'axle-smoke-test' ) );
		echo '</nav>';

		if ( 'settings' === $tab ) {
			echo '<p class="axle-intro">' . esc_html__( 'Choose which pages to check, how sensitive the check is and who hears about problems.', 'axle-smoke-test' ) . '</p>';
			( new SettingsView( $this->settings, $this->runner->urls() ) )->render();
		} elseif ( $run_id ) {
			echo '<p class="axle-intro">' . esc_html__( 'What the smoke test found on each page in this run.', 'axle-smoke-test' ) . '</p>';
			$this->render_run( $run_id );
		} else {
			echo '<p class="axle-intro">' . esc_html__( 'Checks your key pages after every update, theme switch or deploy, and tells you what broke.', 'axle-smoke-test' ) . '</p>';
			$this->render_runs();
		}

		AxleUi::footer();
		echo '</div>';
	}

	/**
	 * Notices after a form was handled.
	 */
	private function messages(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$done  = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised after decoding.
		// phpcs:enable
		$messages = array(
			'saved'            => __( 'Settings saved.', 'axle-smoke-test' ),
			'url_added'        => __( 'URL added.', 'axle-smoke-test' ),
			'url_removed'      => __( 'URL removed.', 'axle-smoke-test' ),
			'baseline'         => __( 'Accepted as the new baseline. The next run compares against it.', 'axle-smoke-test' ),
			'rolled_back'      => __( 'Rolled back. The smoke test ran again: see the new run below.', 'axle-smoke-test' ),
			'ran'              => __( 'Smoke test finished.', 'axle-smoke-test' ),
			'mu_installed'     => __( 'Early collector installed in wp-content/mu-plugins.', 'axle-smoke-test' ),
			'mu_removed'       => __( 'Early collector removed.', 'axle-smoke-test' ),
			'rules_saved'      => __( 'Rules saved. They apply from the next run.', 'axle-smoke-test' ),
			'finding_accepted' => __( 'Accepted. Later runs stop reporting this finding for this page.', 'axle-smoke-test' ),
			'finding_restored' => __( 'Taken back. Later runs report this finding again.', 'axle-smoke-test' ),
			'url_restored'     => __( 'URL restored.', 'axle-smoke-test' ),
			'suggestions'      => __( 'Pages added. Run now to set their baseline.', 'axle-smoke-test' ),
			'steps_dismissed'  => __( 'The Get started steps are hidden.', 'axle-smoke-test' ),
		);
		if ( isset( $messages[ $done ] ) ) {
			$undo = '';
			if ( in_array( $done, array( 'url_removed', 'finding_accepted' ), true ) && self::undo_pending() ) {
				ob_start();
				self::post_button( 'undo', __( 'Undo', 'axle-smoke-test' ), array(), 'button-link ast-undo' );
				$undo = ' ' . ob_get_clean();
			}
			echo '<div class="notice notice-success is-dismissible ast-notice" data-speak="' . esc_attr( $messages[ $done ] ) . '"><p>' . esc_html( $messages[ $done ] ) . wp_kses( $undo, self::form_html() ) . '</p></div>';
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error ast-notice" data-speak="' . esc_attr( $error ) . '"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	/**
	 * The tags a post_button() needs, for wp_kses().
	 *
	 * @return array
	 */
	private static function form_html(): array {
		return array(
			'form'   => array(
				'method' => true,
				'action' => true,
				'class'  => true,
			),
			'input'  => array(
				'type'  => true,
				'name'  => true,
				'value' => true,
				'id'    => true,
			),
			'button' => array(
				'type'       => true,
				'class'      => true,
				'aria-label' => true,
			),
		);
	}

	/**
	 * Remembers what the Undo link would bring back, for ten minutes.
	 *
	 * @param array $undo type (url or finding) and what is needed to restore it.
	 */
	private static function remember_undo( array $undo ): void {
		set_transient( 'axle_smoke_test_undo_' . get_current_user_id(), $undo, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Whether the current user has something to undo.
	 *
	 * @return bool
	 */
	private static function undo_pending(): bool {
		return is_array( get_transient( 'axle_smoke_test_undo_' . get_current_user_id() ) );
	}

	/**
	 * The latest run card and the runs table.
	 */
	private function render_runs(): void {
		$runs   = $this->runner->runs();
		$latest = $runs->latest();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only filters the list.
		$filter = isset( $_GET['verdict'] ) && in_array( $_GET['verdict'], array( 'red', 'amber', 'green' ), true ) ? sanitize_key( wp_unslash( $_GET['verdict'] ) ) : '';

		$this->steps();

		echo '<div class="axle-toolbar ast-toolbar">';
		echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '" class="ast-filter">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label for="ast-verdict" class="screen-reader-text">' . esc_html__( 'Show runs with this verdict', 'axle-smoke-test' ) . '</label>';
		echo '<select id="ast-verdict" name="verdict">';
		foreach ( array(
			''      => __( 'All verdicts', 'axle-smoke-test' ),
			'red'   => __( 'Red', 'axle-smoke-test' ),
			'amber' => __( 'Amber', 'axle-smoke-test' ),
			'green' => __( 'Green', 'axle-smoke-test' ),
		) as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $filter, $value, false ), esc_html( $label ) );
		}
		echo '</select> <button type="submit" class="button">' . esc_html__( 'Filter runs', 'axle-smoke-test' ) . '</button>';
		echo '</form>';
		echo '<span class="axle-toolbar__end">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'axle_smoke_test_run' );
		echo '<input type="hidden" name="action" value="axle_smoke_test_run">';
		echo '<button type="submit" id="ast-run-now" class="button button-primary ast-run-now">' . esc_html__( 'Run now', 'axle-smoke-test' ) . '</button>';
		echo '</form>';
		echo '</span>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Run now checks every key URL from the server, then in this browser.', 'axle-smoke-test' ) . '</p>';
		echo '<div class="notice notice-info inline ast-run-box ast-panel-inline" role="status" aria-live="polite" aria-busy="false" hidden><p><span class="spinner"></span><span class="ast-panel-status"></span></p></div>';

		if ( ! $latest ) {
			echo '<div class="axle-empty"><p>' . esc_html__( 'No runs yet. Each run shows here with its verdict: red, amber or green. A run starts by itself after every plugin, theme or core update, theme switch, plugin activation or deploy. To set the first baseline now, press Run now.', 'axle-smoke-test' ) . '</p><p><a class="button" href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Choose the pages to check', 'axle-smoke-test' ) . '</a></p></div>';
			return;
		}

		$this->latest_card( $latest );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$query = '' !== $filter ? array( 'verdict' => $filter ) : array();
		$list  = $runs->list(
			$query + array(
				'per_page' => 20,
				'page'     => $paged,
			)
		);
		$total = $runs->count( $query );

		echo '<h2 class="axle-card__title">' . esc_html__( 'All runs', 'axle-smoke-test' ) . '</h2>';
		if ( ! $list ) {
			echo '<div class="axle-empty"><p>' . esc_html__( 'No run has this verdict. Pick another verdict to see the rest.', 'axle-smoke-test' ) . '</p></div>';
			return;
		}
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'All runs', 'axle-smoke-test' ) . '">';
		echo '<table class="widefat striped ast-runs"><thead><tr>';
		foreach ( array( __( 'Run', 'axle-smoke-test' ), __( 'Started', 'axle-smoke-test' ), __( 'Trigger', 'axle-smoke-test' ), __( 'Change', 'axle-smoke-test' ), __( 'Verdict', 'axle-smoke-test' ), __( 'Red / amber / green', 'axle-smoke-test' ), __( 'Duration', 'axle-smoke-test' ) ) as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $list as $run ) {
			$counts = (array) ( $run['summary']['counts'] ?? array() );
			echo '<tr>';
			/* translators: %d: run ID. */
			echo '<td><a class="row-title" href="' . esc_url( self::url( array( 'run' => $run['id'] ) ) ) . '">' . esc_html( sprintf( __( 'Run #%d', 'axle-smoke-test' ), $run['id'] ) ) . '</a></td>';
			echo '<td>' . Display::time( (string) $run['created_gmt'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			echo '<td>' . esc_html( Display::trigger( (string) $run['trigger_type'] ) ) . '</td>';
			echo '<td>' . esc_html( Display::change( $run ) ) . '</td>';
			echo '<td>' . Display::badge( (string) $run['verdict'], (string) $run['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			echo '<td>' . ( $counts ? esc_html( (int) ( $counts['red'] ?? 0 ) . ' / ' . (int) ( $counts['amber'] ?? 0 ) . ' / ' . (int) ( $counts['green'] ?? 0 ) ) : '' ) . '</td>';
			echo '<td>' . esc_html( Display::duration( (int) ( $run['context']['duration_ms'] ?? 0 ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		$pages = (int) ceil( $total / 20 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::url( $query ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * The "Get started" card, until every step is done or the user hides it.
	 */
	private function steps(): void {
		if ( get_user_meta( get_current_user_id(), self::STEPS_META, true ) ) {
			return;
		}

		$suggestions = Presets::suggestions( $this->runner->urls() );
		$steps       = array(
			array(
				'title' => __( 'Run the first check', 'axle-smoke-test' ),
				'text'  => __( 'It visits your key pages and saves how they look. Later runs are compared with that.', 'axle-smoke-test' ),
				'done'  => null !== $this->runner->runs()->latest_finished(),
				'link'  => '#ast-run-now',
				'label' => __( 'Go to Run now', 'axle-smoke-test' ),
			),
		);
		if ( $suggestions ) {
			$steps[] = array(
				'title' => __( 'Add the pages your site depends on', 'axle-smoke-test' ),
				'text'  => __( 'Shop, cart and checkout pages, or the home page of each language, are not checked yet.', 'axle-smoke-test' ),
				'done'  => false,
				'link'  => self::url( array( 'tab' => 'settings' ) ) . '#ast-suggestions',
				'label' => __( 'See the pages', 'axle-smoke-test' ),
			);
		}
		$steps[] = array(
			'title' => __( 'Choose who hears about problems', 'axle-smoke-test' ),
			'text'  => __( 'By default a red run emails the site admin. Add addresses or a webhook, or switch alerts off.', 'axle-smoke-test' ),
			'done'  => false !== get_option( Settings::OPTION, false ),
			'link'  => self::url( array( 'tab' => 'settings' ) ) . '#ast-alerts',
			'label' => __( 'Open the alert settings', 'axle-smoke-test' ),
		);

		/**
		 * Filters the steps of the Get started card.
		 *
		 * @param array[] $steps Each: title, text, done (bool), link, label.
		 */
		$steps = (array) apply_filters( 'axle_smoke_test_get_started_steps', $steps );
		if ( ! array_filter( $steps, static fn( $step ) => empty( $step['done'] ) ) ) {
			return;
		}

		echo '<section class="axle-card ast-steps" aria-labelledby="ast-steps-title">';
		echo '<div class="axle-card__head"><h2 class="axle-card__title" id="ast-steps-title">' . esc_html__( 'Get started', 'axle-smoke-test' ) . '</h2>';
		self::post_button( 'dismiss_steps', __( 'Hide these steps', 'axle-smoke-test' ), array(), 'button-link' );
		echo '</div><ol class="axle-steps">';
		foreach ( $steps as $step ) {
			echo '<li class="axle-step"><div class="axle-step__body"><strong class="axle-step__title">' . esc_html( (string) ( $step['title'] ?? '' ) ) . '</strong><p>' . esc_html( (string) ( $step['text'] ?? '' ) ) . '</p></div>';
			if ( ! empty( $step['done'] ) ) {
				echo '<span class="axle-badge axle-badge--good">' . esc_html__( 'Done', 'axle-smoke-test' ) . '</span>';
			} else {
				echo '<a class="button" href="' . esc_url( (string) ( $step['link'] ?? '#' ) ) . '">' . esc_html( (string) ( $step['label'] ?? '' ) ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ol></section>';
	}

	/**
	 * The latest run, as a card.
	 *
	 * @param array $run The run.
	 */
	private function latest_card( array $run ): void {
		$counts = (array) ( $run['summary']['counts'] ?? array() );
		$tiers  = (array) ( $run['summary']['tiers'] ?? array() );

		$this->stats( $counts );

		echo '<section class="axle-card ast-latest" aria-labelledby="ast-latest-title">';
		echo '<div class="axle-card__head"><h2 class="axle-card__title" id="ast-latest-title">' . esc_html__( 'Latest run', 'axle-smoke-test' ) . '</h2>' . Display::badge( (string) $run['verdict'], (string) $run['status'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
		echo '<dl class="axle-facts">';
		echo '<dt>' . esc_html__( 'When', 'axle-smoke-test' ) . '</dt><dd>' . Display::time( (string) $run['created_gmt'] ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
		echo '<dt>' . esc_html__( 'Trigger', 'axle-smoke-test' ) . '</dt><dd>' . esc_html( Display::trigger( (string) $run['trigger_type'] ) ) . '</dd>';
		$change = Display::change( $run );
		if ( '' !== $change ) {
			echo '<dt>' . esc_html__( 'Change', 'axle-smoke-test' ) . '</dt><dd>' . esc_html( $change ) . '</dd>';
		}
		echo '<dt>' . esc_html__( 'Checked by', 'axle-smoke-test' ) . '</dt><dd>' . esc_html( self::tiers( $tiers, (string) $run['status'] ) ) . '</dd>';
		echo '</dl>';
		echo '<p class="axle-actions"><a class="button" href="' . esc_url( self::url( array( 'run' => $run['id'] ) ) ) . '">' . esc_html__( 'See the results', 'axle-smoke-test' ) . '</a></p>';
		echo '</section>';
	}

	/**
	 * The page counts of a run, as stats.
	 *
	 * @param array $counts Counts by verdict.
	 */
	private function stats( array $counts ): void {
		if ( ! $counts ) {
			return;
		}
		$stats = array(
			'red'   => array( 'error', __( 'red pages', 'axle-smoke-test' ) ),
			'amber' => array( 'warning', __( 'amber pages', 'axle-smoke-test' ) ),
			'green' => array( 'good', __( 'green pages', 'axle-smoke-test' ) ),
		);
		echo '<div class="axle-stats">';
		foreach ( $stats as $verdict => $stat ) {
			echo '<div class="axle-stat axle-stat--' . esc_attr( $stat[0] ) . '"><span class="axle-stat__value">' . (int) ( $counts[ $verdict ] ?? 0 ) . '</span><span class="axle-stat__label">' . esc_html( $stat[1] ) . '</span></div>';
		}
		echo '</div>';
	}

	/**
	 * Which tiers produced results, in words.
	 *
	 * @param string[] $tiers  Tiers.
	 * @param string   $status Run status.
	 * @return string
	 */
	private static function tiers( array $tiers, string $status ): string {
		$labels = array(
			'server'  => __( 'the server', 'axle-smoke-test' ),
			'browser' => __( 'a browser', 'axle-smoke-test' ),
			'worker'  => __( 'an external worker', 'axle-smoke-test' ),
		);
		$names  = array();
		foreach ( $tiers as $tier ) {
			if ( isset( $labels[ $tier ] ) ) {
				$names[] = $labels[ $tier ];
			}
		}
		$text = $names ? implode( ', ', $names ) : __( 'nothing yet', 'axle-smoke-test' );
		if ( 'waiting_browser' === $status ) {
			$text .= ' ' . __( '(the browser check runs on the next admin page view)', 'axle-smoke-test' );
		}

		return $text;
	}

	/**
	 * One run: its results and actions.
	 *
	 * @param int $run_id Run ID.
	 */
	private function render_run( int $run_id ): void {
		$run = $this->runner->runs()->get( $run_id );
		if ( ! $run ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No such run.', 'axle-smoke-test' ) . '</p></div>';
			return;
		}

		$results = $this->runner->runs()->results( $run_id );
		$summary = $run['summary'];

		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'All runs', 'axle-smoke-test' ) . '</a></p>';
		$this->stats( (array) ( $summary['counts'] ?? array() ) );

		echo '<section class="axle-card" aria-labelledby="ast-run-title">';
		/* translators: %d: run ID. */
		echo '<div class="axle-card__head"><h2 class="axle-card__title ast-run-title" id="ast-run-title">' . esc_html( sprintf( __( 'Run #%d', 'axle-smoke-test' ), $run_id ) ) . ' ' . Display::badge( (string) $run['verdict'], (string) $run['status'] ) . '</h2></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.

		echo '<dl class="axle-facts">';
		echo '<dt>' . esc_html__( 'Started', 'axle-smoke-test' ) . '</dt><dd>' . Display::time( (string) $run['created_gmt'] ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
		echo '<dt>' . esc_html__( 'Trigger', 'axle-smoke-test' ) . '</dt><dd>' . esc_html( Display::trigger( (string) $run['trigger_type'] ) ) . '</dd>';
		$change = Display::change( $run );
		if ( '' !== $change ) {
			echo '<dt>' . esc_html__( 'Change', 'axle-smoke-test' ) . '</dt><dd class="ast-change">' . esc_html( $change ) . '</dd>';
		}
		echo '<dt>' . esc_html__( 'Status', 'axle-smoke-test' ) . '</dt><dd>' . esc_html( Display::status( (string) $run['status'] ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Checked by', 'axle-smoke-test' ) . '</dt><dd>' . esc_html( self::tiers( (array) ( $summary['tiers'] ?? array() ), (string) $run['status'] ) ) . '</dd>';
		$duration = Display::duration( (int) ( $run['context']['duration_ms'] ?? 0 ) );
		if ( '' !== $duration ) {
			echo '<dt>' . esc_html__( 'Server tier took', 'axle-smoke-test' ) . '</dt><dd>' . esc_html( $duration ) . '</dd>';
		}
		echo '</dl>';

		echo '<div class="axle-actions">';
		if ( $results ) {
			self::confirm_button(
				'baseline',
				__( 'Accept as baseline', 'axle-smoke-test' ),
				array( 'run' => $run_id ),
				__( 'Every page in this run becomes the baseline that later runs are compared with, in place of the old one. Use it when the changes were meant. You cannot undo it, but the next green run sets a new baseline.', 'axle-smoke-test' ),
				__( 'Replace the baseline', 'axle-smoke-test' )
			);
		}
		$report = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'axle-smoke-test/v1/runs/' . $run_id . '/report' ) );
		echo '<a class="button" href="' . esc_url( $report ) . '" download="smoke-test-' . (int) $run_id . '.json">' . esc_html__( 'Download report (JSON)', 'axle-smoke-test' ) . '</a>';
		echo '</div>';
		echo '</section>';

		if ( 'failed' === $run['status'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) ( $summary['error'] ?? '' ) ) . '</p>';
			if ( ! empty( $summary['loopback'] ) ) {
				/* translators: %s: core's loopback test result. */
				echo '<p>' . esc_html( sprintf( __( 'Site Health loopback test: %s', 'axle-smoke-test' ), (string) $summary['loopback'] ) ) . '</p>';
			}
			echo '</div>';
		}

		if ( 'waiting_browser' === $run['status'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Waiting for a browser: the browser check runs in the background on this screen or the next admin page you open.', 'axle-smoke-test' ) . '</p></div>';
		}

		if ( in_array( $run['verdict'], array( Verdict::RED, Verdict::AMBER ), true ) ) {
			$this->problems( $run );
		}

		if ( ! empty( $summary['rolled_back'] ) ) {
			foreach ( (array) $summary['rolled_back'] as $done ) {
				/* translators: 1: plugin or theme name, 2: broken version, 3: restored version. */
				echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( '%1$s was rolled back from %2$s to %3$s.', 'axle-smoke-test' ), (string) $done['name'], (string) $done['from'], (string) $done['to'] ) ) . '</p></div>';
			}
		}

		$this->results_table( $run, $results );
	}

	/**
	 * Rollback offers, errors by source and database warnings for a red or amber run.
	 *
	 * @param array $run The run.
	 */
	private function problems( array $run ): void {
		$summary = $run['summary'];
		$sources = (array) ( $summary['sources'] ?? array() );

		echo '<section class="axle-card ast-problems" aria-labelledby="ast-problems-title">';
		echo '<h2 class="axle-card__title" id="ast-problems-title">' . esc_html__( 'What to do', 'axle-smoke-test' ) . '</h2>';

		if ( $sources ) {
			echo '<p>' . esc_html__( 'Problems by source:', 'axle-smoke-test' ) . '</p><ul class="ul-disc">';
			foreach ( $sources as $source => $count ) {
				/* translators: 1: plugin or theme name, 2: number of problems. */
				echo '<li>' . esc_html( sprintf( _n( '%1$s: %2$d problem', '%1$s: %2$d problems', (int) $count, 'axle-smoke-test' ), Display::source( (string) $source ), (int) $count ) ) . '</li>';
			}
			echo '</ul>';
		}

		$candidates = 'rollback' === $run['trigger_type'] ? array() : $this->rollback->candidates( $run );
		$rolled     = wp_list_pluck( (array) ( $summary['rolled_back'] ?? array() ), 'id' );
		$first      = true;
		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate['id'], $rolled, true ) ) {
				continue;
			}
			if ( ! Rollback::may_roll_back( $candidate, get_current_user_id() ) ) {
				echo '<p class="description">' . esc_html__( 'You are not allowed to change this plugin or theme, so you cannot roll it back.', 'axle-smoke-test' ) . '</p>';
				continue;
			}
			self::confirm_button(
				'rollback',
				/* translators: 1: plugin or theme name, 2: version. */
				sprintf( __( 'Roll back %1$s to %2$s', 'axle-smoke-test' ), $candidate['name'], $candidate['version'] ),
				array(
					'run'  => (int) $run['id'],
					'item' => $candidate['id'],
				),
				/* translators: 1: plugin or theme name, 2: version now, 3: version it goes back to. */
				sprintf( __( 'This replaces the files of %1$s (version %2$s) with the saved copy of version %3$s, then runs the smoke test again. Changes the update made to the database stay. You cannot undo it here, but you can update again later.', 'axle-smoke-test' ), $candidate['name'], $candidate['current'], $candidate['version'] ),
				/* translators: %s: plugin or theme name. */
				sprintf( __( 'Roll back %s', 'axle-smoke-test' ), $candidate['name'] ),
				$first ? 'button button-primary ast-rollback' : 'button ast-rollback'
			);
			$first    = false;
			$rehearse = Family::rehearsal_url();
			if ( '' !== $rehearse ) {
				/* translators: %s: plugin or theme name. */
				echo '<p>' . wp_kses_post( sprintf( __( 'Next time, rehearse the update of %s first:', 'axle-smoke-test' ), esc_html( $candidate['name'] ) ) ) . ' <a href="' . esc_url( $rehearse ) . '">' . esc_html__( 'Update Rehearsal', 'axle-smoke-test' ) . '</a></p>';
			}
		}
		if ( ! $candidates && 'rollback' !== $run['trigger_type'] ) {
			echo '<p>' . esc_html__( 'No rollback is offered: this run has no plugin or theme update with a backup. Core updates are never rolled back.', 'axle-smoke-test' ) . '</p>';
		}

		$versions = (array) ( $summary['db_versions'] ?? array() );
		if ( $versions ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'These version options changed during the update. A rollback puts the files back, not the database changes the new version made:', 'axle-smoke-test' ) . '</p><ul>';
			foreach ( $versions as $version ) {
				echo '<li><code>' . esc_html( (string) $version['option'] ) . '</code>: ' . esc_html( (string) $version['before'] . ' > ' . (string) $version['after'] ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( Family::frozen() ) {
			echo '<p>' . esc_html__( 'The site is in read-only mode, so rollback is blocked until it is unfrozen.', 'axle-smoke-test' ) . '</p>';
		}

		echo '</section>';
	}

	/**
	 * The results table: one row per URL and tier.
	 *
	 * @param array   $run     The run.
	 * @param array[] $results Results.
	 */
	private function results_table( array $run, array $results ): void {
		if ( ! $results ) {
			echo '<div class="axle-empty"><p>' . esc_html__( 'No results yet. They show up here when the checks finish.', 'axle-smoke-test' ) . '</p></div>';
			return;
		}

		$tiers = array(
			'server'  => __( 'Server', 'axle-smoke-test' ),
			'browser' => __( 'Browser', 'axle-smoke-test' ),
			'worker'  => __( 'Worker', 'axle-smoke-test' ),
		);

		echo '<h2 class="axle-card__title">' . esc_html__( 'Results', 'axle-smoke-test' ) . '</h2>';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Results', 'axle-smoke-test' ) . '">';
		echo '<table class="widefat striped ast-results"><thead><tr>';
		foreach ( array( __( 'Page', 'axle-smoke-test' ), __( 'Tier', 'axle-smoke-test' ), __( 'Verdict', 'axle-smoke-test' ), __( 'Status before and after', 'axle-smoke-test' ), __( 'Time', 'axle-smoke-test' ), __( 'What changed', 'axle-smoke-test' ), __( 'Baseline', 'axle-smoke-test' ) ) as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $results as $result ) {
			$meta    = $result['meta'];
			$reasons = (array) ( $meta['reasons'] ?? array() );
			$label   = (string) ( $meta['label'] ?? $result['url_key'] );

			echo '<tr class="ast-result ast-result-' . esc_attr( (string) $result['verdict'] ) . '" data-url-key="' . esc_attr( (string) $result['url_key'] ) . '" data-tier="' . esc_attr( (string) $result['tier'] ) . '">';
			echo '<th scope="row"><strong>' . esc_html( $label ) . '</strong><br><a class="ast-url" href="' . esc_url( (string) $result['url'] ) . '">' . esc_html( (string) wp_parse_url( (string) $result['url'], PHP_URL_PATH ) ) . '</a></th>';
			echo '<td>' . esc_html( $tiers[ $result['tier'] ] ?? $result['tier'] ) . '</td>';
			echo '<td>' . Display::badge( (string) $result['verdict'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			if ( 'server' === $result['tier'] ) {
				$before = isset( $meta['baseline_status'] ) ? (string) (int) $meta['baseline_status'] : '-';
				$after  = $result['http_status'] ? (string) $result['http_status'] : '-';
				if ( $before === $after ) {
					echo '<td>' . esc_html( $after ) . '</td>';
				} else {
					echo '<td><div class="axle-diff"><del><span class="screen-reader-text">' . esc_html__( 'Before:', 'axle-smoke-test' ) . '</span> ' . esc_html( $before ) . '</del><ins><span class="screen-reader-text">' . esc_html__( 'After:', 'axle-smoke-test' ) . '</span> ' . esc_html( $after ) . '</ins></div></td>';
				}
				echo '<td>' . esc_html( Display::duration( (int) $result['ttfb_ms'] ) ) . '</td>';
			} else {
				echo '<td>-</td><td>' . esc_html( Display::duration( (int) ( $meta['load_ms'] ?? 0 ) ) ) . '</td>';
			}

			echo '<td>';
			if ( $reasons ) {
				echo '<ul class="ast-reasons">';
				foreach ( $reasons as $reason ) {
					$source = ! empty( $reason['source'] ) && 'unknown' !== $reason['source'] ? ' (' . Display::source( (string) $reason['source'] ) . ')' : '';
					echo '<li class="ast-reason">' . Display::badge( (string) $reason['level'] ) . ' ' . esc_html( (string) $reason['message'] . $source ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
					if ( ! empty( $reason['acceptable'] ) && ! empty( $reason['sig'] ) ) {
						self::confirm_button(
							'accept_finding',
							__( 'Accept this change', 'axle-smoke-test' ),
							array(
								'run'     => (int) $run['id'],
								'url_key' => (string) $result['url_key'],
								'sig'     => (string) $reason['sig'],
							),
							__( 'Later runs stop reporting this finding for this page, because the change was meant. Other findings still count. You can undo it right after.', 'axle-smoke-test' ),
							__( 'Accept this change', 'axle-smoke-test' ),
							'button-link ast-accept',
							/* translators: %s: page label. */
							sprintf( __( 'Accept this change on %s', 'axle-smoke-test' ), $label )
						);
					}
					echo '</li>';
				}
				echo '</ul>';
			} elseif ( ! empty( $meta['compared_with'] ) ) {
				echo esc_html__( 'Same as the baseline.', 'axle-smoke-test' );
			} else {
				echo esc_html__( 'No problems. This is now the baseline.', 'axle-smoke-test' );
			}
			if ( '' !== $result['thumb_path'] ) {
				$thumb = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'axle-smoke-test/v1/results/' . (int) $result['id'] . '/thumbnail' ) );
				/* translators: %s: page label. */
				echo '<p><img class="ast-thumb" src="' . esc_url( $thumb ) . '" alt="' . esc_attr( sprintf( __( 'Screenshot of %s from the worker', 'axle-smoke-test' ), $label ) ) . '" loading="lazy" width="240"></p>';
			}
			echo '</td>';

			echo '<td>';
			if ( $result['is_baseline'] ) {
				echo esc_html__( 'Baseline', 'axle-smoke-test' );
			} else {
				self::confirm_button(
					'baseline',
					__( 'Accept', 'axle-smoke-test' ),
					array(
						'run'     => (int) $run['id'],
						'url_key' => (string) $result['url_key'],
					),
					__( 'This page, as it is in this run, becomes its baseline in place of the old one. You cannot undo it, but the next green run sets a new baseline.', 'axle-smoke-test' ),
					__( 'Replace the baseline', 'axle-smoke-test' ),
					'button button-small',
					/* translators: %s: page label. */
					sprintf( __( 'Accept %s as baseline', 'axle-smoke-test' ), $label )
				);
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * A button that first asks, in place, what will change and whether it can be undone.
	 * It is a details element, so it works without JavaScript; admin.js moves focus into
	 * the question and back.
	 *
	 * @param string $action        Action name without the prefix.
	 * @param string $label         Text of the first button.
	 * @param array  $fields        Hidden fields.
	 * @param string $message       What will change, and whether it can be undone.
	 * @param string $confirm_label Text of the button that does it.
	 * @param string $classes       Classes of the first button.
	 * @param string $aria_label    Accessible name of the first button.
	 */
	public static function confirm_button( string $action, string $label, array $fields, string $message, string $confirm_label, string $classes = 'button', string $aria_label = '' ): void {
		static $count = 0;
		++$count;
		$id = 'ast-confirm-' . $count;

		echo '<details class="ast-confirm"><summary class="' . esc_attr( $classes ) . '"' . ( '' !== $aria_label ? ' aria-label="' . esc_attr( $aria_label ) . '"' : '' ) . '>' . esc_html( $label ) . '</summary>';
		echo '<div class="axle-confirm axle-confirm--danger" role="group" aria-labelledby="' . esc_attr( $id ) . '"><p id="' . esc_attr( $id ) . '">' . esc_html( $message ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'axle_smoke_test_' . $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( 'axle_smoke_test_' . $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		echo '<div class="axle-actions"><button type="submit" class="button axle-button--danger">' . esc_html( $confirm_label ) . '</button> <button type="button" class="button ast-cancel">' . esc_html__( 'Cancel', 'axle-smoke-test' ) . '</button></div>';
		echo '</form></div></details>';
	}

	/**
	 * A small form with one button that posts to admin-post.php.
	 *
	 * @param string $action     Action name without the prefix.
	 * @param string $label      Button text.
	 * @param array  $fields     Hidden fields.
	 * @param string $classes    Button classes.
	 * @param string $aria_label Accessible name, when the text is short.
	 */
	public static function post_button( string $action, string $label, array $fields, string $classes, string $aria_label = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ast-inline-form">';
		wp_nonce_field( 'axle_smoke_test_' . $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( 'axle_smoke_test_' . $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $classes ) . '"' . ( '' !== $aria_label ? ' aria-label="' . esc_attr( $aria_label ) . '"' : '' ) . '>' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Checks the nonce and capability of a form.
	 *
	 * @param string $action Action name without the prefix.
	 */
	private function verify( string $action ): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to use the smoke test.', 'axle-smoke-test' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'axle_smoke_test_' . $action );
	}

	/**
	 * Back to the screen with a message.
	 *
	 * @param array          $args   Query arguments.
	 * @param \WP_Error|null $error  An error to show.
	 */
	private function back( array $args, ?\WP_Error $error = null ): void {
		if ( $error ) {
			$args['error'] = rawurlencode( $error->get_error_message() );
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Saves the settings form.
	 */
	public function handle_save_settings(): void {
		$this->verify( 'save_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Checked in verify(); every field is sanitised in Settings::sanitize().
		$posted = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		$posted = SettingsView::from_form( (array) $posted );
		$this->settings->update( $posted );
		$this->back(
			array(
				'tab'  => 'settings',
				'done' => 'saved',
			)
		);
	}

	/**
	 * Adds a key URL.
	 */
	public function handle_add_url(): void {
		$this->verify( 'add_url' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$url      = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$expected = isset( $_POST['expected'] ) ? Urls::statuses( sanitize_text_field( wp_unslash( $_POST['expected'] ) ) ) : array( 200 );
		$browser  = ! empty( $_POST['browser'] );
		$label    = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		// phpcs:enable
		$added = $this->runner->urls()->add( $url, $expected, $browser, $label );
		$this->back(
			array(
				'tab'  => 'settings',
				'done' => is_wp_error( $added ) ? '' : 'url_added',
			),
			is_wp_error( $added ) ? $added : null
		);
	}

	/**
	 * Removes a key URL.
	 */
	public function handle_remove_url(): void {
		$this->verify( 'remove_url' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$key   = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$entry = $this->runner->urls()->find( $key );
		if ( $entry && 'custom' === $entry['source'] && $this->runner->urls()->remove( $key ) ) {
			self::remember_undo(
				array(
					'type'  => 'url',
					'entry' => $entry,
				)
			);
		}
		$this->back(
			array(
				'tab'  => 'settings',
				'done' => 'url_removed',
			)
		);
	}

	/**
	 * Saves the rules of one key URL.
	 */
	public function handle_save_rules(): void {
		$this->verify( 'save_rules' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in verify(); each line is cleaned in Rules::clean().
		$key   = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$rules = array(
			'must'     => isset( $_POST['must'] ) ? (string) wp_unslash( $_POST['must'] ) : '',
			'must_not' => isset( $_POST['must_not'] ) ? (string) wp_unslash( $_POST['must_not'] ) : '',
		);
		// phpcs:enable
		$saved = $this->runner->urls()->set_rules( $key, $rules );
		$this->back(
			array(
				'tab'  => 'settings',
				'done' => is_wp_error( $saved ) ? '' : 'rules_saved',
			),
			is_wp_error( $saved ) ? $saved : null
		);
	}

	/**
	 * Accepts one finding of the structure check.
	 */
	public function handle_accept_finding(): void {
		$this->verify( 'accept_finding' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$run = isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0;
		$key = isset( $_POST['url_key'] ) ? sanitize_key( wp_unslash( $_POST['url_key'] ) ) : '';
		$sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		// phpcs:enable
		$done = $this->runner->accept_finding( $run, $key, $sig );
		if ( ! is_wp_error( $done ) ) {
			self::remember_undo(
				array(
					'type' => 'finding',
					'run'  => $run,
					'key'  => $key,
					'sig'  => $sig,
				)
			);
		}
		$this->back(
			array(
				'run'  => $run,
				'done' => is_wp_error( $done ) ? '' : 'finding_accepted',
			),
			is_wp_error( $done ) ? $done : null
		);
	}

	/**
	 * Adds every suggested page.
	 */
	public function handle_add_suggestions(): void {
		$this->verify( 'add_suggestions' );
		$added = Presets::add( $this->runner->urls() );
		$this->back(
			array(
				'tab'  => 'settings',
				'done' => is_wp_error( $added ) ? '' : 'suggestions',
			),
			is_wp_error( $added ) ? $added : null
		);
	}

	/**
	 * Hides the Get started card for this user.
	 */
	public function handle_dismiss_steps(): void {
		$this->verify( 'dismiss_steps' );
		update_user_meta( get_current_user_id(), self::STEPS_META, 1 );
		$this->back( array( 'done' => 'steps_dismissed' ) );
	}

	/**
	 * Undoes the last removal of a URL or acceptance of a finding.
	 */
	public function handle_undo(): void {
		$this->verify( 'undo' );
		$key  = 'axle_smoke_test_undo_' . get_current_user_id();
		$undo = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $undo ) ) {
			$this->back( array(), new \WP_Error( 'axle_smoke_test_no_undo', __( 'There is nothing to undo any more.', 'axle-smoke-test' ) ) );
		}

		if ( 'url' === ( $undo['type'] ?? '' ) ) {
			$entry = (array) ( $undo['entry'] ?? array() );
			$added = $this->runner->urls()->add( (string) ( $entry['url'] ?? '' ), (array) ( $entry['expected'] ?? array( 200 ) ), ! empty( $entry['browser'] ), (string) ( $entry['label'] ?? '' ), (array) ( $entry['own_rules'] ?? array() ) );
			$this->back(
				array(
					'tab'  => 'settings',
					'done' => is_wp_error( $added ) ? '' : 'url_restored',
				),
				is_wp_error( $added ) ? $added : null
			);
		}

		$run  = (int) ( $undo['run'] ?? 0 );
		$done = $this->runner->restore_finding( $run, (string) ( $undo['key'] ?? '' ), (string) ( $undo['sig'] ?? '' ) );
		$this->back(
			array(
				'run'  => $run,
				'done' => is_wp_error( $done ) ? '' : 'finding_restored',
			),
			is_wp_error( $done ) ? $done : null
		);
	}

	/**
	 * Accepts a run, or one URL of it, as the baseline.
	 */
	public function handle_baseline(): void {
		$this->verify( 'baseline' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$run = isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0;
		$key = isset( $_POST['url_key'] ) ? sanitize_key( wp_unslash( $_POST['url_key'] ) ) : '';
		// phpcs:enable
		$accepted = $this->runner->accept_baseline( $run, $key );
		$this->back(
			array(
				'run'  => $run,
				'done' => is_wp_error( $accepted ) ? '' : 'baseline',
			),
			is_wp_error( $accepted ) ? $accepted : null
		);
	}

	/**
	 * Rolls back an item.
	 */
	public function handle_rollback(): void {
		$this->verify( 'rollback' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$run  = isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0;
		$item = isset( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : '';
		// phpcs:enable
		$result = $this->rollback->rollback( $run, $item, get_current_user_id(), true );
		if ( is_wp_error( $result ) ) {
			$this->back( array( 'run' => $run ), $result );
		}
		$new = is_array( $result['run'] ?? null ) ? (int) $result['run']['id'] : $run;
		$this->back(
			array(
				'run'  => $new,
				'done' => 'rolled_back',
			)
		);
	}

	/**
	 * Run now, without JavaScript: the server tier only.
	 */
	public function handle_run(): void {
		$this->verify( 'run' );
		$run = $this->runner->start( 'manual', array( 'browser' => false ), get_current_user_id() );
		if ( is_wp_error( $run ) ) {
			$this->back( array(), $run );
		}
		$this->back(
			array(
				'run'  => (int) $run['id'],
				'done' => 'ran',
			)
		);
	}

	/**
	 * Installs or removes the early must-use plugin.
	 */
	public function handle_mu_plugin(): void {
		$this->verify( 'mu_plugin' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$install = isset( $_POST['do'] ) && 'install' === sanitize_key( wp_unslash( $_POST['do'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in verify().
		if ( $install ) {
			$done = MuPlugin::install();
			$this->back(
				array(
					'tab'  => 'settings',
					'done' => is_wp_error( $done ) ? '' : 'mu_installed',
				),
				is_wp_error( $done ) ? $done : null
			);
		}
		MuPlugin::remove();
		$this->back(
			array(
				'tab'  => 'settings',
				'done' => 'mu_removed',
			)
		);
	}

	/**
	 * Dismisses the failed-run notice.
	 */
	public function handle_dismiss(): void {
		$this->verify( 'dismiss' );
		delete_option( \AryanX\Axle\SmokeTest\Alerts::NOTICE_OPTION );
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Registers the stylesheet, for this screen and the panel.
	 */
	public static function register_style(): void {
		wp_register_style( 'axle-smoke-test-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
	}

	/**
	 * The size of the backups folder, for the settings screen.
	 *
	 * @return int Bytes.
	 */
	public static function backups_size(): int {
		$size = 0;
		foreach ( Backups::all() as $entry ) {
			$size += (int) $entry['size'];
		}

		return $size;
	}
}
