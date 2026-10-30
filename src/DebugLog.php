<?php
/**
 * Reads new lines from debug.log.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * When WP_DEBUG_LOG is on, remembers how long debug.log was before a request
 * and reads the PHP errors written after it.
 */
final class DebugLog {

	/** Most bytes read after one request. */
	private const MAX_BYTES = 262144;

	/**
	 * The log file path, or empty when logging is off or the file is missing.
	 *
	 * @return string
	 */
	public static function path(): string {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return '';
		}

		// wp_debug_mode() points PHP's error_log at the file WP_DEBUG_LOG names.
		$path = (string) ini_get( 'error_log' );

		return is_readable( $path ) ? $path : '';
	}

	/**
	 * The current end of the log.
	 *
	 * @return int Bytes.
	 */
	public static function offset(): int {
		$path = self::path();
		if ( '' === $path ) {
			return 0;
		}
		clearstatcache( true, $path );

		return (int) filesize( $path );
	}

	/**
	 * PHP errors written since an offset.
	 *
	 * @param int $offset From offset().
	 * @return array[] Each: type, message, file, line.
	 */
	public static function since( int $offset ): array {
		$path = self::path();
		if ( '' === $path ) {
			return array();
		}
		clearstatcache( true, $path );
		$size = (int) filesize( $path );
		if ( $size <= $offset ) {
			return array();
		}

		$length = min( self::MAX_BYTES, $size - $offset );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local log file, read from an offset.
		$text = file_get_contents( $path, false, null, $offset, $length );

		return self::parse( is_string( $text ) ? $text : '' );
	}

	/**
	 * PHP errors in log text.
	 *
	 * @param string $text Log lines.
	 * @return array[]
	 */
	public static function parse( string $text ): array {
		$errors = array();
		$types  = array(
			'fatal error'       => 'fatal error',
			'parse error'       => 'parse error',
			'warning'           => 'warning',
			'notice'            => 'notice',
			'deprecated'        => 'deprecated',
			'recoverable fatal' => 'fatal error',
		);
		if ( ! preg_match_all( '/^\[[^\]]+\]\s+PHP\s+([A-Za-z ]+?):\s+(.+?)\s+in\s+(\S+?\.php)(?:\s+on\s+line\s+|:)(\d+)/m', $text, $matches, PREG_SET_ORDER ) ) {
			return $errors;
		}

		foreach ( $matches as $match ) {
			$type = strtolower( trim( $match[1] ) );
			$type = $types[ $type ] ?? $type;
			$key  = $type . '|' . $match[3] . '|' . $match[4] . '|' . md5( $match[2] );
			if ( isset( $errors[ $key ] ) ) {
				continue;
			}
			$errors[ $key ] = array(
				'type'    => $type,
				'message' => substr( $match[2], 0, 500 ),
				'file'    => $match[3],
				'line'    => (int) $match[4],
			);
		}

		return array_values( $errors );
	}
}
