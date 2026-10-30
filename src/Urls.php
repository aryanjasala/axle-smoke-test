<?php
/**
 * The key URLs a run checks.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of key URLs: the ones the admin added, plus pages picked
 * from the site (home, latest post and page, a category, search, a 404, the
 * feed, the REST index, the login page, two wp-admin screens and, with
 * WooCommerce, the shop pages).
 *
 * Each URL is an array: key, label, url, expected (list of HTTP statuses),
 * browser (checked in the browser tier too), auth (needs a signed-in user),
 * kind (html, feed, json) and source (auto or custom).
 */
final class Urls {

	public const OPTION = 'axle_smoke_test_urls';

	public const AUTO_TYPES = array( 'home', 'post', 'page', 'category', 'search', 'not_found', 'feed', 'rest', 'login', 'dashboard', 'plugins', 'woocommerce' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the list.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Every key URL, after the filters, capped at the maximum.
	 *
	 * @return array[]
	 */
	public function all(): array {
		$urls = array_merge( $this->auto(), $this->custom() );

		/**
		 * Filters the final list of key URLs a run checks.
		 *
		 * @param array[] $urls Each: key, label, url, expected (int[]), browser, auth, kind, source.
		 */
		$urls = (array) apply_filters( 'axle_smoke_test_urls', $urls );

		$clean = array();
		foreach ( $urls as $url ) {
			$url = self::normalise( $url );
			if ( $url && ! isset( $clean[ $url['key'] ] ) ) {
				$clean[ $url['key'] ] = $url;
			}
		}

		return array_slice( array_values( $clean ), 0, self::max() );
	}

	/**
	 * Only the URLs with these keys, or every URL for an empty list.
	 *
	 * @param string[] $keys URL keys.
	 * @return array[]
	 */
	public function pick( array $keys ): array {
		$all = $this->all();
		if ( ! $keys ) {
			return $all;
		}

		return array_values(
			array_filter(
				$all,
				static function ( $url ) use ( $keys ) {
					return in_array( $url['key'], $keys, true );
				}
			)
		);
	}

	/**
	 * One URL by key.
	 *
	 * @param string $key URL key.
	 * @return array|null
	 */
	public function find( string $key ): ?array {
		foreach ( $this->all() as $url ) {
			if ( $url['key'] === $key ) {
				return $url;
			}
		}

		return null;
	}

	/**
	 * The most URLs a run checks.
	 *
	 * @return int
	 */
	public static function max(): int {
		/**
		 * Filters the most key URLs a run checks.
		 *
		 * @param int $max Default 25. Clamped to 1 to 200.
		 */
		return Settings::filtered_int( 'max_urls', 25, 1, 200 );
	}

	/**
	 * The URLs the admin added.
	 *
	 * @return array[]
	 */
	public function custom(): array {
		$saved = get_option( self::OPTION, array() );
		$urls  = array();
		foreach ( is_array( $saved ) ? $saved : array() as $url ) {
			$url = self::normalise( is_array( $url ) ? array( 'source' => 'custom' ) + $url : null );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Adds a URL to the admin's list.
	 *
	 * @param string $url      Absolute URL on this site, or a path.
	 * @param int[]  $expected Expected statuses. Default 200.
	 * @param bool   $browser  Check it in the browser tier too.
	 * @param string $label    Label. Default: the path.
	 * @return array|\WP_Error The URL.
	 */
	public function add( string $url, array $expected = array( 200 ), bool $browser = true, string $label = '' ) {
		$absolute = self::absolute( $url );
		if ( '' === $absolute ) {
			return new \WP_Error( 'axle_smoke_test_bad_url', __( 'Use a URL on this site, or a path like /shop/.', 'axle-smoke-test' ) );
		}

		$custom = $this->custom();
		if ( count( $custom ) >= self::max() ) {
			return new \WP_Error( 'axle_smoke_test_too_many_urls', __( 'The list is full. Remove a URL first.', 'axle-smoke-test' ) );
		}

		$path = (string) wp_parse_url( $absolute, PHP_URL_PATH );
		$item = self::normalise(
			array(
				'key'      => 'url-' . substr( md5( $absolute ), 0, 8 ),
				'label'    => '' !== $label ? $label : ( '' !== $path ? $path : '/' ),
				'url'      => $absolute,
				'expected' => $expected,
				'browser'  => $browser,
				'auth'     => false !== strpos( $absolute, admin_url() ),
				'source'   => 'custom',
			)
		);
		if ( ! $item ) {
			return new \WP_Error( 'axle_smoke_test_bad_url', __( 'Use a URL on this site, or a path like /shop/.', 'axle-smoke-test' ) );
		}

		foreach ( $custom as $existing ) {
			if ( $existing['key'] === $item['key'] ) {
				return new \WP_Error( 'axle_smoke_test_duplicate_url', __( 'That URL is already on the list.', 'axle-smoke-test' ) );
			}
		}

		$custom[] = $item;
		update_option( self::OPTION, $custom, false );

		return $item;
	}

	/**
	 * Removes a URL from the admin's list.
	 *
	 * @param string $key URL key.
	 * @return bool Whether it was there.
	 */
	public function remove( string $key ): bool {
		$custom = $this->custom();
		$kept   = array_values(
			array_filter(
				$custom,
				static function ( $url ) use ( $key ) {
					return $url['key'] !== $key;
				}
			)
		);
		if ( count( $kept ) === count( $custom ) ) {
			return false;
		}

		update_option( self::OPTION, $kept, false );
		return true;
	}

	/**
	 * Pages picked from the site, by type, for the types switched on.
	 *
	 * @return array[]
	 */
	public function auto(): array {
		$on   = (array) $this->settings->get( 'auto_pick' );
		$urls = array();
		$add  = static function ( string $type, string $key, string $label, string $url, array $extra = array() ) use ( &$urls, $on ) {
			if ( empty( $on[ $type ] ) || '' === $url ) {
				return;
			}
			$urls[] = array_merge(
				array(
					'key'      => $key,
					'label'    => $label,
					'url'      => $url,
					'expected' => array( 200 ),
					'browser'  => true,
					'auth'     => false,
					'kind'     => 'html',
					'source'   => 'auto',
				),
				$extra
			);
		};

		$add( 'home', 'home', __( 'Home page', 'axle-smoke-test' ), home_url( '/' ) );

		$post = empty( $on['post'] ) ? 0 : self::latest( 'post', 0 );
		if ( $post ) {
			$add( 'post', 'latest-post', __( 'Latest post', 'axle-smoke-test' ), (string) get_permalink( $post ) );
		}

		$page = empty( $on['page'] ) ? 0 : self::latest( 'page', (int) get_option( 'page_on_front' ) );
		if ( $page ) {
			$add( 'page', 'latest-page', __( 'Latest page', 'axle-smoke-test' ), (string) get_permalink( $page ) );
		}

		$terms = empty( $on['category'] ) ? array() : get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'number'     => 1,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_array( $terms ) && $terms ) {
			$link = get_term_link( $terms[0] );
			$add( 'category', 'category', __( 'Category archive', 'axle-smoke-test' ), is_string( $link ) ? $link : '' );
		}

		$add( 'search', 'search', __( 'Search results', 'axle-smoke-test' ), add_query_arg( 's', 'hello', home_url( '/' ) ) );
		$add(
			'not_found',
			'not-found',
			__( 'Page not found (404)', 'axle-smoke-test' ),
			home_url( '/axle-smoke-test-404-check/' ),
			array( 'expected' => array( 404 ) )
		);
		$add(
			'feed',
			'feed',
			__( 'RSS feed', 'axle-smoke-test' ),
			(string) get_feed_link(),
			array(
				'browser' => false,
				'kind'    => 'feed',
			)
		);
		$add(
			'rest',
			'rest',
			__( 'REST API index', 'axle-smoke-test' ),
			(string) get_rest_url(),
			array(
				'browser' => false,
				'kind'    => 'json',
			)
		);
		$add( 'login', 'login', __( 'Login page', 'axle-smoke-test' ), wp_login_url() );
		$add(
			'dashboard',
			'dashboard',
			__( 'Dashboard', 'axle-smoke-test' ),
			admin_url( '/' ),
			array(
				'browser' => false,
				'auth'    => true,
			)
		);
		$add(
			'plugins',
			'plugins-screen',
			__( 'Plugins screen', 'axle-smoke-test' ),
			admin_url( 'plugins.php' ),
			array(
				'browser' => false,
				'auth'    => true,
			)
		);

		if ( ! empty( $on['woocommerce'] ) && function_exists( 'wc_get_page_permalink' ) ) {
			$add( 'woocommerce', 'shop', __( 'Shop', 'axle-smoke-test' ), (string) wc_get_page_permalink( 'shop' ) );
			$product = self::latest( 'product', 0 );
			if ( $product ) {
				$add( 'woocommerce', 'latest-product', __( 'Latest product', 'axle-smoke-test' ), (string) get_permalink( $product ) );
			}
			$add( 'woocommerce', 'cart', __( 'Cart', 'axle-smoke-test' ), (string) wc_get_page_permalink( 'cart' ) );
			// With an empty cart, checkout either sends the visitor to the cart or says the cart is empty.
			$add( 'woocommerce', 'checkout', __( 'Checkout (empty cart)', 'axle-smoke-test' ), (string) wc_get_page_permalink( 'checkout' ), array( 'expected' => array( 200, 302 ) ) );
			$add( 'woocommerce', 'my-account', __( 'My account', 'axle-smoke-test' ), (string) wc_get_page_permalink( 'myaccount' ) );
		}

		/**
		 * Filters the URLs picked from the site, before the admin's own are added.
		 *
		 * @param array[] $urls Each: key, label, url, expected (int[]), browser, auth, kind, source.
		 */
		return (array) apply_filters( 'axle_smoke_test_auto_urls', $urls );
	}

	/**
	 * Checks and completes one URL entry. Only URLs on this site are kept.
	 *
	 * @param mixed $url URL entry.
	 * @return array|null
	 */
	public static function normalise( $url ): ?array {
		if ( ! is_array( $url ) || empty( $url['url'] ) ) {
			return null;
		}

		$absolute = self::absolute( (string) $url['url'] );
		if ( '' === $absolute ) {
			return null;
		}

		$key      = isset( $url['key'] ) ? sanitize_key( (string) $url['key'] ) : '';
		$key      = '' !== $key ? substr( $key, 0, 64 ) : 'url-' . substr( md5( $absolute ), 0, 8 );
		$expected = self::statuses( $url['expected'] ?? array( 200 ) );
		$kind     = isset( $url['kind'] ) && in_array( $url['kind'], array( 'html', 'feed', 'json' ), true ) ? $url['kind'] : 'html';

		$entry = array(
			'key'      => $key,
			'label'    => isset( $url['label'] ) && '' !== (string) $url['label'] ? sanitize_text_field( (string) $url['label'] ) : $absolute,
			'url'      => $absolute,
			'expected' => $expected,
			'browser'  => 'html' === $kind && ( ! isset( $url['browser'] ) || (bool) $url['browser'] ),
			'auth'     => ! empty( $url['auth'] ),
			'kind'     => $kind,
			'source'   => isset( $url['source'] ) && 'custom' === $url['source'] ? 'custom' : 'auto',
		);

		/**
		 * Filters the HTTP statuses a URL may answer with and stay green.
		 *
		 * @param int[] $expected Statuses, e.g. array( 200 ) or array( 404 ).
		 * @param array $entry    The URL entry.
		 */
		$filtered          = apply_filters( 'axle_smoke_test_expected_status', $expected, $entry );
		$filtered          = self::statuses( $filtered );
		$entry['expected'] = $filtered ? $filtered : $expected;

		return $entry;
	}

	/**
	 * A list of HTTP statuses from an int, a "200,302" string or an array.
	 *
	 * @param mixed $value Statuses.
	 * @return int[]
	 */
	public static function statuses( $value ): array {
		$list = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$out  = array();
		foreach ( (array) $list as $status ) {
			$status = (int) $status;
			if ( $status >= 100 && $status <= 599 ) {
				$out[] = $status;
			}
		}
		$out = array_values( array_unique( $out ) );

		return $out ? $out : array( 200 );
	}

	/**
	 * An absolute URL on this site, from a URL or a path. Empty for other sites.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	public static function absolute( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			$url = home_url( $url );
		}

		$url  = esc_url_raw( $url, array( 'http', 'https' ) );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		return $host && $home && strtolower( (string) $host ) === strtolower( (string) $home ) ? $url : '';
	}

	/**
	 * The newest published post of a type, leaving one ID out.
	 *
	 * @param string $type    Post type.
	 * @param int    $exclude Post ID to skip.
	 * @return int Post ID, or 0.
	 */
	private static function latest( string $type, int $exclude ): int {
		$query = new \WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => 'publish',
				'posts_per_page'         => 2,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $query->posts as $id ) {
			if ( (int) $id !== $exclude ) {
				return (int) $id;
			}
		}

		return 0;
	}
}
