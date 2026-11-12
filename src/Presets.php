<?php
/**
 * Key pages worth checking on shops and multilingual sites.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Knows the pages a shop and a multilingual site depend on: the shop,
 * a product that is in stock, the cart, the checkout and the account page
 * (with rules that say what each one must render), and the home page, the
 * shop pages of each language. Pages are only rendered: nothing is submitted,
 * added to a cart or ordered.
 *
 * The shop pages are picked by themselves while the WooCommerce box is on.
 * The rest are offered as suggestions, and a person adds them with one click.
 */
final class Presets {

	/**
	 * The WooCommerce pages, with the rules each one must meet.
	 *
	 * @return array[] Each: key, label, url, rules and sometimes expected.
	 */
	public static function shop(): array {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return array();
		}

		$pages   = array();
		$pages[] = array(
			'key'   => 'shop',
			'label' => __( 'Shop', 'axle-smoke-test' ),
			'url'   => (string) wc_get_page_permalink( 'shop' ),
			'rules' => array( 'must_not' => array( 'text:[woocommerce_shop_page]', 'text:[products' ) ),
		);

		$product = self::product();
		if ( $product ) {
			$pages[] = array(
				'key'   => 'latest-product',
				'label' => __( 'Product in stock', 'axle-smoke-test' ),
				'url'   => (string) get_permalink( $product ),
				'rules' => array(
					'must'     => array( '.single_add_to_cart_button, .wp-block-woocommerce-add-to-cart-form, .add_to_cart_button' ),
					'must_not' => array( 'text:[add_to_cart' ),
				),
			);
		}

		$pages[] = array(
			'key'   => 'cart',
			'label' => __( 'Cart', 'axle-smoke-test' ),
			'url'   => (string) wc_get_page_permalink( 'cart' ),
			'rules' => array(
				'must'     => array( '.woocommerce-cart-form, .wp-block-woocommerce-cart, .wc-block-cart, .cart-empty, .woocommerce' ),
				'must_not' => array( 'text:[woocommerce_cart]' ),
			),
		);
		// With an empty cart, checkout either sends the visitor to the cart or says the cart is empty.
		$pages[] = array(
			'key'      => 'checkout',
			'label'    => __( 'Checkout (empty cart)', 'axle-smoke-test' ),
			'url'      => (string) wc_get_page_permalink( 'checkout' ),
			'expected' => array( 200, 302 ),
			'rules'    => array(
				'must'     => array( '.woocommerce, .wp-block-woocommerce-checkout, .wc-block-checkout' ),
				'must_not' => array( 'text:[woocommerce_checkout]' ),
			),
		);
		$pages[] = array(
			'key'   => 'my-account',
			'label' => __( 'My account', 'axle-smoke-test' ),
			'url'   => (string) wc_get_page_permalink( 'myaccount' ),
			'rules' => array(
				'must'     => array( '.woocommerce' ),
				'must_not' => array( 'text:[woocommerce_my_account]' ),
			),
		);

		/**
		 * Filters the WooCommerce pages the smoke test checks, with their rules.
		 *
		 * @param array[] $pages Each: key, label, url, rules (must and must_not) and optionally expected (int[]).
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_shop_pages', $pages );
		$out      = array();
		foreach ( $filtered as $page ) {
			if ( ! empty( $page['key'] ) && ! empty( $page['url'] ) ) {
				$out[] = $page;
			}
		}

		return $out;
	}

	/**
	 * The ID of a published simple product that is in stock, or 0.
	 *
	 * @return int
	 */
	private static function product(): int {
		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One product, once per list build.
				array(
					'key'   => '_stock_status',
					'value' => 'instock',
				),
			),
		);
		$ids  = ( new \WP_Query( $args ) )->posts;

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The languages of the site, from WPML, Polylang or TranslatePress.
	 *
	 * @return array[] Each: code, label, home (URL), default (bool).
	 */
	public static function languages(): array {
		$languages = array();

		if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_active_languages' ) ) {
			$active  = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			$default = (string) apply_filters( 'wpml_default_language', '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			foreach ( is_array( $active ) ? $active : array() as $code => $language ) {
				$languages[] = array(
					'code'    => (string) $code,
					'label'   => (string) ( $language['native_name'] ?? $code ),
					'home'    => (string) apply_filters( 'wpml_home_url', home_url( '/' ), (string) $code ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
					'default' => (string) $code === $default,
					'plugin'  => 'wpml',
				);
			}
		} elseif ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_home_url' ) ) {
			$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
			$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$names   = (array) pll_languages_list( array( 'fields' => 'name' ) );
			foreach ( $slugs as $i => $slug ) {
				$languages[] = array(
					'code'    => (string) $slug,
					'label'   => (string) ( $names[ $i ] ?? $slug ),
					'home'    => (string) pll_home_url( (string) $slug ),
					'default' => (string) $slug === $default,
					'plugin'  => 'polylang',
				);
			}
		} elseif ( defined( 'TRP_PLUGIN_VERSION' ) ) {
			$settings = get_option( 'trp_settings', array() );
			$settings = is_array( $settings ) ? $settings : array();
			$default  = (string) ( $settings['default-language'] ?? '' );
			$slugs    = (array) ( $settings['url-slugs'] ?? array() );
			foreach ( (array) ( $settings['publish-languages'] ?? array() ) as $code ) {
				$slug        = (string) ( $slugs[ $code ] ?? '' );
				$languages[] = array(
					'code'    => (string) $code,
					'label'   => '' !== $slug ? strtoupper( $slug ) : (string) $code,
					'home'    => (string) $code === $default || '' === $slug ? home_url( '/' ) : home_url( '/' . $slug . '/' ),
					'default' => (string) $code === $default,
					'plugin'  => 'translatepress',
				);
			}
		}

		/**
		 * Filters the languages the smoke test knows about.
		 *
		 * @param array[] $languages Each: code, label, home (the language's home page URL), default (bool).
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_languages', $languages );
		$out      = array();
		foreach ( $filtered as $language ) {
			if ( ! empty( $language['code'] ) && ! empty( $language['home'] ) ) {
				$out[] = $language + array(
					'label'   => (string) $language['code'],
					'default' => false,
				);
			}
		}

		return count( $out ) > 1 ? $out : array();
	}

	/**
	 * The permalink of a page in another language, or empty.
	 *
	 * @param int   $page_id  Page in the default language.
	 * @param array $language One of languages().
	 * @return string
	 */
	private static function translated( int $page_id, array $language ): string {
		$id = 0;
		if ( 'wpml' === ( $language['plugin'] ?? '' ) ) {
			$id = (int) apply_filters( 'wpml_object_id', $page_id, 'page', false, (string) $language['code'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
		} elseif ( 'polylang' === ( $language['plugin'] ?? '' ) && function_exists( 'pll_get_post' ) ) {
			$id = (int) pll_get_post( $page_id, (string) $language['code'] );
		}

		return $id && $id !== $page_id ? (string) get_permalink( $id ) : '';
	}

	/**
	 * Pages to suggest: those a site needs that are not on the list yet.
	 *
	 * @param Urls $urls The key URLs.
	 * @return array[] Each: key, label, url, rules, group (shop or language), reason.
	 */
	public static function suggestions( Urls $urls ): array {
		$have = array();
		foreach ( $urls->all() as $url ) {
			$have[ untrailingslashit( preg_replace( '/#.*$/', '', (string) $url['url'] ) ) ] = true;
		}

		$found = array();
		$add   = static function ( string $key, string $label, string $url, array $rules, string $group, string $reason, array $extra = array() ) use ( &$found, $have ): void {
			$absolute = Urls::absolute( $url );
			if ( '' === $absolute || isset( $have[ untrailingslashit( preg_replace( '/#.*$/', '', $absolute ) ) ] ) ) {
				return;
			}
			$found[ $key ] = array_merge(
				array(
					'key'    => $key,
					'label'  => $label,
					'url'    => $absolute,
					'rules'  => $rules,
					'group'  => $group,
					'reason' => $reason,
				),
				$extra
			);
		};

		$shop = array();
		foreach ( self::shop() as $page ) {
			$shop[ $page['key'] ] = $page;
			/* translators: %s: page name, like Cart. */
			$add( 'preset-' . $page['key'], (string) $page['label'], (string) $page['url'], (array) ( $page['rules'] ?? array() ), 'shop', sprintf( __( 'A shop depends on its %s page.', 'axle-smoke-test' ), strtolower( (string) $page['label'] ) ), isset( $page['expected'] ) ? array( 'expected' => (array) $page['expected'] ) : array() );
		}

		$header_footer = array( 'must' => array( 'header', 'footer' ) );
		foreach ( self::languages() as $language ) {
			if ( ! $language['default'] ) {
				/* translators: %s: language name. */
				$add( 'preset-home-' . sanitize_key( (string) $language['code'] ), sprintf( __( 'Home page (%s)', 'axle-smoke-test' ), $language['label'] ), (string) $language['home'], $header_footer, 'language', __( 'Each language gets its own check of the header and footer.', 'axle-smoke-test' ) );
			}
			if ( $language['default'] || ! function_exists( 'wc_get_page_id' ) || ! $shop ) {
				continue;
			}
			foreach ( array(
				'shop'     => 'shop',
				'cart'     => 'cart',
				'checkout' => 'checkout',
			) as $page_key => $wc_page ) {
				$url = self::translated( (int) wc_get_page_id( $wc_page ), $language );
				if ( '' !== $url && isset( $shop[ $page_key ] ) ) {
					/* translators: 1: page name, 2: language name. */
					$add( 'preset-' . $page_key . '-' . sanitize_key( (string) $language['code'] ), sprintf( __( '%1$s (%2$s)', 'axle-smoke-test' ), $shop[ $page_key ]['label'], $language['label'] ), $url, (array) ( $shop[ $page_key ]['rules'] ?? array() ), 'language', __( 'A change can break the checkout in one language only.', 'axle-smoke-test' ), isset( $shop[ $page_key ]['expected'] ) ? array( 'expected' => (array) $shop[ $page_key ]['expected'] ) : array() );
				}
			}
		}

		/**
		 * Filters the pages the smoke test suggests adding.
		 *
		 * @param array[] $suggestions Each: key, label, url, rules (must and must_not), group, reason and optionally expected.
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_suggestions', array_values( $found ) );
		$out      = array();
		foreach ( $filtered as $suggestion ) {
			if ( ! empty( $suggestion['key'] ) && ! empty( $suggestion['url'] ) ) {
				$out[] = $suggestion + array(
					'label'  => (string) $suggestion['url'],
					'rules'  => array(),
					'group'  => 'shop',
					'reason' => '',
				);
			}
		}

		return $out;
	}

	/**
	 * Adds suggested pages to the key URLs, with their rules.
	 *
	 * @param Urls     $urls Key URLs.
	 * @param string[] $keys Suggestion keys. Empty for all.
	 * @return array|\WP_Error The URLs added.
	 */
	public static function add( Urls $urls, array $keys = array() ) {
		$added = array();
		foreach ( self::suggestions( $urls ) as $suggestion ) {
			if ( $keys && ! in_array( $suggestion['key'], $keys, true ) ) {
				continue;
			}
			$item = $urls->add( (string) $suggestion['url'], Urls::statuses( $suggestion['expected'] ?? array( 200 ) ), true, (string) $suggestion['label'], (array) $suggestion['rules'] );
			if ( is_wp_error( $item ) ) {
				if ( $added ) {
					break;
				}
				return $item;
			}
			$added[] = $item;
		}

		/**
		 * Fires after suggested pages were added to the key URLs.
		 *
		 * @param array[] $added The URL entries.
		 */
		do_action( 'axle_smoke_test_suggestions_added', $added );

		return $added;
	}
}
