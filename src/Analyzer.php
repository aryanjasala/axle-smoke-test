<?php
/**
 * Reads a page's HTML: hash, text, landmarks and assets.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a response body into the signals the server tier compares: a hash of
 * the normalised HTML, the visible text and a fingerprint of it, landmark
 * counts, same-origin scripts and stylesheets, and whether it is core's
 * "critical error" page.
 */
final class Analyzer {

	/** How many numbers the text fingerprint has. */
	public const FINGERPRINT_SIZE = 32;

	/** Landmarks counted on each page. */
	public const LANDMARKS = array( 'header', 'nav', 'main', 'footer', 'h1', 'form', 'button' );

	/** Most assets checked per page. */
	public const MAX_ASSETS = 40;

	/**
	 * Every signal from one HTML page.
	 *
	 * @param string $html     Response body.
	 * @param string $page_url The page's URL, to resolve relative links.
	 * @return array html_hash, text, text_bytes, fingerprint, landmarks, assets, critical_error, title.
	 */
	public static function analyse( string $html, string $page_url ): array {
		$clean = self::strip_scrape_marker( $html );
		$text  = self::visible_text( $clean );

		return array(
			'html_hash'      => hash( 'sha256', self::normalise( $clean ) ),
			'text'           => $text,
			'text_bytes'     => strlen( $text ),
			'fingerprint'    => self::fingerprint( $text ),
			'landmarks'      => self::landmarks( $clean ),
			'assets'         => self::assets( $clean, $page_url ),
			'critical_error' => self::is_critical_error( $clean ),
			'title'          => self::title( $clean ),
		);
	}

	/**
	 * Removes core's scrape result block from the end of a body.
	 *
	 * @param string $html Body.
	 * @return string
	 */
	public static function strip_scrape_marker( string $html ): string {
		return (string) preg_replace( '/\s*###### wp_scraping_result_start:[a-z0-9]+ ######.*?###### wp_scraping_result_end:[a-z0-9]+ ######\s*/s', '', $html );
	}

	/**
	 * HTML with the parts that change on every load taken out: nonces,
	 * timestamps, version query strings, inline JSON and generated ids.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function normalise( string $html ): string {
		$patterns = array(
			// Inline JSON and JavaScript data blobs, which carry nonces and settings.
			'#<script\b[^>]*type=["\']?application/(?:ld\+)?json["\']?[^>]*>.*?</script>#is' => '<script type="application/json"></script>',
			'#<script\b[^>]*id=["\']?[\w-]+-js-(?:extra|before|after)["\']?[^>]*>.*?</script>#is' => '<script></script>',
			'#<script\b[^>]*type=["\']?importmap["\']?[^>]*>.*?</script>#is' => '<script type="importmap"></script>',
			// Nonces in attributes, query strings and JSON.
			'/(nonce["\']?\s*[:=]\s*["\']?)[a-f0-9]{10}/i' => '$1',
			'/(_wpnonce=)[a-f0-9]{10}/i'                   => '$1',
			'/(name=["\']_wpnonce["\'][^>]*value=["\'])[^"\']*/i' => '$1',
			'/(data-[\w-]*nonce=["\'])[^"\']*/i'           => '$1',
			// Version and cache-busting query strings.
			'/([?&](?:amp;)?ver=)[^"\'&\s>]*/i'            => '$1',
			'/([?&](?:amp;)?axle_smoke_nocache=)[^"\'&\s>]*/i' => '$1',
			// Ids and classes that blocks generate on each render.
			'/\b(wp-(?:elements|container|duotone|block-[\w-]+-is-layout)-)[a-f0-9]{6,}\b/i' => '$1',
			'/\b(wp-container-(?:[\w-]+-)?is-layout-)[\w]+\b/i' => '$1',
			'/\bwp-elements-\d+\b/'                        => 'wp-elements-',
			'/\b(id=["\'][\w-]*?)[a-f0-9]{8,}(["\'])/i'    => '$1$2',
			// Dates and times.
			'/\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)?\b/' => '',
			'/\b1\d{9}(?:\.\d+)?\b/'                       => '',
			// HTML comments, which plugins stamp with timings.
			'/<!--(?!\s*\/?wp:).*?-->/s'                   => '',
			'/\s+/'                                        => ' ',
		);

		$normalised = (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $html );

		/**
		 * Filters the normalised HTML before it is hashed.
		 *
		 * @param string $normalised HTML with nonces, timestamps and ids taken out.
		 * @param string $html       The original HTML.
		 */
		$filtered = (string) apply_filters( 'axle_smoke_test_normalise_html', $normalised, $html );

		return trim( $filtered );
	}

	/**
	 * The text a visitor sees: no scripts, styles, comments or tags.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function visible_text( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|noscript|template|svg|head)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = (string) preg_replace( '/<[^>]+>/', ' ', $html );
		$text = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * A MinHash fingerprint of the text, so two versions can be compared
	 * without storing the text itself.
	 *
	 * @param string $text Visible text.
	 * @return int[]
	 */
	public static function fingerprint( string $text ): array {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? $words : array();
		if ( ! $words ) {
			return array();
		}

		$shingles = array();
		$count    = count( $words );
		if ( $count < 3 ) {
			$shingles[] = implode( ' ', $words );
		} else {
			for ( $i = 0; $i <= $count - 3; $i++ ) {
				$shingles[ $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ] ] = true;
			}
			$shingles = array_keys( $shingles );
		}

		$fingerprint = array();
		for ( $seed = 0; $seed < self::FINGERPRINT_SIZE; $seed++ ) {
			$min = PHP_INT_MAX;
			foreach ( $shingles as $shingle ) {
				$hash = crc32( $seed . ':' . $shingle );
				if ( $hash < $min ) {
					$min = $hash;
				}
			}
			$fingerprint[] = $min;
		}

		return $fingerprint;
	}

	/**
	 * How alike two fingerprints are, from 0 (nothing shared) to 1 (same text).
	 *
	 * @param int[] $a Fingerprint.
	 * @param int[] $b Fingerprint.
	 * @return float
	 */
	public static function similarity( array $a, array $b ): float {
		if ( ! $a && ! $b ) {
			return 1.0;
		}
		if ( ! $a || ! $b || count( $a ) !== count( $b ) ) {
			return 0.0;
		}

		$same = 0;
		foreach ( $a as $i => $value ) {
			if ( (int) $value === (int) $b[ $i ] ) {
				++$same;
			}
		}

		return $same / count( $a );
	}

	/**
	 * How many of each landmark the page has.
	 *
	 * @param string $html HTML.
	 * @return array<string, int>
	 */
	public static function landmarks( string $html ): array {
		$html   = (string) preg_replace( '#<(script|style|template)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html   = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		$counts = array();
		foreach ( self::LANDMARKS as $tag ) {
			$counts[ $tag ] = (int) preg_match_all( '/<' . $tag . '\b/i', $html );
		}

		$roles = array(
			'header' => 'banner',
			'nav'    => 'navigation',
			'main'   => 'main',
			'footer' => 'contentinfo',
		);
		foreach ( $roles as $tag => $role ) {
			$counts[ $tag ] += (int) preg_match_all( '/\brole=["\']?' . $role . '\b/i', $html );
		}

		return $counts;
	}

	/**
	 * Same-origin scripts and stylesheets the page loads.
	 *
	 * @param string $html     HTML.
	 * @param string $page_url Page URL.
	 * @return string[] Absolute URLs.
	 */
	public static function assets( string $html, string $page_url ): array {
		$found = array();
		if ( preg_match_all( '#<script\b[^>]*\bsrc=["\']([^"\']+)["\']#i', $html, $matches ) ) {
			$found = array_merge( $found, $matches[1] );
		}
		if ( preg_match_all( '#<link\b[^>]*>#i', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				if ( preg_match( '/\brel=["\']?stylesheet\b/i', $tag ) && preg_match( '/\bhref=["\']([^"\']+)["\']/i', $tag, $href ) ) {
					$found[] = $href[1];
				}
			}
		}

		$host   = strtolower( (string) wp_parse_url( $page_url, PHP_URL_HOST ) );
		$assets = array();
		foreach ( $found as $url ) {
			$url = self::resolve( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ), $page_url );
			if ( '' !== $url && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $host ) {
				$assets[ $url ] = true;
			}
		}

		return array_slice( array_keys( $assets ), 0, self::MAX_ASSETS );
	}

	/**
	 * Whether the page is core's "There has been a critical error" page.
	 *
	 * @param string $html HTML.
	 * @return bool
	 */
	public static function is_critical_error( string $html ): bool {
		if ( false !== stripos( $html, 'There has been a critical error on this website' ) ) {
			return true;
		}

		return (bool) preg_match( '/<body\b[^>]*id=["\']error-page["\']/i', $html ) && false !== stripos( $html, 'wp-die-message' ) && false !== stripos( $html, 'critical' );
	}

	/**
	 * The page title.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function title( string $html ): string {
		if ( preg_match( '#<title\b[^>]*>(.*?)</title>#is', $html, $match ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES, 'UTF-8' ) );
		}

		return '';
	}

	/**
	 * An absolute URL from a link on a page.
	 *
	 * @param string $url      Link.
	 * @param string $page_url Page URL.
	 * @return string
	 */
	public static function resolve( string $url, string $page_url ): string {
		$url = trim( $url );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$parts  = wp_parse_url( $page_url );
		$scheme = $parts['scheme'] ?? 'http';
		$origin = $scheme . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		if ( '/' === $url[0] ) {
			return $origin . $url;
		}

		$path = $parts['path'] ?? '/';
		$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );

		return $origin . $dir . $url;
	}
}
