<?php
/**
 * Red, amber or green, per URL and per run.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Compares a result with its baseline and decides the verdict.
 *
 * Red: an unexpected 4xx or 5xx, no response, a fatal error, core's critical
 * error page, or a blank page. Amber: new PHP warnings, new JavaScript errors,
 * new missing files or images, a landmark gone or moved past the threshold,
 * the visible text changed past the threshold, or an unexpected status below
 * 400. Green otherwise.
 */
final class Verdict {

	public const GREEN = 'green';
	public const AMBER = 'amber';
	public const RED   = 'red';

	public const ORDER = array(
		self::GREEN => 0,
		self::AMBER => 1,
		self::RED   => 2,
	);

	/**
	 * The thresholds in force, after the settings and filters.
	 *
	 * @param Settings $settings Settings.
	 * @return array{text: float, layout: int, blank: int}
	 */
	public static function thresholds( Settings $settings ): array {
		return array(
			/**
			 * Filters how much of the visible text may change before a URL turns amber, in percent.
			 *
			 * @param int $percent Default from the settings, 60.
			 */
			'text'   => Settings::filtered_int( 'text_change_threshold', (int) $settings->get( 'text_threshold' ), 1, 100 ) / 100,
			/**
			 * Filters how far a landmark may move or resize, in CSS pixels, before a URL turns amber.
			 *
			 * @param int $pixels Default from the settings, 40.
			 */
			'layout' => Settings::filtered_int( 'layout_threshold', (int) $settings->get( 'layout_threshold' ), 1, 5000 ),
			/**
			 * Filters the visible text size, in bytes, under which a page counts as blank.
			 *
			 * @param int $bytes Default from the settings, 40.
			 */
			'blank'  => Settings::filtered_int( 'blank_page_bytes', (int) $settings->get( 'blank_bytes' ), 0, 1000000 ),
		);
	}

	/**
	 * The verdict for one result.
	 *
	 * @param array      $result     The result.
	 * @param array|null $baseline   The baseline result for the same URL and tier, if any.
	 * @param array      $thresholds From thresholds().
	 * @return array{verdict: string, reasons: array[]}
	 */
	public static function evaluate( array $result, ?array $baseline, array $thresholds ): array {
		$reasons = 'server' === ( $result['tier'] ?? 'server' )
			? self::server_reasons( $result, $baseline, $thresholds )
			: self::browser_reasons( $result, $baseline, $thresholds );

		$verdict = self::GREEN;
		foreach ( $reasons as $reason ) {
			$verdict = self::worst( $verdict, $reason['level'] );
		}

		/**
		 * Filters the verdict for one URL in one tier, or for a whole run.
		 *
		 * @param string     $verdict  red, amber or green.
		 * @param array[]    $reasons  Each: level, code, message and, when known, source.
		 * @param array      $subject  The result (level "url") or the run (level "run").
		 * @param string     $level    url or run.
		 * @param array|null $baseline The baseline result, for level "url".
		 */
		$filtered = apply_filters( 'axle_smoke_test_verdict', $verdict, $reasons, $result, 'url', $baseline );

		return array(
			'verdict' => isset( self::ORDER[ $filtered ] ) ? $filtered : $verdict,
			'reasons' => $reasons,
		);
	}

	/**
	 * The run verdict: the worst of its results.
	 *
	 * @param array    $run      The run.
	 * @param string[] $verdicts Result verdicts.
	 * @return string
	 */
	public static function for_run( array $run, array $verdicts ): string {
		$verdict = self::GREEN;
		foreach ( $verdicts as $one ) {
			$verdict = self::worst( $verdict, (string) $one );
		}

		/** This filter is documented in src/Verdict.php */
		$filtered = apply_filters( 'axle_smoke_test_verdict', $verdict, array(), $run, 'run', null );

		return isset( self::ORDER[ $filtered ] ) ? $filtered : $verdict;
	}

	/**
	 * The worse of two verdicts.
	 *
	 * @param string $a Verdict.
	 * @param string $b Verdict.
	 * @return string
	 */
	public static function worst( string $a, string $b ): string {
		return ( self::ORDER[ $b ] ?? 0 ) > ( self::ORDER[ $a ] ?? 0 ) ? $b : $a;
	}

	/**
	 * Server tier checks.
	 *
	 * @param array      $result     Result.
	 * @param array|null $baseline   Baseline.
	 * @param array      $thresholds Thresholds.
	 * @return array[]
	 */
	private static function server_reasons( array $result, ?array $baseline, array $thresholds ): array {
		$reasons  = array();
		$meta     = (array) ( $result['meta'] ?? array() );
		$status   = (int) ( $result['http_status'] ?? 0 );
		$expected = array_map( 'intval', (array) ( $meta['expected'] ?? array( 200 ) ) );

		if ( ! empty( $meta['error'] ) ) {
			$reasons[] = self::reason(
				self::RED,
				'no_response',
				/* translators: %s: error message. */
				sprintf( __( 'No response: %s', 'axle-smoke-test' ), (string) $meta['error'] )
			);
			return $reasons;
		}

		if ( ! empty( $meta['fatal'] ) && is_array( $meta['fatal'] ) ) {
			$fatal     = $meta['fatal'];
			$reasons[] = self::reason(
				self::RED,
				'fatal',
				/* translators: 1: error message, 2: file, 3: line. */
				sprintf( __( 'Fatal error: %1$s in %2$s on line %3$d', 'axle-smoke-test' ), (string) ( $fatal['message'] ?? '' ), str_replace( ABSPATH, '', (string) ( $fatal['file'] ?? '' ) ), (int) ( $fatal['line'] ?? 0 ) ),
				(string) ( $fatal['source'] ?? '' )
			);
		}

		if ( ! empty( $meta['critical_error'] ) ) {
			$reasons[] = self::reason( self::RED, 'critical_error', __( 'The page shows "There has been a critical error on this website".', 'axle-smoke-test' ) );
		}

		if ( ! in_array( $status, $expected, true ) ) {
			$wanted = implode( ', ', $expected );
			if ( $status >= 400 ) {
				$reasons[] = self::reason(
					self::RED,
					'status',
					/* translators: 1: HTTP status received, 2: expected statuses. */
					sprintf( __( 'HTTP %1$d, expected %2$s.', 'axle-smoke-test' ), $status, $wanted )
				);
			} else {
				$reasons[] = self::reason(
					self::AMBER,
					'status',
					/* translators: 1: HTTP status received, 2: expected statuses. */
					sprintf( __( 'HTTP %1$d, expected %2$s.', 'axle-smoke-test' ), $status, $wanted )
				);
			}
		}

		$html       = 'html' === ( $meta['kind'] ?? 'html' );
		$text_bytes = (int) ( $meta['text_bytes'] ?? 0 );
		$base_meta  = $baseline ? (array) ( $baseline['meta'] ?? array() ) : array();
		if ( $html && $status >= 200 && $status < 300 && $text_bytes < $thresholds['blank'] && ( ! $baseline || (int) ( $base_meta['text_bytes'] ?? 0 ) >= $thresholds['blank'] ) ) {
			$reasons[] = self::reason(
				self::RED,
				'blank',
				/* translators: %d: number of bytes. */
				sprintf( __( 'Blank page: only %d bytes of visible text.', 'axle-smoke-test' ), $text_bytes )
			);
		}

		if ( ! empty( $meta['invalid'] ) ) {
			$reasons[] = self::reason( self::RED, 'invalid', (string) $meta['invalid'] );
		}

		foreach ( self::new_items( (array) ( $result['php_errors'] ?? array() ), $baseline ? (array) ( $baseline['php_errors'] ?? array() ) : array(), array( self::class, 'php_signature' ) ) as $error ) {
			$reasons[] = self::reason(
				self::AMBER,
				'php_error',
				/* translators: 1: PHP error type, 2: message. */
				sprintf( __( 'New PHP %1$s: %2$s', 'axle-smoke-test' ), (string) ( $error['type'] ?? '' ), (string) ( $error['message'] ?? '' ) ),
				(string) ( $error['source'] ?? '' )
			);
		}

		$reasons = array_merge( $reasons, self::missing_reasons( $result, $baseline ) );

		$broken = (bool) array_filter(
			$reasons,
			static function ( $reason ) {
				return self::RED === $reason['level'];
			}
		);
		// A broken page loses its landmarks and text anyway: the red reason says it all.
		if ( $baseline && $html && ! $broken ) {
			$landmarks = (array) ( $result['landmarks'] ?? array() );
			foreach ( (array) ( $baseline['landmarks'] ?? array() ) as $landmark => $count ) {
				$now = (int) ( $landmarks[ $landmark ] ?? 0 );
				if ( (int) $count > 0 && 0 === $now ) {
					$reasons[] = self::reason(
						self::AMBER,
						'landmark',
						/* translators: %s: landmark, like main or footer. */
						sprintf( __( 'The page has no <%s> any more.', 'axle-smoke-test' ), (string) $landmark )
					);
				}
			}

			$before = (array) ( $baseline['text_fingerprint'] ?? array() );
			$after  = (array) ( $result['text_fingerprint'] ?? array() );
			if ( $before && $after ) {
				$changed = 1 - Analyzer::similarity( $after, $before );
				if ( $changed > $thresholds['text'] ) {
					$reasons[] = self::reason(
						self::AMBER,
						'text',
						/* translators: %d: percentage. */
						sprintf( __( 'About %d%% of the visible text changed.', 'axle-smoke-test' ), (int) round( $changed * 100 ) )
					);
				}
			}
		}

		return $reasons;
	}

	/**
	 * Browser and worker tier checks.
	 *
	 * @param array      $result     Result.
	 * @param array|null $baseline   Baseline.
	 * @param array      $thresholds Thresholds.
	 * @return array[]
	 */
	private static function browser_reasons( array $result, ?array $baseline, array $thresholds ): array {
		$reasons = array();
		$meta    = (array) ( $result['meta'] ?? array() );

		if ( ! empty( $meta['error'] ) ) {
			$reasons[] = self::reason(
				self::AMBER,
				'browser_error',
				/* translators: %s: what went wrong. */
				sprintf( __( 'The browser could not check this page: %s', 'axle-smoke-test' ), (string) $meta['error'] )
			);
		}

		foreach ( self::new_items( (array) ( $result['js_errors'] ?? array() ), $baseline ? (array) ( $baseline['js_errors'] ?? array() ) : array(), array( self::class, 'js_signature' ) ) as $error ) {
			$reasons[] = self::reason(
				self::AMBER,
				'js_error',
				/* translators: %s: JavaScript error message. */
				sprintf( __( 'New JavaScript error: %s', 'axle-smoke-test' ), (string) ( $error['message'] ?? '' ) ),
				(string) ( $error['source'] ?? '' )
			);
		}

		$reasons = array_merge( $reasons, self::missing_reasons( $result, $baseline ) );

		if ( $baseline ) {
			foreach ( self::layout_changes( (array) ( $baseline['layout'] ?? array() ), (array) ( $result['layout'] ?? array() ), (int) $thresholds['layout'] ) as $change ) {
				$reasons[] = self::reason(
					self::AMBER,
					'layout',
					'missing' === $change['change']
						/* translators: %s: landmark selector, like main or footer. */
						? sprintf( __( 'Landmark %s is gone or hidden.', 'axle-smoke-test' ), $change['key'] )
						/* translators: 1: landmark selector, 2: pixels. */
						: sprintf( __( 'Landmark %1$s moved or resized by %2$dpx.', 'axle-smoke-test' ), $change['key'], $change['delta'] )
				);
			}
		}

		return $reasons;
	}

	/**
	 * Reasons for missing files and broken images that the baseline did not have.
	 *
	 * @param array      $result   Result.
	 * @param array|null $baseline Baseline.
	 * @return array[]
	 */
	private static function missing_reasons( array $result, ?array $baseline ): array {
		$reasons = array();
		$before  = $baseline ? (array) ( $baseline['missing_assets'] ?? array() ) : array();
		foreach ( self::new_items( (array) ( $result['missing_assets'] ?? array() ), $before, array( self::class, 'asset_signature' ) ) as $asset ) {
			$reasons[] = self::reason(
				self::AMBER,
				'missing_asset',
				/* translators: 1: file URL, 2: HTTP status. */
				sprintf( __( 'Missing file: %1$s (HTTP %2$d)', 'axle-smoke-test' ), (string) ( $asset['url'] ?? '' ), (int) ( $asset['status'] ?? 0 ) ),
				(string) ( $asset['source'] ?? '' )
			);
		}

		return $reasons;
	}

	/**
	 * Landmarks that went missing or moved past the threshold.
	 *
	 * @param array $before    Baseline layout: key => x, y, w, h, visible.
	 * @param array $after     Current layout.
	 * @param int   $threshold Pixels.
	 * @return array[] Each: key, change (missing or moved), delta.
	 */
	public static function layout_changes( array $before, array $after, int $threshold ): array {
		$changes = array();
		foreach ( $before as $key => $box ) {
			$box = (array) $box;
			if ( empty( $box['visible'] ) ) {
				continue;
			}
			$now = isset( $after[ $key ] ) ? (array) $after[ $key ] : null;
			if ( ! $now || empty( $now['visible'] ) ) {
				$changes[] = array(
					'key'    => (string) $key,
					'change' => 'missing',
					'delta'  => 0,
				);
				continue;
			}
			$delta = 0;
			foreach ( array( 'x', 'y', 'w', 'h' ) as $axis ) {
				$delta = max( $delta, (int) round( abs( (float) ( $now[ $axis ] ?? 0 ) - (float) ( $box[ $axis ] ?? 0 ) ) ) );
			}
			if ( $delta > $threshold ) {
				$changes[] = array(
					'key'    => (string) $key,
					'change' => 'moved',
					'delta'  => $delta,
				);
			}
		}

		return $changes;
	}

	/**
	 * Items whose signature is not in the baseline, ignoring the filtered-out ones.
	 *
	 * @param array    $items     Current items.
	 * @param array    $before    Baseline items.
	 * @param callable $signature Signature function.
	 * @return array[]
	 */
	public static function new_items( array $items, array $before, callable $signature ): array {
		$known = array();
		foreach ( $before as $item ) {
			$known[ $signature( (array) $item ) ] = true;
		}

		$new = array();
		foreach ( $items as $item ) {
			$item = (array) $item;
			$key  = $signature( $item );
			if ( ! isset( $known[ $key ] ) ) {
				$known[ $key ] = true;
				$new[]         = $item;
			}
		}

		return $new;
	}

	/**
	 * A PHP error's identity across runs: type, source and message, with numbers left out.
	 *
	 * @param array $error Error.
	 * @return string
	 */
	public static function php_signature( array $error ): string {
		return strtolower( (string) ( $error['type'] ?? '' ) ) . '|' . (string) ( $error['source'] ?? '' ) . '|' . self::fuzzy( (string) ( $error['message'] ?? '' ) );
	}

	/**
	 * A JavaScript error's identity across runs.
	 *
	 * @param array $error Error.
	 * @return string
	 */
	public static function js_signature( array $error ): string {
		return (string) ( $error['source'] ?? '' ) . '|' . self::fuzzy( (string) ( $error['message'] ?? '' ) );
	}

	/**
	 * A missing file's identity across runs: its URL without the query string.
	 *
	 * @param array $asset Asset.
	 * @return string
	 */
	public static function asset_signature( array $asset ): string {
		return (string) preg_replace( '/[?#].*$/', '', (string) ( $asset['url'] ?? '' ) );
	}

	/**
	 * A message with numbers and quoted ids collapsed, so line numbers and
	 * object ids do not make a known error look new.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function fuzzy( string $message ): string {
		return trim( (string) preg_replace( '/\d+/', '#', strtolower( $message ) ) );
	}

	/**
	 * One reason.
	 *
	 * @param string $level   red or amber.
	 * @param string $code    Machine-readable code.
	 * @param string $message What happened.
	 * @param string $source  plugin:<folder>, theme:<stylesheet>, core, or empty.
	 * @return array
	 */
	private static function reason( string $level, string $code, string $message, string $source = '' ): array {
		return array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
			'source'  => $source,
		);
	}
}
