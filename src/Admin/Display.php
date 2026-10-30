<?php
/**
 * Small display helpers for the screens.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Admin;

use AryanX\Axle\SmokeTest\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Escaped HTML snippets shared by the screens.
 */
final class Display {

	/**
	 * A verdict badge.
	 *
	 * @param string $verdict red, amber, green or empty.
	 * @param string $status  Run status, for runs without a verdict.
	 * @return string HTML.
	 */
	public static function badge( string $verdict, string $status = '' ): string {
		$labels = array(
			'red'   => __( 'Red', 'axle-smoke-test' ),
			'amber' => __( 'Amber', 'axle-smoke-test' ),
			'green' => __( 'Green', 'axle-smoke-test' ),
		);
		if ( isset( $labels[ $verdict ] ) ) {
			return '<span class="ast-badge ast-badge-' . esc_attr( $verdict ) . '">' . esc_html( $labels[ $verdict ] ) . '</span>';
		}

		return '<span class="ast-badge ast-badge-none">' . esc_html( self::status( $status ) ) . '</span>';
	}

	/**
	 * A run status in words.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status( string $status ): string {
		$labels = array(
			'queued'          => __( 'Queued', 'axle-smoke-test' ),
			'running'         => __( 'Running', 'axle-smoke-test' ),
			'waiting_browser' => __( 'Waiting for a browser', 'axle-smoke-test' ),
			'done'            => __( 'Done', 'axle-smoke-test' ),
			'failed'          => __( 'Could not run', 'axle-smoke-test' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * A trigger in words.
	 *
	 * @param string $trigger Trigger.
	 * @return string
	 */
	public static function trigger( string $trigger ): string {
		$labels = array(
			'update'   => __( 'Update', 'axle-smoke-test' ),
			'install'  => __( 'Install or activation', 'axle-smoke-test' ),
			'theme'    => __( 'Theme switch', 'axle-smoke-test' ),
			'deploy'   => __( 'Deploy', 'axle-smoke-test' ),
			'manual'   => __( 'Run now', 'axle-smoke-test' ),
			'cli'      => __( 'WP-CLI', 'axle-smoke-test' ),
			'rest'     => __( 'REST API', 'axle-smoke-test' ),
			'schedule' => __( 'Daily check', 'axle-smoke-test' ),
			'rollback' => __( 'After a rollback', 'axle-smoke-test' ),
		);

		return $labels[ $trigger ] ?? $trigger;
	}

	/**
	 * A GMT MySQL date in the site's format and time zone.
	 *
	 * @param string $gmt Date.
	 * @return string
	 */
	public static function date( string $gmt ): string {
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return '';
		}
		$time = strtotime( $gmt . ' UTC' );

		return $time ? wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $time ) : '';
	}

	/**
	 * A run's change in words, or its reason.
	 *
	 * @param array $run The run.
	 * @return string
	 */
	public static function change( array $run ): string {
		$change = (string) ( $run['summary']['change'] ?? '' );
		if ( '' === $change ) {
			$change = \AryanX\Axle\SmokeTest\Runner::describe_change( (array) ( $run['context']['items'] ?? array() ) );
		}
		$reason = (string) ( $run['context']['reason'] ?? '' );

		return '' !== $change ? $change : $reason;
	}

	/**
	 * A source label: plugin or theme name.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	public static function source( string $source ): string {
		return '' === $source ? '' : Attribution::label( $source );
	}

	/**
	 * Milliseconds in words.
	 *
	 * @param int $ms Milliseconds.
	 * @return string
	 */
	public static function duration( int $ms ): string {
		if ( $ms <= 0 ) {
			return '';
		}
		/* translators: %s: seconds with one decimal. */
		return $ms < 1000 ? sprintf( __( '%d ms', 'axle-smoke-test' ), $ms ) : sprintf( __( '%s s', 'axle-smoke-test' ), number_format_i18n( $ms / 1000, 1 ) );
	}
}
