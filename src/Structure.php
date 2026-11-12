<?php
/**
 * The structure of a page: what it is made of, not what it says.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Learns a structure fingerprint of a page at baseline (landmarks, headings,
 * counts of links, images, forms and buttons, the size of the text and the
 * key elements found by themselves: forms with their action, add to cart
 * controls, the search and login forms, the menu) and compares a new page
 * with it. A page that answers 200 but lost its header, its form or its Buy
 * button is caught here, with a sentence that says what is missing.
 *
 * It reads the HTML with DOMDocument, which PHP ships. Without it (the dom
 * extension is optional) every function returns nothing and the older checks
 * in Verdict still run.
 */
final class Structure {

	/** Landmarks the fingerprint records. */
	public const LANDMARKS = array( 'header', 'nav', 'main', 'footer' );

	/** The ARIA role of each landmark. */
	private const ROLES = array(
		'header' => 'banner',
		'nav'    => 'navigation',
		'main'   => 'main',
		'footer' => 'contentinfo',
	);

	/** Most HTML read per page. */
	private const MAX_HTML = 2097152;

	/**
	 * Whether this PHP can read HTML for the fingerprint.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' );
	}

	/**
	 * The upper edges of the count buckets.
	 *
	 * @return int[]
	 */
	public static function buckets(): array {
		$default = array( 1, 2, 3, 4, 5, 8, 12, 20, 35, 60, 100, 170, 300, 500, 850, 1500, 3000, 6000, 12000, 25000, 50000, 100000, 250000, 1000000 );

		/**
		 * Filters the bucket edges that counts and text sizes fall into, so a small change in content does not make a page look different.
		 *
		 * @param int[] $edges Ascending upper edges. Default: 1, 2, 3, 4, 5, 8, 12, 20 and so on up to a million.
		 */
		$edges = (array) apply_filters( 'axle_smoke_test_structure_buckets', $default );
		$edges = array_values( array_unique( array_filter( array_map( 'intval', $edges ), static fn( $edge ) => $edge > 0 ) ) );
		sort( $edges );

		return $edges ? $edges : $default;
	}

	/**
	 * The bucket a number falls into: its upper edge. Zero is its own bucket.
	 *
	 * @param int   $number Number.
	 * @param int[] $edges  Edges from buckets().
	 * @return int
	 */
	public static function bucket( int $number, array $edges ): int {
		if ( $number <= 0 ) {
			return 0;
		}
		foreach ( $edges as $edge ) {
			if ( $number <= $edge ) {
				return (int) $edge;
			}
		}

		return (int) end( $edges );
	}

	/**
	 * Reads a page's HTML into a DOM, or null when it cannot be read.
	 *
	 * @param string $html HTML.
	 * @return \DOMDocument|null
	 */
	public static function dom( string $html ): ?\DOMDocument {
		if ( ! self::available() || '' === trim( $html ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$loaded   = $dom->loadHTML( '<?xml encoding="UTF-8">' . substr( $html, 0, self::MAX_HTML ), LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? $dom : null;
	}

	/**
	 * The fingerprint of one page.
	 *
	 * @param string $html Response body.
	 * @return array Empty when the HTML cannot be read. Otherwise: v, landmarks, title, h1, counts, text, forms, add_to_cart, search, login.
	 */
	public static function analyse( string $html ): array {
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return array();
		}

		$xpath = new \DOMXPath( $dom );
		$edges = self::buckets();

		// Scripts and templates are not part of the page a visitor sees.
		foreach ( self::nodes( $xpath, '//script|//style|//template|//noscript' ) as $node ) {
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property names.
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
			// phpcs:enable
		}

		$landmarks = array();
		foreach ( self::LANDMARKS as $tag ) {
			$landmarks[ $tag ] = self::count( $xpath, '//' . $tag . '|//*[@role="' . self::ROLES[ $tag ] . '"]' ) > 0 ? 1 : 0;
		}

		$forms = array();
		$login = 0;
		$find  = 0;
		foreach ( self::nodes( $xpath, '//form' ) as $form ) {
			if ( ! $form instanceof \DOMElement ) {
				continue;
			}
			$kind = 'form';
			if ( self::count( $xpath, './/input[@type="password"]', $form ) > 0 ) {
				$kind = 'login';
				++$login;
			} elseif ( 'search' === $form->getAttribute( 'role' ) || self::count( $xpath, './/input[@type="search" or @name="s"]', $form ) > 0 ) {
				$kind = 'search';
				++$find;
			}
			$path = (string) wp_parse_url( $form->getAttribute( 'action' ), PHP_URL_PATH );
			// A notice's Dismiss button posts to admin-post.php and comes and goes. It is not a part of the page.
			if ( preg_match( '#/admin-(?:post|ajax)\.php$#', $path ) ) {
				continue;
			}
			$name = $kind . ':' . ( '' === $path ? '/' : $path );
			if ( count( $forms ) < 20 || isset( $forms[ $name ] ) ) {
				$forms[ $name ] = ( $forms[ $name ] ?? 0 ) + 1;
			}
		}
		ksort( $forms );

		$menu = 0;
		foreach ( self::nodes( $xpath, '//nav' ) as $nav ) {
			$menu = max( $menu, self::count( $xpath, './/a', $nav ) );
		}

		$h1    = self::nodes( $xpath, '//h1' );
		$title = self::nodes( $xpath, '//title' );

		return array(
			'v'           => 1,
			'landmarks'   => $landmarks,
			'title'       => $title ? substr( self::text( $title[0] ), 0, 200 ) : '',
			'h1'          => $h1 ? substr( self::text( $h1[0] ), 0, 200 ) : '',
			'counts'      => array(
				'links'      => self::bucket( self::count( $xpath, '//a[@href]' ), $edges ),
				'images'     => self::bucket( self::count( $xpath, '//img|//picture|//svg' ), $edges ),
				'buttons'    => self::bucket( self::count( $xpath, '//button|//input[@type="submit" or @type="button"]|//*[@role="button"]' ), $edges ),
				'menu_items' => self::bucket( $menu, $edges ),
			),
			'text'        => self::bucket( strlen( Analyzer::visible_text( $html ) ), $edges ),
			'forms'       => $forms,
			'add_to_cart' => self::count( $xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," add_to_cart_button ") or contains(concat(" ",normalize-space(@class)," ")," single_add_to_cart_button ") or contains(@class,"wp-block-woocommerce-add-to-cart-form") or @name="add-to-cart"]' ),
			'search'      => $find,
			'login'       => $login,
		);
	}

	/**
	 * What changed between a page's baseline fingerprint and its fingerprint now.
	 *
	 * Something that disappeared is red. Counts and text that fell a long way
	 * are amber.
	 *
	 * @param array $now  Fingerprint now.
	 * @param array $base Fingerprint at baseline.
	 * @return array[] Findings, each: level, code, message, sig.
	 */
	public static function compare( array $now, array $base ): array {
		if ( ! $now || ! $base ) {
			return array();
		}

		/**
		 * Filters how far a count or the text size may fall before a page turns amber, in percent.
		 *
		 * @param int $percent Default 50. Clamped to 1 to 99.
		 */
		$drop     = Settings::filtered_int( 'structure_drop_percent', 50, 1, 99 );
		$findings = array();
		$add      = static function ( string $level, string $code, string $message, string $sig ) use ( &$findings ): void {
			$findings[] = array(
				'level'   => $level,
				'code'    => $code,
				'message' => $message,
				'sig'     => $sig,
			);
		};

		$names = array(
			'header' => __( 'header', 'axle-smoke-test' ),
			'nav'    => __( 'navigation', 'axle-smoke-test' ),
			'main'   => __( 'main content area', 'axle-smoke-test' ),
			'footer' => __( 'footer', 'axle-smoke-test' ),
		);
		foreach ( (array) ( $base['landmarks'] ?? array() ) as $tag => $had ) {
			if ( $had && empty( $now['landmarks'][ $tag ] ) && isset( $names[ $tag ] ) ) {
				/* translators: %s: the part of the page, like header or footer. */
				$add( Verdict::RED, 'structure_landmark', sprintf( __( 'The page no longer has a %s.', 'axle-smoke-test' ), $names[ $tag ] ), 'landmark:' . $tag );
			}
		}

		$before = (array) ( $base['forms'] ?? array() );
		$after  = (array) ( $now['forms'] ?? array() );
		foreach ( $before as $name => $count ) {
			$left = (int) ( $after[ $name ] ?? 0 );
			if ( $left >= (int) $count ) {
				continue;
			}
			list( $kind, $path ) = array_pad( explode( ':', (string) $name, 2 ), 2, '/' );
			$what                = 'search' === $kind ? __( 'search form', 'axle-smoke-test' ) : ( 'login' === $kind ? __( 'login form', 'axle-smoke-test' ) : __( 'form', 'axle-smoke-test' ) );
			if ( 0 === $left ) {
				$add(
					Verdict::RED,
					'structure_form',
					'/' === $path || '' === $path
						/* translators: 1: kind of form, 2: how many it had. */
						? sprintf( __( 'The page no longer has a %1$s. It had %2$d.', 'axle-smoke-test' ), $what, (int) $count )
						/* translators: 1: kind of form, 2: where it sends the data, 3: how many it had. */
						: sprintf( __( 'The page no longer has a %1$s that sends to %2$s. It had %3$d.', 'axle-smoke-test' ), $what, $path, (int) $count ),
					'form:' . $name
				);
			} else {
				/* translators: 1: kind of form, 2: how many now, 3: how many it had. */
				$add( Verdict::AMBER, 'structure_form', sprintf( __( 'The page has fewer %1$ss: %2$d now, %3$d before.', 'axle-smoke-test' ), $what, $left, (int) $count ), 'form:' . $name );
			}
		}

		if ( (int) ( $base['add_to_cart'] ?? 0 ) > 0 && 0 === (int) ( $now['add_to_cart'] ?? 0 ) ) {
			/* translators: %d: how many it had. */
			$add( Verdict::RED, 'structure_add_to_cart', sprintf( __( 'The page no longer has an Add to cart button. It had %d.', 'axle-smoke-test' ), (int) $base['add_to_cart'] ), 'add_to_cart' );
		}

		$menu_before = (int) ( $base['counts']['menu_items'] ?? 0 );
		if ( $menu_before >= 2 && 0 === (int) ( $now['counts']['menu_items'] ?? 0 ) ) {
			/* translators: %d: how many links the menu had. */
			$add( Verdict::RED, 'structure_menu', sprintf( __( 'The page no longer has a menu. It had about %d links in it.', 'axle-smoke-test' ), $menu_before ), 'menu' );
		}

		if ( '' !== (string) ( $base['h1'] ?? '' ) && '' === (string) ( $now['h1'] ?? '' ) ) {
			/* translators: %s: the heading it had. */
			$add( Verdict::AMBER, 'structure_h1', sprintf( __( 'The page no longer has a main heading (h1). It had "%s".', 'axle-smoke-test' ), (string) $base['h1'] ), 'h1' );
		}

		$labels = array(
			'links'   => array( __( 'links', 'axle-smoke-test' ), 5 ),
			'images'  => array( __( 'images', 'axle-smoke-test' ), 3 ),
			'buttons' => array( __( 'buttons', 'axle-smoke-test' ), 2 ),
		);
		foreach ( $labels as $key => $label ) {
			$was = (int) ( $base['counts'][ $key ] ?? 0 );
			$is  = (int) ( $now['counts'][ $key ] ?? 0 );
			if ( $was >= $label[1] && $is <= $was * ( 100 - $drop ) / 100 ) {
				/* translators: 1: what was counted (links, images, buttons), 2: how many now, 3: how many before. */
				$add( Verdict::AMBER, 'structure_count', sprintf( __( 'The page has far fewer %1$s: about %2$d now, about %3$d before.', 'axle-smoke-test' ), $label[0], $is, $was ), 'count:' . $key );
			}
		}

		$text_was = (int) ( $base['text'] ?? 0 );
		$text_is  = (int) ( $now['text'] ?? 0 );
		if ( $text_was >= 100 && $text_is <= $text_was * ( 100 - $drop ) / 100 ) {
			/* translators: 1: characters now, 2: characters before. */
			$add( Verdict::AMBER, 'structure_text', sprintf( __( 'The page has far less text: about %1$d characters now, about %2$d before.', 'axle-smoke-test' ), $text_is, $text_was ), 'text' );
		}

		/**
		 * Filters the findings of the structure check for one page.
		 *
		 * @param array[] $findings Each: level (red or amber), code, message, sig (stable id, used to accept one finding).
		 * @param array   $now      Fingerprint now.
		 * @param array   $base     Fingerprint at baseline.
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_structure_findings', $findings, $now, $base );

		$out = array();
		foreach ( $filtered as $finding ) {
			$finding = (array) $finding;
			if ( isset( $finding['level'], $finding['message'], $finding['sig'] ) && isset( Verdict::ORDER[ $finding['level'] ] ) && Verdict::GREEN !== $finding['level'] ) {
				$out[] = array(
					'level'   => (string) $finding['level'],
					'code'    => (string) ( $finding['code'] ?? 'structure' ),
					'message' => (string) $finding['message'],
					'sig'     => substr( (string) $finding['sig'], 0, 120 ),
				);
			}
		}

		return $out;
	}

	/**
	 * Checks a page against the person's own rules: text or elements it must
	 * contain and must not contain.
	 *
	 * @param string $html  Response body.
	 * @param array  $rules must and must_not: lists of text or CSS selectors.
	 * @return array[] Findings, each: level (always red), code, message, sig.
	 */
	public static function check_rules( string $html, array $rules ): array {
		$must     = (array) ( $rules['must'] ?? array() );
		$must_not = (array) ( $rules['must_not'] ?? array() );
		if ( ! $must && ! $must_not ) {
			return array();
		}

		$dom      = self::dom( $html );
		$xpath    = $dom ? new \DOMXPath( $dom ) : null;
		$text     = Analyzer::visible_text( $html );
		$findings = array();

		foreach ( array(
			'must'     => $must,
			'must_not' => $must_not,
		) as $kind => $list ) {
			foreach ( $list as $rule ) {
				$rule  = (string) $rule;
				$found = self::rule_matches( $rule, $text, $xpath );
				if ( null === $found ) {
					$findings[] = array(
						'level'   => Verdict::AMBER,
						/* translators: %s: the rule the person wrote. */
						'message' => sprintf( __( 'The rule "%s" could not be read, so it was not checked. Use plain text, or a simple CSS selector like .buy-button or form#contact.', 'axle-smoke-test' ), $rule ),
						'code'    => 'rule_invalid',
						'sig'     => 'rule:' . $kind . ':' . substr( md5( $rule ), 0, 10 ),
					);
					continue;
				}
				if ( 'must' === $kind && ! $found ) {
					$findings[] = array(
						'level'   => Verdict::RED,
						/* translators: %s: the text or selector. */
						'message' => sprintf( __( 'The page must contain "%s", but it does not.', 'axle-smoke-test' ), self::plain( $rule ) ),
						'code'    => 'rule_must',
						'sig'     => 'rule:must:' . substr( md5( $rule ), 0, 10 ),
					);
				} elseif ( 'must_not' === $kind && $found ) {
					$findings[] = array(
						'level'   => Verdict::RED,
						/* translators: %s: the text or selector. */
						'message' => sprintf( __( 'The page must not contain "%s", but it does.', 'axle-smoke-test' ), self::plain( $rule ) ),
						'code'    => 'rule_must_not',
						'sig'     => 'rule:must_not:' . substr( md5( $rule ), 0, 10 ),
					);
				}
			}
		}

		return $findings;
	}

	/**
	 * Whether a rule is a CSS selector (as opposed to text).
	 *
	 * Text is the default. A rule is a selector when it starts with ".", "#"
	 * or "[", is a plain HTML tag name, or starts with "css:". Start a rule
	 * with "text:" to force text.
	 *
	 * @param string $rule Rule.
	 * @return bool
	 */
	public static function is_selector( string $rule ): bool {
		$rule = trim( $rule );
		if ( 0 === stripos( $rule, 'text:' ) ) {
			return false;
		}
		if ( 0 === stripos( $rule, 'css:' ) || 1 === preg_match( '/^[.#\[]/', $rule ) ) {
			return true;
		}

		return 1 === preg_match( '/^(?:header|nav|main|footer|form|button|input|select|textarea|img|picture|video|iframe|table|ul|ol|li|a|h[1-6]|section|article|aside)(?:[.#\[>\s,:][^\s].*)?$/i', $rule );
	}

	/**
	 * The rule without its "css:" or "text:" prefix.
	 *
	 * @param string $rule Rule.
	 * @return string
	 */
	public static function plain( string $rule ): string {
		return trim( (string) preg_replace( '/^(?:css|text):\s*/i', '', trim( $rule ) ) );
	}

	/**
	 * The selectors among a list of rules that the browser can check too.
	 *
	 * @param string[] $rules Rules.
	 * @return string[]
	 */
	public static function selectors( array $rules ): array {
		$out = array();
		foreach ( $rules as $rule ) {
			$rule = (string) $rule;
			if ( self::is_selector( $rule ) && null !== self::to_xpath( self::plain( $rule ) ) ) {
				$out[] = self::plain( $rule );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether the page has the text or element a rule names. Null for a rule that cannot be read.
	 *
	 * @param string         $rule  Rule.
	 * @param string         $text  Visible text of the page.
	 * @param \DOMXPath|null $xpath XPath of the page.
	 * @return bool|null
	 */
	private static function rule_matches( string $rule, string $text, ?\DOMXPath $xpath ): ?bool {
		$plain = self::plain( $rule );
		if ( '' === $plain ) {
			return null;
		}
		if ( ! self::is_selector( $rule ) ) {
			return function_exists( 'mb_stripos' ) ? false !== mb_stripos( $text, $plain, 0, 'UTF-8' ) : false !== stripos( $text, $plain );
		}

		$query = self::to_xpath( $plain );
		if ( null === $query ) {
			return null;
		}
		if ( ! $xpath ) {
			return null;
		}
		$nodes = @$xpath->query( $query ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An odd rule must not warn.

		return $nodes ? $nodes->length > 0 : false;
	}

	/**
	 * A simple CSS selector as XPath. Supports tags, #id, .class, [attr],
	 * [attr=value], [attr*=value], [attr^=value], [attr$=value], the descendant
	 * and child combinators and comma lists. Anything else returns null.
	 *
	 * @param string $selector Selector.
	 * @return string|null
	 */
	public static function to_xpath( string $selector ): ?string {
		$selector = trim( $selector );
		if ( '' === $selector || strlen( $selector ) > 300 ) {
			return null;
		}

		$paths = array();
		foreach ( explode( ',', $selector ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				return null;
			}
			$tokens = preg_split( '/\s*(>)\s*|\s+/', $part, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			if ( ! $tokens ) {
				return null;
			}
			$path = '';
			$axis = '//';
			foreach ( $tokens as $token ) {
				if ( '>' === $token ) {
					$axis = '/';
					continue;
				}
				$step = self::compound( $token );
				if ( null === $step ) {
					return null;
				}
				$path .= $axis . $step;
				$axis  = '//';
			}
			$paths[] = $path;
		}

		return implode( '|', $paths );
	}

	/**
	 * One compound selector, like form.cart#main[data-x="y"], as an XPath step.
	 *
	 * @param string $token Compound selector.
	 * @return string|null
	 */
	private static function compound( string $token ): ?string {
		if ( ! preg_match( '/^(\*|[a-zA-Z][a-zA-Z0-9-]*)?((?:[.#][\w-]+|\[[^\]]+\])*)$/', $token, $match ) ) {
			return null;
		}

		$tag        = '' === $match[1] ? '*' : strtolower( $match[1] );
		$conditions = array();
		preg_match_all( '/([.#][\w-]+)|\[([^\]]+)\]/', $match[2], $parts, PREG_SET_ORDER );
		foreach ( $parts as $part ) {
			if ( isset( $part[1] ) && '' !== $part[1] ) {
				$name = substr( $part[1], 1 );
				if ( '.' === $part[1][0] ) {
					$conditions[] = 'contains(concat(" ",normalize-space(@class)," "),' . self::quote( ' ' . $name . ' ' ) . ')';
				} else {
					$conditions[] = '@id=' . self::quote( $name );
				}
				continue;
			}
			if ( ! preg_match( '/^\s*([\w:-]+)\s*(?:([*^$]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+)))?\s*$/', (string) ( $part[2] ?? '' ), $attr ) ) {
				return null;
			}
			if ( ! isset( $attr[2] ) || '' === $attr[2] ) {
				$conditions[] = '@' . $attr[1];
				continue;
			}
			$value = '' !== ( $attr[3] ?? '' ) ? $attr[3] : ( '' !== ( $attr[4] ?? '' ) ? $attr[4] : (string) ( $attr[5] ?? '' ) );
			$quote = self::quote( $value );
			if ( '=' === $attr[2] ) {
				$conditions[] = '@' . $attr[1] . '=' . $quote;
			} elseif ( '*=' === $attr[2] ) {
				$conditions[] = 'contains(@' . $attr[1] . ',' . $quote . ')';
			} elseif ( '^=' === $attr[2] ) {
				$conditions[] = 'starts-with(@' . $attr[1] . ',' . $quote . ')';
			} else {
				$conditions[] = 'substring(@' . $attr[1] . ',string-length(@' . $attr[1] . ')-' . ( strlen( $value ) - 1 ) . ')=' . $quote;
			}
		}

		return $tag . ( $conditions ? '[' . implode( ' and ', $conditions ) . ']' : '' );
	}

	/**
	 * An XPath string literal.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function quote( string $value ): string {
		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}
		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}

		return 'concat("' . str_replace( '"', '", \'"\', "', $value ) . '")';
	}

	/**
	 * How many nodes a query matches.
	 *
	 * @param \DOMXPath     $xpath   XPath.
	 * @param string        $query   Query.
	 * @param \DOMNode|null $context Context node.
	 * @return int
	 */
	private static function count( \DOMXPath $xpath, string $query, ?\DOMNode $context = null ): int {
		$nodes = $context ? $xpath->query( $query, $context ) : $xpath->query( $query );

		return $nodes ? $nodes->length : 0;
	}

	/**
	 * The nodes a query matches.
	 *
	 * @param \DOMXPath $xpath XPath.
	 * @param string    $query Query.
	 * @return \DOMNode[]
	 */
	private static function nodes( \DOMXPath $xpath, string $query ): array {
		$nodes = $xpath->query( $query );

		return $nodes ? iterator_to_array( $nodes ) : array();
	}

	/**
	 * A node's text with the white space tidied.
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private static function text( \DOMNode $node ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property name.
	}
}
