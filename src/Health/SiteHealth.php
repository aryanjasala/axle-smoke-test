<?php
/**
 * Site Health test.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Health;

use AryanX\Axle\SmokeTest\Admin\Display;
use AryanX\Axle\SmokeTest\Admin\Page;
use AryanX\Axle\SmokeTest\Backups;
use AryanX\Axle\SmokeTest\Runs;
use AryanX\Axle\SmokeTest\Service;
use AryanX\Axle\SmokeTest\Verdict;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Last smoke test" to Tools > Site Health: critical when the last run
 * was red or the tables are missing, recommended when it was amber, could not
 * run, or the backups folder is not writable.
 */
final class SiteHealth implements Service {

	/**
	 * Storage.
	 *
	 * @var Runs
	 */
	private Runs $runs;

	/**
	 * Sets up the test.
	 *
	 * @param Runs $runs Storage.
	 */
	public function __construct( Runs $runs ) {
		$this->runs = $runs;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public function add_test( $tests ): array {
		$tests                              = (array) $tests;
		$tests['direct']['axle_smoke_test'] = array(
			'label' => __( 'Last smoke test', 'axle-smoke-test' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array
	 */
	public function run(): array {
		$result = array(
			'label'       => __( 'The last smoke test passed', 'axle-smoke-test' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Stability', 'axle-smoke-test' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Axle Smoke Test checks your key pages after every update and deploy.', 'axle-smoke-test' ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( Page::url() ) . '">' . esc_html__( 'Open Smoke Test', 'axle-smoke-test' ) . '</a></p>',
			'test'        => 'axle_smoke_test',
		);

		if ( ! $this->runs->tables_exist() ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'The smoke test tables are missing', 'axle-smoke-test' );
			$result['description'] = '<p>' . esc_html__( 'Deactivate and activate Axle Smoke Test to create them again.', 'axle-smoke-test' ) . '</p>';
			return $result;
		}

		$run = $this->runs->latest_finished();
		if ( ! $run ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'No smoke test has run yet', 'axle-smoke-test' );
			$result['description'] = '<p>' . esc_html__( 'Run one now to set the baseline the next updates are compared with.', 'axle-smoke-test' ) . '</p>';
			return $result;
		}

		$change = Display::change( $run );
		if ( 'failed' === $run['status'] ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'The last smoke test could not run', 'axle-smoke-test' );
			$result['description'] = '<p>' . esc_html( (string) ( $run['summary']['error'] ?? '' ) ) . '</p>';
		} elseif ( Verdict::RED === $run['verdict'] ) {
			$result['status'] = 'critical';
			$result['label']  = __( 'Last smoke test failed', 'axle-smoke-test' );
			/* translators: %s: what changed. */
			$result['description'] = '<p>' . esc_html( '' !== $change ? sprintf( __( 'Key pages broke after %s.', 'axle-smoke-test' ), $change ) : __( 'Key pages are broken.', 'axle-smoke-test' ) ) . '</p>';
		} elseif ( Verdict::AMBER === $run['verdict'] ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'The last smoke test found something worth a look', 'axle-smoke-test' );
			$result['description'] = '<p>' . esc_html__( 'New warnings, JavaScript errors, missing files or layout changes. Accept the run as the baseline if the change was intended.', 'axle-smoke-test' ) . '</p>';
		}
		$result['actions'] = '<p><a href="' . esc_url( Page::url( array( 'run' => $run['id'] ) ) ) . '">' . esc_html__( 'See the run', 'axle-smoke-test' ) . '</a></p>';

		if ( 'good' === $result['status'] && ! Backups::prepare_dir() ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Smoke test backups cannot be written', 'axle-smoke-test' );
			$result['description'] = '<p>' . esc_html__( 'wp-content/axle-smoke-test/backups is not writable, so updates cannot be rolled back.', 'axle-smoke-test' ) . '</p>';
		}

		return $result;
	}
}
