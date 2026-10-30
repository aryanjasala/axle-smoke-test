<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

use AryanX\Axle\SmokeTest\Admin\Network;
use AryanX\Axle\SmokeTest\Admin\Page;
use AryanX\Axle\SmokeTest\Admin\Panel;
use AryanX\Axle\SmokeTest\Browser\Injector;
use AryanX\Axle\SmokeTest\Cli\Command;
use AryanX\Axle\SmokeTest\Health\SiteHealth;
use AryanX\Axle\SmokeTest\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

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
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->runs     = new Runs();
		$attribution    = new Attribution();
		$this->runner   = new Runner( $this->settings, $this->runs, new Urls( $this->settings ), new Checker( $this->settings, $attribution ), $attribution );
		$this->rollback = new Rollback( $this->settings, $this->runner );
		$this->cleanup  = new Cleanup( $this->settings, $this->runner );
	}

	/**
	 * The runner, for other plugins and deploy tooling.
	 *
	 * @return Runner
	 */
	public function runner(): Runner {
		return $this->runner;
	}

	/**
	 * Rollbacks, for other plugins.
	 *
	 * @return Rollback
	 */
	public function rollback(): Rollback {
		return $this->rollback;
	}

	/**
	 * Settings, for other plugins.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$services = array(
			new Schema(),
			$this->runner,
			new Triggers( $this->runner ),
			new Backups(),
			$this->rollback,
			new Alerts( $this->settings ),
			new Family(),
			$this->cleanup,
			new AdminSession(),
			new Injector(),
			new Controller( $this->runner, $this->rollback ),
			new Page( $this->settings, $this->runner, $this->rollback ),
			new Panel( $this->runner ),
			new Network(),
			new SiteHealth( $this->runs ),
			new Privacy( $this->runs, $this->settings ),
			new Abilities( $this->runner, $this->rollback ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-smoke-test', new Command( $this->runner, $this->rollback, $this->cleanup ) );
		}
	}
}
