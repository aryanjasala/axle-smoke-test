<?php
/**
 * The server tier: one loopback request per key URL.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Loads a key URL from the server itself and turns the response into a
 * result: status, redirect, time, size, fatal errors (through core's scrape
 * mechanism, as the auto-update rollback does), PHP warnings (through the
 * request's error collector and debug.log), the HTML signals and missing
 * same-origin scripts and stylesheets.
 */
class Checker {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Error attribution.
	 *
	 * @var Attribution
	 */
	private Attribution $attribution;

	/**
	 * Asset statuses already checked in this run, by URL.
	 *
	 * @var array<string, int>
	 */
	private array $asset_cache = array();

	/**
	 * Sets up the checker.
	 *
	 * @param Settings    $settings    Settings.
	 * @param Attribution $attribution Error attribution.
	 */
	public function __construct( Settings $settings, Attribution $attribution ) {
		$this->settings    = $settings;
		$this->attribution = $attribution;
	}

	/**
	 * Forgets the assets checked for the previous run.
	 */
	public function reset(): void {
		$this->asset_cache = array();
	}

	/**
	 * Checks one URL.
	 *
	 * @param array $url     URL entry from Urls.
	 * @param int   $user_id User the wp-admin URLs are opened as, 0 for none.
	 * @return array Result, without run_id and verdict.
	 */
	public function check( array $url, int $user_id ): array {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( ErrorCollector::token_key( $token ), 1, 2 * MINUTE_IN_SECONDS );

		$scrape_key   = md5( (string) wp_rand() );
		$scrape_nonce = (string) wp_rand();
		set_transient( 'scrape_key_' . $scrape_key, $scrape_nonce, MINUTE_IN_SECONDS );

		$query = array(
			'wp_scrape_key'   => $scrape_key,
			'wp_scrape_nonce' => $scrape_nonce,
		);
		/**
		 * Filters whether loopback requests carry a cache-busting query argument
		 * and a Cache-Control: no-cache header, so a full-page cache does not
		 * answer with an old copy.
		 *
		 * @param bool  $bust Default true.
		 * @param array $url  The URL entry.
		 */
		$bust = (bool) apply_filters( 'axle_smoke_test_cache_bust', true, $url );
		if ( $bust ) {
			$query['axle_smoke_nocache'] = (string) time() . wp_rand( 100, 999 );
		}
		$request_url = add_query_arg( $query, $url['url'] );

		$expected = $url['expected'];
		$session  = null;
		$mode     = 'public';
		if ( $url['auth'] ) {
			$session = AdminSession::create( $user_id, $url['url'] );
			if ( ! $session ) {
				// Without a login, a wp-admin screen should send the visitor to the login page.
				// It gets its own key, so it is never compared with the signed-in screen.
				$mode       = 'login-page';
				$expected   = array( 302 );
				$url['key'] = substr( $url['key'], 0, 52 ) . '-signed-out';
			} else {
				$mode = 'signed-in';
			}
		}

		$args = array(
			'timeout'     => 20, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- A loopback to a page that may be slow right after an update; runs in the background.
			'redirection' => 0,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, as its loopback checks use it.
			'user-agent'  => 'Axle Smoke Test/' . VERSION . '; ' . home_url( '/' ),
			'headers'     => array(
				ErrorCollector::HEADER => $token,
			),
			'cookies'     => $session ? $session['cookies'] : array(),
		);
		if ( $bust ) {
			$args['headers']['Cache-Control'] = 'no-cache';
			$args['headers']['Pragma']        = 'no-cache';
		}
		$auth_user = (string) $this->settings->get( 'auth_user' );
		if ( '' !== $auth_user ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth header.
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( $auth_user . ':' . $this->settings->secret( 'auth_pass' ) );
		}

		/**
		 * Filters the arguments of each loopback request.
		 *
		 * @param array $args wp_remote_get() arguments.
		 * @param array $url  The URL entry.
		 */
		$args = (array) apply_filters( 'axle_smoke_test_request_args', $args, $url );

		$offset = DebugLog::offset();
		$start  = microtime( true );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- A loopback to this site, which is the whole check; never cached.
		$response = wp_remote_get( $request_url, $args );
		$elapsed  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( $session ) {
			AdminSession::destroy( $user_id, $session['token'] );
		}
		delete_transient( 'scrape_key_' . $scrape_key );
		delete_transient( ErrorCollector::token_key( $token ) );
		$collected = get_transient( ErrorCollector::errors_key( $token ) );
		delete_transient( ErrorCollector::errors_key( $token ) );

		$result = array(
			'url_key'          => $url['key'],
			'url'              => $url['url'],
			'tier'             => 'server',
			'http_status'      => 0,
			'ttfb_ms'          => $elapsed,
			'body_bytes'       => 0,
			'php_errors'       => array(),
			'js_errors'        => array(),
			'missing_assets'   => array(),
			'html_hash'        => '',
			'text_fingerprint' => array(),
			'landmarks'        => array(),
			'layout'           => array(),
			'meta'             => array(
				'label'     => $url['label'],
				'expected'  => $expected,
				'kind'      => $url['kind'],
				'auth_mode' => $mode,
			),
		);

		if ( is_wp_error( $response ) ) {
			$result['meta']['error'] = $response->get_error_message();
			return $result;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		$result['http_status']          = $status;
		$result['body_bytes']           = strlen( $body );
		$result['meta']['redirect']     = (string) wp_remote_retrieve_header( $response, 'location' );
		$result['meta']['content_type'] = (string) wp_remote_retrieve_header( $response, 'content-type' );

		$fatal = self::scrape_result( $body, $scrape_key );
		if ( $fatal ) {
			$fatal['source']         = $this->attribution->source( (string) ( $fatal['file'] ?? '' ), $fatal );
			$result['meta']['fatal'] = $fatal;
		}

		// When the request's own collector ran, its list is exact. debug.log is shared with every
		// other request, so it is only read when the collector could not run (a fatal error before
		// this plugin loaded, for example).
		$errors               = is_array( $collected ) ? $collected : DebugLog::since( $offset );
		$result['php_errors'] = $this->php_errors( $errors, $url, ! empty( $result['meta']['fatal'] ) );

		if ( 'login-page' === $mode && 302 === $status && false === strpos( $result['meta']['redirect'], 'wp-login.php' ) ) {
			$result['meta']['invalid'] = __( 'The wp-admin screen did not send a signed-out visitor to the login page.', 'axle-smoke-test' );
		}

		$body = Analyzer::strip_scrape_marker( $body );
		if ( 'html' === $url['kind'] ) {
			if ( ( $status >= 200 && $status < 300 ) || $status >= 400 ) {
				$signals                          = Analyzer::analyse( $body, $url['url'] );
				$result['html_hash']              = $signals['html_hash'];
				$result['text_fingerprint']       = $signals['fingerprint'];
				$result['landmarks']              = $signals['landmarks'];
				$result['meta']['text_bytes']     = $signals['text_bytes'];
				$result['meta']['critical_error'] = $signals['critical_error'];
				$result['meta']['title']          = $signals['title'];
				if ( $status < 400 ) {
					$result['missing_assets'] = $this->missing_assets( $signals['assets'], $args );
				}
			}
		} elseif ( $status >= 200 && $status < 300 ) {
			$result['html_hash'] = hash( 'sha256', $body );
			$invalid             = self::invalid_body( $url['kind'], $body );
			if ( '' !== $invalid ) {
				$result['meta']['invalid'] = $invalid;
			}
		}

		return $result;
	}

	/**
	 * Loads the home page once to see whether loopback requests work at all.
	 *
	 * @return true|\WP_Error
	 */
	public function loopback_works() {
		$args      = array(
			'timeout'     => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- One loopback to the home page before a run.
			'redirection' => 3,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter.
		);
		$auth_user = (string) $this->settings->get( 'auth_user' );
		if ( '' !== $auth_user ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth header.
			$args['headers'] = array( 'Authorization' => 'Basic ' . base64_encode( $auth_user . ':' . $this->settings->secret( 'auth_pass' ) ) );
		}
		/** This filter is documented in src/Checker.php */
		$filtered = apply_filters( 'axle_smoke_test_request_args', $args, array( 'key' => 'loopback' ) );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- A loopback to this site.
		$response = wp_remote_get( home_url( '/' ), is_array( $filtered ) ? $filtered : $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'axle_smoke_test_basic_auth', __( 'The site asks for a username and password (HTTP 401). Add them under Settings > Basic auth.', 'axle-smoke-test' ) );
		}

		return true;
	}

	/**
	 * Core's scrape result: the fatal error, if the request had one.
	 *
	 * @param string $body Body.
	 * @param string $key  Scrape key.
	 * @return array|null type, message, file, line.
	 */
	public static function scrape_result( string $body, string $key ): ?array {
		$start = "###### wp_scraping_result_start:{$key} ######";
		$end   = "###### wp_scraping_result_end:{$key} ######";
		$at    = strpos( $body, $start );
		if ( false === $at ) {
			return null;
		}

		$output = substr( $body, $at + strlen( $start ) );
		$stop   = strpos( $output, $end );
		$output = false === $stop ? $output : substr( $output, 0, $stop );
		$result = json_decode( trim( $output ), true );

		// Only fatal errors carry a type, as in WP_Automatic_Updater::has_fatal_error().
		if ( ! is_array( $result ) || ! isset( $result['type'] ) ) {
			return null;
		}

		$file = (string) ( $result['file'] ?? '' );
		if ( '' !== $file && 0 !== strpos( $file, '/' ) && ! preg_match( '#^[a-z]:#i', $file ) ) {
			// Core strips ABSPATH from the path.
			$file = ABSPATH . $file;
		}

		return array(
			'type'    => ErrorCollector::type( (int) $result['type'] ),
			'message' => self::clean_message( (string) ( $result['message'] ?? '' ) ),
			'file'    => $file,
			'line'    => (int) ( $result['line'] ?? 0 ),
		);
	}

	/**
	 * An error message without its stack trace and trailing location.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function clean_message( string $message ): string {
		$message = (string) preg_replace( '/\s*Stack trace:.*$/s', '', $message );
		$message = (string) preg_replace( '/\s+in\s+\S+?(?::\d+|\s+on\s+line\s+\d+)\s*$/', '', trim( $message ) );

		return substr( trim( $message ), 0, 500 );
	}

	/**
	 * Why a feed or JSON response is broken, or empty when it looks fine.
	 *
	 * @param string $kind feed or json.
	 * @param string $body Body.
	 * @return string
	 */
	public static function invalid_body( string $kind, string $body ): string {
		if ( 'json' === $kind && null === json_decode( $body ) ) {
			return __( 'The response is not valid JSON.', 'axle-smoke-test' );
		}
		if ( 'feed' === $kind && false === stripos( $body, '<rss' ) && false === stripos( $body, '<feed' ) ) {
			return __( 'The response is not an RSS or Atom feed.', 'axle-smoke-test' );
		}

		return '';
	}

	/**
	 * PHP errors with their source, without the ignored ones.
	 *
	 * @param array[] $errors     Raw errors.
	 * @param array   $url        URL entry.
	 * @param bool    $skip_fatal Leave fatal errors out (the result has one already).
	 * @return array[]
	 */
	private function php_errors( array $errors, array $url, bool $skip_fatal ): array {
		/**
		 * Filters patterns of PHP errors that never count, matched against the
		 * message. Plain text matches anywhere; text between slashes is a regex.
		 *
		 * @param string[] $patterns Default: none.
		 * @param array    $url      The URL entry.
		 */
		$ignored = (array) apply_filters( 'axle_smoke_test_ignored_php_errors', array(), $url );

		$out  = array();
		$seen = array();
		foreach ( $errors as $error ) {
			$message = self::clean_message( (string) ( $error['message'] ?? '' ) );
			// A fatal error is reported once, as the run's fatal.
			if ( $skip_fatal && 'fatal error' === ( $error['type'] ?? '' ) ) {
				continue;
			}
			if ( self::matches_any( $message, $ignored ) ) {
				continue;
			}
			$file = (string) ( $error['file'] ?? '' );
			$key  = ( $error['type'] ?? '' ) . '|' . $file . '|' . md5( $message );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'type'    => (string) ( $error['type'] ?? 'error' ),
				'message' => $message,
				'file'    => $file,
				'line'    => (int) ( $error['line'] ?? 0 ),
				'source'  => $this->attribution->source( $file, $error ),
			);
		}

		return $out;
	}

	/**
	 * Whether a message matches one of the patterns.
	 *
	 * @param string $message  Message.
	 * @param array  $patterns Plain text or /regex/.
	 * @return bool
	 */
	public static function matches_any( string $message, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' === $pattern ) {
				continue;
			}
			if ( 1 === preg_match( '#^/.+/[imsux]*$#s', $pattern ) ) {
				if ( @preg_match( $pattern, $message ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A pattern from a filter may be invalid.
					return true;
				}
			} elseif ( false !== stripos( $message, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Same-origin scripts and stylesheets that answer with an error.
	 *
	 * @param string[] $assets URLs.
	 * @param array    $args   Request arguments (for basic auth and SSL).
	 * @return array[] Each: url, status, source.
	 */
	private function missing_assets( array $assets, array $args ): array {
		$missing = array();
		$head    = array(
			'timeout'     => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- A HEAD request to one of the site's own files.
			'redirection' => 2,
			'sslverify'   => $args['sslverify'] ?? false,
			'headers'     => isset( $args['headers']['Authorization'] ) ? array( 'Authorization' => $args['headers']['Authorization'] ) : array(),
		);
		foreach ( $assets as $asset ) {
			if ( ! isset( $this->asset_cache[ $asset ] ) ) {
				if ( count( $this->asset_cache ) >= 150 ) {
					break;
				}
				$response                    = wp_remote_head( $asset, $head );
				$this->asset_cache[ $asset ] = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			}
			$status = $this->asset_cache[ $asset ];
			if ( $status >= 400 ) {
				$missing[] = array(
					'url'    => $asset,
					'status' => $status,
					'source' => $this->attribution->source( $asset ),
				);
			}
		}

		return $missing;
	}
}
