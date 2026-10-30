<?php
/**
 * The browser tier's collector, injected into pages loaded with a run token.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Browser;

use AryanX\Axle\SmokeTest\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Issues browser tokens and, on a front-end or login page requested with
 * ?axle_smoke=<token>, puts the collector script right after <head> (through
 * an output buffer, so it runs before every other script). The token only
 * switches collection on: it never signs anyone in or changes who the user is.
 */
final class Injector implements Service {

	public const PARAM = 'axle_smoke';

	/** Seconds a browser token lives. */
	public const LIFETIME = HOUR_IN_SECONDS;

	/**
	 * Hooks the page output.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_start' ), -1000 );
		add_action( 'login_init', array( $this, 'maybe_start' ), -1000 );
	}

	/**
	 * A new token for a run's browser tier.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $user_id User running it.
	 * @return string
	 */
	public static function issue( int $run_id, int $user_id ): string {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::key( $token ),
			array(
				'run'  => $run_id,
				'user' => $user_id,
			),
			self::LIFETIME
		);

		return $token;
	}

	/**
	 * The run a token belongs to.
	 *
	 * @param string $token Token.
	 * @return array{run: int, user: int}|null
	 */
	public static function check( string $token ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$data = get_transient( self::key( $token ) );

		return is_array( $data ) && isset( $data['run'] ) ? array(
			'run'  => (int) $data['run'],
			'user' => (int) ( $data['user'] ?? 0 ),
		) : null;
	}

	/**
	 * The transient for a token. Only a hash of the token is stored.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function key( string $token ): string {
		return 'axle_smoke_test_bt_' . substr( hash( 'sha256', $token ), 0, 32 );
	}

	/**
	 * Starts the output buffer on a token request.
	 */
	public function maybe_start(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token is the check; it only switches collection on.
		$token = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		if ( '' === $token || ! self::check( $token ) ) {
			return;
		}

		nocache_headers();
		add_filter( 'show_admin_bar', '__return_false' ); // phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected -- Only inside the smoke test's hidden frame, so the layout matches what visitors see.
		ob_start( array( self::class, 'inject' ) );
	}

	/**
	 * Puts the collector after <head>.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function inject( $html ): string {
		$html = (string) $html;
		if ( ! preg_match( '/<head\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$at = (int) $match[0][1] + strlen( $match[0][0] );

		return substr( $html, 0, $at ) . self::script() . substr( $html, $at );
	}

	/**
	 * The collector as an inline script.
	 *
	 * @return string
	 */
	public static function script(): string {
		$file = dirname( __DIR__, 2 ) . '/assets/collector.js';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- The plugin's own script, printed inline so it runs before anything else.
		$code = is_readable( $file ) ? (string) file_get_contents( $file ) : '';

		return "\n" . wp_get_inline_script_tag( $code, array( 'id' => 'axle-smoke-test-collector' ) ) . "\n";
	}
}
