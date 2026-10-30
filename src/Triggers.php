<?php
/**
 * What starts a run on its own.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Queues a run after plugin, theme, core and translation updates (manual or
 * automatic), plugin installs and activations, theme switches, and deploys
 * noticed by the five-minute code fingerprint check.
 */
final class Triggers implements Service {

	/**
	 * The runner.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Versions before the updates in this request, by type:id.
	 *
	 * @var array<string, string>
	 */
	private array $before = array();

	/**
	 * Options ending in _version, before the first update in this request.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $versions = null;

	/**
	 * Sets up the triggers.
	 *
	 * @param Runner $runner The runner.
	 */
	public function __construct( Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Hooks the events.
	 */
	public function register(): void {
		add_filter( 'upgrader_pre_install', array( $this, 'remember' ), 5, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'updated' ), 20, 2 );
		add_action( 'automatic_updates_complete', array( $this, 'auto_updated' ) );
		add_action( 'activated_plugin', array( $this, 'activated' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'switched' ), 10, 3 );
		add_action( Schema::DEPLOY_HOOK, array( $this, 'scheduled_deploy_check' ) );
		add_action( 'axle_smoke_test_run_started', array( $this, 'refresh_fingerprint' ) );
	}

	/**
	 * Before a plugin or theme is replaced: its version, and the version options.
	 *
	 * @param mixed $response   Passed through.
	 * @param array $hook_extra plugin or theme being installed.
	 * @return mixed
	 */
	public function remember( $response, $hook_extra ) {
		$hook_extra = (array) $hook_extra;
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$this->before[ 'plugin:' . $hook_extra['plugin'] ] = self::plugin_version( (string) $hook_extra['plugin'] );
		}
		if ( ! empty( $hook_extra['theme'] ) ) {
			$theme = wp_get_theme( (string) $hook_extra['theme'] );
			$this->before[ 'theme:' . $hook_extra['theme'] ] = $theme->exists() ? (string) $theme->get( 'Version' ) : '';
		}
		if ( null === $this->versions ) {
			$this->versions = self::version_options();
		}

		return $response;
	}

	/**
	 * After an upgrader run.
	 *
	 * @param mixed $upgrader   The upgrader.
	 * @param array $hook_extra type, action, plugins, themes.
	 */
	public function updated( $upgrader, $hook_extra ): void {
		$hook_extra = (array) $hook_extra;
		$type       = (string) ( $hook_extra['type'] ?? '' );
		$action     = (string) ( $hook_extra['action'] ?? '' );
		$items      = array();

		if ( 'plugin' === $type ) {
			$files = (array) ( $hook_extra['plugins'] ?? array() );
			if ( ! empty( $hook_extra['plugin'] ) ) {
				$files[] = (string) $hook_extra['plugin'];
			}
			if ( 'install' === $action && is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) {
				$files[] = (string) $upgrader->plugin_info();
			}
			foreach ( array_unique( array_filter( $files ) ) as $file ) {
				$items[] = $this->plugin_item( (string) $file );
			}
		} elseif ( 'theme' === $type ) {
			$themes = (array) ( $hook_extra['themes'] ?? array() );
			if ( ! empty( $hook_extra['theme'] ) ) {
				$themes[] = (string) $hook_extra['theme'];
			}
			if ( 'install' === $action && is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ) {
				$info = $upgrader->theme_info();
				if ( $info instanceof \WP_Theme ) {
					$themes[] = $info->get_stylesheet();
				}
			}
			foreach ( array_unique( array_filter( $themes ) ) as $stylesheet ) {
				$items[] = $this->theme_item( (string) $stylesheet );
			}
		} elseif ( 'core' === $type ) {
			$items[] = array(
				'type' => 'core',
				'id'   => 'wordpress',
				'name' => 'WordPress',
				'from' => (string) get_bloginfo( 'version' ),
				'to'   => self::core_version(),
			);
		} elseif ( 'translation' === $type ) {
			$items[] = array(
				'type' => 'translation',
				'id'   => 'translations',
				'name' => __( 'Translations', 'axle-smoke-test' ),
				'from' => '',
				'to'   => '',
			);
		}

		if ( ! $items ) {
			return;
		}

		Fingerprint::save( Fingerprint::parts() );
		$this->runner->queue(
			'install' === $action ? 'install' : 'update',
			array(
				'items'           => $items,
				'versions_before' => $this->versions ?? array(),
			),
			get_current_user_id()
		);
	}

	/**
	 * After background (automatic) updates.
	 *
	 * @param array $results Results by type.
	 */
	public function auto_updated( $results ): void {
		$items = array();
		foreach ( (array) $results as $type => $list ) {
			foreach ( (array) $list as $result ) {
				$item = is_object( $result ) && isset( $result->item ) ? $result->item : null;
				if ( 'plugin' === $type && is_object( $item ) && ! empty( $item->plugin ) ) {
					$items[] = $this->plugin_item( (string) $item->plugin );
				} elseif ( 'theme' === $type && is_object( $item ) && ! empty( $item->theme ) ) {
					$items[] = $this->theme_item( (string) $item->theme );
				} elseif ( 'core' === $type ) {
					$items[] = array(
						'type' => 'core',
						'id'   => 'wordpress',
						'name' => 'WordPress',
						'from' => '',
						'to'   => self::core_version(),
					);
				}
			}
		}
		if ( $items ) {
			$this->runner->queue( 'update', array( 'items' => $items ), 0 );
		}
	}

	/**
	 * After a plugin was activated.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Network activation.
	 */
	public function activated( $plugin, $network_wide = false ): void {
		if ( plugin_basename( PLUGIN_FILE ) === $plugin ) {
			return;
		}

		$item         = $this->plugin_item( (string) $plugin );
		$item['from'] = '';
		$this->runner->queue(
			'install',
			array(
				'items'   => array( $item ),
				'network' => (bool) $network_wide,
			),
			get_current_user_id()
		);
	}

	/**
	 * After the theme was switched.
	 *
	 * @param string    $new_name  New theme name.
	 * @param \WP_Theme $new_theme New theme.
	 * @param \WP_Theme $old_theme Old theme.
	 */
	public function switched( $new_name, $new_theme = null, $old_theme = null ): void {
		$this->runner->queue(
			'theme',
			array(
				'items' => array(
					array(
						'type' => 'theme',
						'id'   => $new_theme instanceof \WP_Theme ? $new_theme->get_stylesheet() : (string) $new_name,
						'name' => __( 'Theme', 'axle-smoke-test' ),
						'from' => $old_theme instanceof \WP_Theme ? (string) $old_theme->get( 'Name' ) : '',
						'to'   => (string) $new_name,
					),
				),
			),
			get_current_user_id()
		);
	}

	/**
	 * The five-minute event.
	 */
	public function scheduled_deploy_check(): void {
		$this->deploy_check();
	}

	/**
	 * The five-minute deploy check.
	 *
	 * @return int Run ID, 0 when nothing changed.
	 */
	public function deploy_check(): int {
		$before = Fingerprint::stored();
		$after  = Fingerprint::parts();
		Fingerprint::save( $after );
		if ( ! $before ) {
			return 0;
		}

		$changed = Fingerprint::diff( $before, $after );
		if ( ! $changed ) {
			return 0;
		}

		$items = Fingerprint::items( $before, $after, $changed );

		/**
		 * Fires when the code fingerprint changed without an update through WordPress.
		 *
		 * @param string[] $changed Names of the parts that changed.
		 * @param array[]  $items   Changed plugins and themes with old and new versions.
		 */
		do_action( 'axle_smoke_test_deploy_detected', $changed, $items );

		return $this->runner->queue( 'deploy', array( 'items' => $items ), 0 );
	}

	/**
	 * A run checks the code as it is now: later deploy checks start from here.
	 */
	public function refresh_fingerprint(): void {
		Fingerprint::save( Fingerprint::parts() );
	}

	/**
	 * A changed plugin, for the run context.
	 *
	 * @param string $file Plugin file.
	 * @return array
	 */
	private function plugin_item( string $file ): array {
		$data = self::plugin_data( $file );

		return array(
			'type' => 'plugin',
			'id'   => $file,
			'name' => '' !== $data['Name'] ? $data['Name'] : $file,
			'from' => $this->before[ 'plugin:' . $file ] ?? '',
			'to'   => $data['Version'],
		);
	}

	/**
	 * A changed theme, for the run context.
	 *
	 * @param string $stylesheet Theme folder.
	 * @return array
	 */
	private function theme_item( string $stylesheet ): array {
		$theme = wp_get_theme( $stylesheet );
		$theme->cache_delete();
		$theme = wp_get_theme( $stylesheet );

		return array(
			'type' => 'theme',
			'id'   => $stylesheet,
			'name' => $theme->exists() ? (string) $theme->get( 'Name' ) : $stylesheet,
			'from' => $this->before[ 'theme:' . $stylesheet ] ?? '',
			'to'   => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
		);
	}

	/**
	 * A plugin's name and version, read from its file (not the plugin cache).
	 *
	 * @param string $file Plugin file.
	 * @return array{Name: string, Version: string}
	 */
	public static function plugin_data( string $file ): array {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( '' === $file || ! is_readable( $path ) ) {
			return array(
				'Name'    => '',
				'Version' => '',
			);
		}
		$data = get_file_data(
			$path,
			array(
				'Name'    => 'Plugin Name',
				'Version' => 'Version',
			)
		);

		return array(
			'Name'    => (string) $data['Name'],
			'Version' => (string) $data['Version'],
		);
	}

	/**
	 * A plugin's version from its file.
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	public static function plugin_version( string $file ): string {
		return self::plugin_data( $file )['Version'];
	}

	/**
	 * The WordPress version on disk, which may be newer than the one loaded.
	 *
	 * @return string
	 */
	public static function core_version(): string {
		$wp_version = '';
		$file       = ABSPATH . WPINC . '/version.php';
		if ( is_readable( $file ) ) {
			include $file;
		}

		return (string) $wp_version;
	}

	/**
	 * Options ending in _version or _db_version, from the autoloaded options.
	 *
	 * @return array<string, string>
	 */
	public static function version_options(): array {
		$versions = array();
		foreach ( wp_load_alloptions() as $name => $value ) {
			if ( preg_match( '/_version$/', (string) $name ) && is_scalar( $value ) && strlen( (string) $value ) < 64 && 0 !== strpos( (string) $name, 'axle_smoke_test_' ) ) {
				$versions[ (string) $name ] = (string) $value;
			}
		}

		return $versions;
	}
}
