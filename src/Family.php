<?php
/**
 * Soft links to the other Axle plugins.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Works with the other Axle plugins when they are active, and does nothing
 * when they are not: Activity Log with Undo logs runs and rollbacks, Update
 * Rehearsal is suggested for the next update of a broken item, and Axle
 * Read-Only Mode blocks rollbacks during a freeze.
 */
final class Family implements Service {

	/**
	 * Hooks the activity log.
	 */
	public function register(): void {
		add_action( 'axle_smoke_test_run_finished', array( $this, 'log_run' ) );
		add_action( 'axle_smoke_test_rolled_back', array( $this, 'log_rollback' ), 10, 2 );
	}

	/**
	 * Whether Axle Read-Only Mode has the site frozen.
	 *
	 * @return bool
	 */
	public static function frozen(): bool {
		$class = '\AryanX\Axle\ReadOnlyMode\Plugin';
		if ( ! class_exists( $class ) ) {
			return false;
		}
		$plugin = call_user_func( array( $class, 'instance' ) );

		return is_object( $plugin ) && method_exists( $plugin, 'state' ) && $plugin->state()->is_frozen();
	}

	/**
	 * Update Rehearsal's screen, when it is active.
	 *
	 * @return string Empty without it.
	 */
	public static function rehearsal_url(): string {
		return class_exists( '\AryanX\Axle\UpdateRehearsal\Plugin' ) ? admin_url( 'tools.php?page=update-rehearsal' ) : '';
	}

	/**
	 * Logs a finished run in Activity Log with Undo.
	 *
	 * @param array $run The run.
	 */
	public function log_run( $run ): void {
		$run = (array) $run;
		if ( 'done' !== ( $run['status'] ?? '' ) ) {
			return;
		}
		self::log(
			'smoke_test_finished',
			(int) $run['id'],
			/* translators: 1: run ID, 2: verdict. */
			sprintf( __( 'Smoke test #%1$d: %2$s', 'axle-smoke-test' ), (int) $run['id'], (string) $run['verdict'] ),
			array(
				'verdict' => (string) $run['verdict'],
				'trigger' => (string) $run['trigger_type'],
				'change'  => (string) ( $run['summary']['change'] ?? '' ),
				'counts'  => (array) ( $run['summary']['counts'] ?? array() ),
			)
		);
	}

	/**
	 * Logs a rollback in Activity Log with Undo.
	 *
	 * @param array $candidate What was rolled back.
	 * @param array $run       The run.
	 */
	public function log_rollback( $candidate, $run ): void {
		$candidate = (array) $candidate;
		self::log(
			'smoke_test_rolled_back',
			(int) ( $run['id'] ?? 0 ),
			/* translators: 1: plugin or theme name, 2: broken version, 3: restored version. */
			sprintf( __( 'Rolled back %1$s from %2$s to %3$s', 'axle-smoke-test' ), (string) $candidate['name'], (string) $candidate['current'], (string) $candidate['version'] ),
			array(
				'item' => (string) $candidate['id'],
				'from' => (string) $candidate['current'],
				'to'   => (string) $candidate['version'],
			)
		);
	}

	/**
	 * Writes one entry to Activity Log with Undo, if it is active.
	 *
	 * @param string $event Event name.
	 * @param int    $id    Run ID.
	 * @param string $label Label.
	 * @param array  $after Details.
	 */
	private static function log( string $event, int $id, string $label, array $after ): void {
		$class = '\AryanX\Axle\ActivityLogWithUndo\Plugin';
		if ( ! class_exists( $class ) ) {
			return;
		}
		$plugin = call_user_func( array( $class, 'instance' ) );
		if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'logger' ) ) {
			return;
		}
		$plugin->logger()->log(
			array(
				'event'        => $event,
				'recorder'     => 'axle-smoke-test',
				'object_type'  => 'smoke_test_run',
				'object_id'    => (string) $id,
				'object_label' => $label,
				'after'        => $after,
				'undoable'     => false,
			)
		);
	}
}
