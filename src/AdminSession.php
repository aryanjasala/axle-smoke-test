<?php
/**
 * Short-lived login for the wp-admin checks.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a loopback request open one wp-admin screen as a given user.
 *
 * It creates a WordPress session for the user that expires in 60 seconds
 * (core stores only a hash of its token) and sends its login cookies with the
 * loopback. The session is marked with the one URL path it may open: any
 * other path, any method but GET or HEAD, admin-post.php and the REST API are
 * refused, and the session is destroyed after its first request.
 */
final class AdminSession implements Service {

	private const MARKER = 'axle_smoke_test';

	/** Seconds the session lives. */
	public const LIFETIME = 60;

	/**
	 * Set when a marked session was used against its rules in this request.
	 *
	 * @var bool
	 */
	private bool $refused = false;

	/**
	 * The marked session used in this request.
	 *
	 * @var array{user: int, token: string}|null
	 */
	private ?array $used = null;

	/**
	 * Hooks into cookie authentication.
	 */
	public function register(): void {
		add_action( 'auth_cookie_valid', array( $this, 'check' ), 10, 2 );
		add_filter( 'determine_current_user', array( $this, 'refuse_user' ), 100 );
		add_action( 'auth_redirect', array( $this, 'refuse_screen' ) );
		add_action( 'shutdown', array( $this, 'destroy_used' ) );
	}

	/**
	 * Whether the wp-admin checks may log in at all.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether wp-admin URLs are checked as a signed-in user. When
		 * false, they are only checked as "the login page loads".
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'axle_smoke_test_admin_loopback_enabled', true );
	}

	/**
	 * Cookies that sign a loopback request in as a user, for one URL.
	 *
	 * @param int    $user_id User ID.
	 * @param string $url     The one URL the session may open.
	 * @return array{cookies: array<string, string>, token: string}|null Null when the checks may not log in.
	 */
	public static function create( int $user_id, string $url ): ?array {
		if ( $user_id <= 0 || ! self::enabled() || ! user_can( $user_id, Settings::capability() ) ) {
			return null;
		}

		$path       = self::path( $url );
		$expiration = time() + self::LIFETIME;
		$attach     = static function ( $session ) use ( $path ) {
			$session                 = (array) $session;
			$session[ self::MARKER ] = array( 'path' => $path );
			return $session;
		};

		add_filter( 'attach_session_information', $attach );
		$token = \WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
		remove_filter( 'attach_session_information', $attach );

		$secure = is_ssl();
		$auth   = $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE;

		return array(
			'cookies' => array(
				$auth            => wp_generate_auth_cookie( $user_id, $expiration, $secure ? 'secure_auth' : 'auth', $token ),
				LOGGED_IN_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
			),
			'token'   => $token,
		);
	}

	/**
	 * Ends a session from create().
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Session token.
	 */
	public static function destroy( int $user_id, string $token ): void {
		if ( $user_id > 0 && '' !== $token ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy( $token );
		}
	}

	/**
	 * Removes every smoke test session of a user that is still stored, for
	 * example when a loopback request and the run wrote the user's sessions
	 * at the same moment. Only for core's own session storage.
	 *
	 * @param int $user_id User ID.
	 * @return int Sessions removed.
	 */
	public static function sweep( int $user_id ): int {
		/** This filter is documented in wp-includes/class-wp-session-tokens.php */
		$manager = apply_filters( 'session_token_manager', 'WP_User_Meta_Session_Tokens' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, read only.
		if ( $user_id <= 0 || 'WP_User_Meta_Session_Tokens' !== $manager ) {
			return 0;
		}

		wp_cache_delete( $user_id, 'user_meta' );
		$sessions = get_user_meta( $user_id, 'session_tokens', true );
		if ( ! is_array( $sessions ) ) {
			return 0;
		}
		$kept    = array_filter(
			$sessions,
			static function ( $session ) {
				return ! is_array( $session ) || empty( $session[ self::MARKER ] );
			}
		);
		$removed = count( $sessions ) - count( $kept );
		if ( $removed ) {
			update_user_meta( $user_id, 'session_tokens', $kept );
		}

		return $removed;
	}

	/**
	 * Checks a valid login cookie: a marked session only opens its own URL, with GET.
	 *
	 * @param array $cookie Cookie parts: username, expiration, token, hmac, scheme.
	 * @param mixed $user   The user (a WP_User).
	 */
	public function check( $cookie, $user ): void {
		$cookie = (array) $cookie;
		$token  = isset( $cookie['token'] ) ? (string) $cookie['token'] : '';
		if ( '' === $token || ! $user instanceof \WP_User ) {
			return;
		}

		$session = \WP_Session_Tokens::get_instance( $user->ID )->get( $token );
		if ( ! is_array( $session ) || empty( $session[ self::MARKER ] ) ) {
			return;
		}

		$this->used = array(
			'user'  => (int) $user->ID,
			'token' => $token,
		);

		$allowed = (string) ( $session[ self::MARKER ]['path'] ?? '' );
		if ( ! self::allowed( self::request_method(), self::request_path(), $allowed ) ) {
			$this->refused = true;
		}
	}

	/**
	 * Whether a request may use a marked session.
	 *
	 * @param string $method  HTTP method.
	 * @param string $path    Request path.
	 * @param string $allowed The path the session was made for.
	 * @return bool
	 */
	public static function allowed( string $method, string $path, string $allowed ): bool {
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || '' === $allowed ) {
			return false;
		}
		if ( false !== strpos( $path, 'admin-post.php' ) || false !== strpos( $path, 'admin-ajax.php' ) || false !== strpos( $path, '/wp-json/' ) ) {
			return false;
		}

		return untrailingslashit( $path ) === untrailingslashit( $allowed );
	}

	/**
	 * No user for a request that broke the session's rules.
	 *
	 * @param int|false $user_id User so far.
	 * @return int|false
	 */
	public function refuse_user( $user_id ) {
		return $this->refused ? false : $user_id;
	}

	/**
	 * Refuses on wp-admin screens too, which check the cookie themselves.
	 */
	public function refuse_screen(): void {
		if ( $this->refused ) {
			wp_die( esc_html__( 'This smoke test login is only valid for one screen.', 'axle-smoke-test' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Single use: the session ends with the request that used it.
	 */
	public function destroy_used(): void {
		if ( $this->used ) {
			self::destroy( $this->used['user'], $this->used['token'] );
			$this->used = null;
		}
	}

	/**
	 * The path of a URL, for matching.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	/**
	 * This request's method.
	 *
	 * @return string
	 */
	private static function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * This request's path.
	 *
	 * @return string
	 */
	private static function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );

		return is_string( $path ) ? $path : '/';
	}
}
