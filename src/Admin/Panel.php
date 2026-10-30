<?php
/**
 * The "Checking your site" panel, the browser runner and the alert notice.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Admin;

use AryanX\Axle\SmokeTest\Alerts;
use AryanX\Axle\SmokeTest\Runner;
use AryanX\Axle\SmokeTest\Service;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Verdict;
use const AryanX\Axle\SmokeTest\PLUGIN_FILE;
use const AryanX\Axle\SmokeTest\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * On the update screens (Plugins, Themes, Updates, the install screens and
 * update.php), a small panel runs the smoke test right after an update and
 * shows the verdict. On any admin screen, a run that waits for a browser is
 * picked up in the background. A failed run leaves a notice until it is
 * dismissed or a green run follows.
 */
final class Panel implements Service {

	/** Screens where updates and installs happen. */
	public const UPDATE_SCREENS = array( 'plugins.php', 'update-core.php', 'update.php', 'themes.php', 'plugin-install.php', 'theme-install.php' );

	/** Screens where hidden page loads would get in the way. */
	private const SKIP_SCREENS = array( 'post.php', 'post-new.php', 'site-editor.php', 'customize.php', 'widgets.php' );

	/**
	 * The runner.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * The run this page view picks up, if any.
	 *
	 * @var array|null
	 */
	private static ?array $pending = null;

	/**
	 * Whether this screen shows the panel.
	 *
	 * @var bool
	 */
	private bool $show = false;

	/**
	 * Whether the runner config was printed.
	 *
	 * @var bool
	 */
	private static bool $enqueued = false;

	/**
	 * Sets up the panel.
	 *
	 * @param Runner $runner The runner.
	 */
	public function __construct( Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Hooks the assets and notices.
	 */
	public function register(): void {
		add_action( 'admin_init', array( self::class, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Registers the scripts and the stylesheet.
	 */
	public static function register_assets(): void {
		Page::register_style();
		wp_register_script( 'axle-smoke-test-lib', plugins_url( 'assets/smoke-lib.js', PLUGIN_FILE ), array(), VERSION, true );
		wp_register_script( 'axle-smoke-test-runner', plugins_url( 'assets/runner.js', PLUGIN_FILE ), array( 'axle-smoke-test-lib', 'wp-api-fetch', 'wp-hooks', 'wp-i18n' ), VERSION, true );
		wp_set_script_translations( 'axle-smoke-test-runner', 'axle-smoke-test' );
	}

	/**
	 * Decides whether this screen runs anything, and loads the runner if so.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function maybe_enqueue( $hook ): void {
		if ( ! current_user_can( Settings::capability() ) || in_array( $hook, self::SKIP_SCREENS, true ) ) {
			return;
		}

		$runs    = $this->runner->runs();
		$waiting = $runs->latest( array( 'waiting_browser' ) );
		if ( $waiting && strtotime( $waiting['created_gmt'] . ' UTC' ) < time() - DAY_IN_SECONDS ) {
			$waiting = null;
		}
		$update_screen = in_array( $hook, self::UPDATE_SCREENS, true );

		self::$pending = $waiting;
		if ( ! self::$pending && $update_screen ) {
			self::$pending = $runs->latest( array( 'queued' ) );
		}

		$this->show = $update_screen || null !== self::$pending;
		if ( ! $this->show && 'tools_page_' . Page::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'axle-smoke-test-admin' );
		self::enqueue_runner();
	}

	/**
	 * Loads the runner with its settings.
	 */
	public static function enqueue_runner(): void {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;
		if ( ! wp_script_is( 'axle-smoke-test-runner', 'registered' ) ) {
			self::register_assets();
		}

		/**
		 * Filters the CSS selectors whose position, size and visibility the
		 * browser tier records. Up to three matches of each are measured.
		 *
		 * @param string[] $selectors Default: header, nav, main, footer, h1, form and the top-level blocks.
		 */
		$selectors = (array) apply_filters( 'axle_smoke_test_layout_selectors', array( 'header', 'nav', 'main', 'footer', 'h1', 'form', '.wp-site-blocks > *', '#page', '#content' ) );
		$selectors = array_values( array_filter( array_map( 'strval', $selectors ) ) );

		$config = array(
			'screenUrl' => Page::url(),
			'runId'     => self::$pending ? (int) self::$pending['id'] : 0,
			'runStatus' => self::$pending ? (string) self::$pending['status'] : '',
			'selectors' => $selectors,
			/**
			 * Filters how long the browser tier waits after a page's load event
			 * before it measures the layout, in milliseconds.
			 *
			 * @param int $ms Default 1500.
			 */
			'settleMs'  => Settings::filtered_int( 'settle_ms', 1500, 0, 30000 ),
			'timeoutMs' => 30000,
		);

		wp_enqueue_script( 'axle-smoke-test-runner' );
		wp_add_inline_script( 'axle-smoke-test-runner', 'window.axleSmokeTestRunner = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Prints the panel and the alert notice.
	 */
	public function notices(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}

		if ( $this->show ) {
			$hidden = self::$pending ? '' : ' hidden';
			echo '<div class="notice notice-info ast-panel"' . esc_attr( $hidden ) . ' role="status" aria-live="polite">';
			echo '<p><strong>' . esc_html__( 'Smoke test', 'axle-smoke-test' ) . '</strong> <span class="ast-panel-status">';
			echo self::$pending && 'waiting_browser' === self::$pending['status'] ? esc_html__( 'Waiting for a browser: checking your key pages in this one.', 'axle-smoke-test' ) : esc_html__( 'Checking your site after the change.', 'axle-smoke-test' );
			echo '</span> <a class="ast-panel-link" href="' . esc_url( Page::url() ) . '" hidden>' . esc_html__( 'See the details', 'axle-smoke-test' ) . '</a></p>';
			echo '</div>';
		}

		$this->failed_notice();
	}

	/**
	 * The notice for the last failed run.
	 */
	private function failed_notice(): void {
		$run_id = (int) get_option( Alerts::NOTICE_OPTION );
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $run_id || ( $screen && 'tools_page_' . Page::SLUG === $screen->id ) ) {
			return;
		}
		$run = $this->runner->runs()->get( $run_id );
		if ( ! $run || ! in_array( $run['verdict'], array( Verdict::RED, Verdict::AMBER ), true ) ) {
			return;
		}

		$change = Display::change( $run );
		$text   = Verdict::RED === $run['verdict']
			/* translators: %s: what changed. */
			? ( '' !== $change ? sprintf( __( 'The last smoke test failed after %s.', 'axle-smoke-test' ), $change ) : __( 'The last smoke test failed.', 'axle-smoke-test' ) )
			/* translators: %s: what changed. */
			: ( '' !== $change ? sprintf( __( 'The last smoke test found something worth a look after %s.', 'axle-smoke-test' ), $change ) : __( 'The last smoke test found something worth a look.', 'axle-smoke-test' ) );

		echo '<div class="notice ' . ( Verdict::RED === $run['verdict'] ? 'notice-error' : 'notice-warning' ) . ' ast-alert-notice">';
		echo '<p>' . esc_html( $text ) . ' <a href="' . esc_url( Page::url( array( 'run' => $run_id ) ) ) . '">' . esc_html__( 'See what broke', 'axle-smoke-test' ) . '</a></p>';
		Page::post_button( 'dismiss', __( 'Dismiss', 'axle-smoke-test' ), array(), 'button-link ast-dismiss', __( 'Dismiss the smoke test notice', 'axle-smoke-test' ) );
		echo '</div>';
	}
}
