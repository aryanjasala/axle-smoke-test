<?php
/**
 * Collects PHP warnings and notices during a smoke test request.
 *
 * This file has no dependencies, so the optional early must-use plugin can
 * load it before other plugins.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * On a loopback request that carries a valid run token in the
 * X-Axle-Smoke-Test header, records every PHP error the request raises and
 * saves them for the checker at shutdown. Normal error handling still runs.
 * Requests without the header are not touched.
 */
final class ErrorCollector {

	public const HEADER = 'X-Axle-Smoke-Test';

	/** Most errors kept per request. */
	private const MAX = 100;

	/**
	 * Whether collection runs in this request.
	 *
	 * @var bool
	 */
	private static bool $started = false;

	/**
	 * The request token.
	 *
	 * @var string
	 */
	private static string $token = '';

	/**
	 * Errors so far, keyed by type, file, line and message.
	 *
	 * @var array<string, array>
	 */
	private static array $errors = array();

	/**
	 * The handler that was there before ours.
	 *
	 * @var callable|null
	 */
	private static $previous = null;

	/**
	 * Starts collecting if this request carries a valid token.
	 *
	 * @return bool Whether it started (now or earlier).
	 */
	public static function maybe_start(): bool {
		if ( self::$started ) {
			return true;
		}

		$token = self::request_token();
		if ( '' === $token || ! function_exists( 'get_transient' ) || false === get_transient( self::token_key( $token ) ) ) {
			return false;
		}

		self::$started = true;
		self::$token   = $token;
		self::$errors  = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Only on the plugin's own loopback requests, and the previous handler still runs.
		self::$previous = set_error_handler( array( self::class, 'handle' ) );
		register_shutdown_function( array( self::class, 'shutdown' ) );

		return true;
	}

	/**
	 * The token from the request header, if it looks like one.
	 *
	 * @return string
	 */
	public static function request_token(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- Checked against a stored hash below.
		$raw = isset( $_SERVER['HTTP_X_AXLE_SMOKE_TEST'] ) && function_exists( 'wp_unslash' ) ? (string) wp_unslash( $_SERVER['HTTP_X_AXLE_SMOKE_TEST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only [a-f0-9] is kept.

		return 1 === preg_match( '/^[a-f0-9]{32}$/', $raw ) ? $raw : '';
	}

	/**
	 * The transient that marks a token valid. Only a hash of the token is stored.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function token_key( string $token ): string {
		return 'axle_smoke_test_rt_' . substr( hash( 'sha256', $token ), 0, 32 );
	}

	/**
	 * The transient the errors of a token's request are saved in.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function errors_key( string $token ): string {
		return 'axle_smoke_test_re_' . substr( hash( 'sha256', $token ), 0, 32 );
	}

	/**
	 * Records one error and hands it on.
	 *
	 * @param int    $errno   Level.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 * @return bool
	 */
	public static function handle( $errno, $errstr, $errfile = '', $errline = 0 ): bool {
		// Errors silenced with @ are left alone.
		if ( error_reporting() & (int) $errno ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting, PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall -- Reads the level, changes nothing.
			self::record( (int) $errno, (string) $errstr, (string) $errfile, (int) $errline );
		}

		if ( is_callable( self::$previous ) ) {
			return (bool) call_user_func( self::$previous, $errno, $errstr, $errfile, $errline );
		}

		return false;
	}

	/**
	 * Adds an error to the list.
	 *
	 * @param int    $errno   Level.
	 * @param string $message Message.
	 * @param string $file    File.
	 * @param int    $line    Line.
	 */
	public static function record( int $errno, string $message, string $file, int $line ): void {
		$key = $errno . '|' . $file . '|' . $line . '|' . md5( $message );
		if ( isset( self::$errors[ $key ] ) || count( self::$errors ) >= self::MAX ) {
			return;
		}

		self::$errors[ $key ] = array(
			'type'    => self::type( $errno ),
			'message' => substr( $message, 0, 500 ),
			'file'    => $file,
			'line'    => $line,
		);
	}

	/**
	 * Saves what was collected, fatal errors included.
	 */
	public static function shutdown(): void {
		$last = error_get_last();
		if ( is_array( $last ) && in_array( $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			self::record( (int) $last['type'], (string) $last['message'], (string) $last['file'], (int) $last['line'] );
		}

		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::errors_key( self::$token ), array_values( self::$errors ), 600 );
		}
	}

	/**
	 * Errors collected so far in this request.
	 *
	 * @return array[]
	 */
	public static function collected(): array {
		return array_values( self::$errors );
	}

	/**
	 * Whether collection runs in this request.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return self::$started;
	}

	/**
	 * A readable name for an error level.
	 *
	 * @param int $errno Level.
	 * @return string
	 */
	public static function type( int $errno ): string {
		$types = array(
			E_ERROR             => 'fatal error',
			E_WARNING           => 'warning',
			E_PARSE             => 'parse error',
			E_NOTICE            => 'notice',
			E_CORE_ERROR        => 'fatal error',
			E_CORE_WARNING      => 'warning',
			E_COMPILE_ERROR     => 'fatal error',
			E_COMPILE_WARNING   => 'warning',
			E_USER_ERROR        => 'fatal error',
			E_USER_WARNING      => 'warning',
			E_USER_NOTICE       => 'notice',
			E_RECOVERABLE_ERROR => 'fatal error',
			E_DEPRECATED        => 'deprecated',
			E_USER_DEPRECATED   => 'deprecated',
		);

		return $types[ $errno ] ?? 'error';
	}
}
