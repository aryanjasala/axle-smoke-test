<?php
/**
 * Personal data: export, erase and the privacy policy text.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Runs remember who started them. Stored HTML is never kept: only hashes,
 * fingerprints and landmark counts. The alert email addresses live in the
 * settings. This class adds the exporter, the eraser and policy text.
 */
final class Privacy implements Service {

	private const PER_PAGE = 100;

	/**
	 * Storage.
	 *
	 * @var Runs
	 */
	private Runs $runs;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up privacy.
	 *
	 * @param Runs     $runs     Storage.
	 * @param Settings $settings Settings.
	 */
	public function __construct( Runs $runs, Settings $settings ) {
		$this->runs     = $runs;
		$this->settings = $settings;
	}

	/**
	 * Hooks into the privacy tools.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ): array {
		$exporters                    = (array) $exporters;
		$exporters['axle-smoke-test'] = array(
			'exporter_friendly_name' => __( 'Axle Smoke Test', 'axle-smoke-test' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers                    = (array) $erasers;
		$erasers['axle-smoke-test'] = array(
			'eraser_friendly_name' => __( 'Axle Smoke Test', 'axle-smoke-test' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the runs a user started, and whether they get alert emails.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$page = max( 1, (int) $page );
		$data = array();

		if ( 1 === $page && in_array( (string) $email, array_map( 'trim', explode( ',', (string) $this->settings->get( 'emails' ) ) ), true ) ) {
			$data[] = array(
				'group_id'    => 'axle-smoke-test-alerts',
				'group_label' => __( 'Smoke test alerts', 'axle-smoke-test' ),
				'item_id'     => 'axle-smoke-test-alerts',
				'data'        => array(
					array(
						'name'  => __( 'Alert email address', 'axle-smoke-test' ),
						'value' => (string) $email,
					),
				),
			);
		}

		$runs = $user ? $this->runs->by_user( (int) $user->ID, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) : array();
		foreach ( $runs as $run ) {
			$data[] = array(
				'group_id'    => 'axle-smoke-test-runs',
				'group_label' => __( 'Smoke test runs', 'axle-smoke-test' ),
				'item_id'     => 'axle-smoke-test-run-' . $run['id'],
				'data'        => array(
					array(
						'name'  => __( 'Run', 'axle-smoke-test' ),
						'value' => (string) $run['id'],
					),
					array(
						'name'  => __( 'Started', 'axle-smoke-test' ),
						'value' => (string) $run['created_gmt'],
					),
					array(
						'name'  => __( 'Trigger', 'axle-smoke-test' ),
						'value' => (string) $run['trigger_type'],
					),
					array(
						'name'  => __( 'Verdict', 'axle-smoke-test' ),
						'value' => (string) $run['verdict'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $runs ) < self::PER_PAGE,
		);
	}

	/**
	 * Takes the user off their runs and the address off the alert list.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function erase( $email, $page = 1 ): array {
		$removed = 0;
		$user    = get_user_by( 'email', (string) $email );
		if ( $user ) {
			$removed += $this->runs->forget_user( (int) $user->ID );
		}

		$emails = array_filter( array_map( 'trim', explode( ',', (string) $this->settings->get( 'emails' ) ) ) );
		if ( in_array( (string) $email, $emails, true ) ) {
			$this->settings->update( array( 'emails' => implode( ', ', array_diff( $emails, array( (string) $email ) ) ) ) );
			++$removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Axle Smoke Test', 'axle-smoke-test' ),
			'<p>' . esc_html__( 'After updates and deploys, this site loads its own key pages to check that they still work. The results record which administrator started each check. Page contents are not stored: only hashes, a text fingerprint and landmark counts. Results are deleted after 30 days by default. Alert emails go to the addresses an administrator set. No data is sent to other services, except alerts to a webhook the administrator set up.', 'axle-smoke-test' ) . '</p>'
		);
	}
}
