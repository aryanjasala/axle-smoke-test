<?php
/**
 * Network Admin: the latest verdict of every site.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Admin;

use AryanX\Axle\SmokeTest\Runs;
use AryanX\Axle\SmokeTest\Schema;
use AryanX\Axle\SmokeTest\Service;

defined( 'ABSPATH' ) || exit;

/**
 * On multisite, Network Admin > Settings > Smoke Test lists each site's
 * latest run. Each site keeps its own runs, URLs and settings.
 */
final class Network implements Service {

	/**
	 * The screen's hook suffix.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Hooks the network menu.
	 */
	public function register(): void {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		}
	}

	/**
	 * Loads the shared stylesheet on this screen only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ): void {
		if ( '' !== $this->hook && $hook === $this->hook ) {
			AxleUi::enqueue();
		}
	}

	/**
	 * Adds the page.
	 */
	public function menu(): void {
		$this->hook = (string) add_submenu_page(
			'settings.php',
			__( 'Smoke Test', 'axle-smoke-test' ),
			__( 'Smoke Test', 'axle-smoke-test' ),
			'manage_network_options',
			'axle-smoke-test-network',
			array( $this, 'render' )
		);
	}

	/**
	 * The latest run of each site.
	 *
	 * @return array[] Each: site ID, name, url, run (or null).
	 */
	public static function latest_per_site(): array {
		$rows  = array();
		$sites = get_sites(
			array(
				'number' => 100,
				'fields' => 'ids',
			)
		);
		foreach ( $sites as $site_id ) {
			switch_to_blog( (int) $site_id );
			$runs   = new Runs();
			$run    = get_option( Schema::DB_VERSION_OPTION ) ? $runs->latest_finished() : null;
			$rows[] = array(
				'site' => (int) $site_id,
				'name' => (string) get_option( 'blogname' ),
				'url'  => admin_url( 'tools.php?page=' . Page::SLUG ),
				'run'  => $run,
			);
			restore_current_blog();
		}

		return $rows;
	}

	/**
	 * Draws the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see this page.', 'axle-smoke-test' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap axle-ui ast-wrap"><h1>' . esc_html__( 'Smoke Test', 'axle-smoke-test' ) . '</h1><hr class="wp-header-end">';
		echo '<p class="axle-intro">' . esc_html__( 'The latest smoke test of each site. Open a site\'s Tools > Smoke Test for its runs and settings.', 'axle-smoke-test' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Site', 'axle-smoke-test' ) . '</th><th scope="col">' . esc_html__( 'Verdict', 'axle-smoke-test' ) . '</th><th scope="col">' . esc_html__( 'When', 'axle-smoke-test' ) . '</th><th scope="col">' . esc_html__( 'Change', 'axle-smoke-test' ) . '</th></tr></thead><tbody>';
		foreach ( self::latest_per_site() as $row ) {
			$run = $row['run'];
			echo '<tr><th scope="row"><a href="' . esc_url( $row['url'] ) . '">' . esc_html( $row['name'] ) . '</a></th>';
			echo '<td>' . ( $run ? Display::badge( (string) $run['verdict'], (string) $run['status'] ) : '<span class="axle-badge axle-badge--neutral">' . esc_html__( 'No runs yet', 'axle-smoke-test' ) . '</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			echo '<td>' . ( $run ? Display::time( (string) $run['created_gmt'] ) : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			echo '<td>' . esc_html( $run ? Display::change( $run ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		AxleUi::footer();
		echo '</div>';
	}
}
