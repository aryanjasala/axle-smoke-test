<?php
/**
 * The coverage set: one real page for every block type, shortcode and
 * template the site uses.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Scans published content in WP-Cron batches, remembers the newest published
 * item for each block type, shortcode and template, and hands those pages to
 * a run after the key URLs.
 */
final class Coverage implements Service {

	public const OPTION = 'axle_smoke_test_coverage';
	public const HOOK   = 'axle_smoke_test_coverage_scan';
	public const PREFIX = 'cov-';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the coverage set.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks the scan and the events that change a picked page.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'scan_batch' ) );
		add_action( 'init', array( $this, 'maybe_start' ) );
		add_action( Schema::DAILY_HOOK, array( $this, 'maybe_start' ), 20 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'on_gone' ) );
		add_action( 'trashed_post', array( $this, 'on_gone' ) );
		add_action( 'axle_smoke_test_settings_saved', array( $this, 'maybe_start' ) );
	}

	/**
	 * Whether the coverage check is switched on.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'coverage' );
	}

	/**
	 * The stored state.
	 *
	 * @return array picks, scanned, scan.
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'picks'   => isset( $state['picks'] ) && is_array( $state['picks'] ) ? $state['picks'] : array(),
			'scanned' => (int) ( $state['scanned'] ?? 0 ),
			'scan'    => isset( $state['scan'] ) && is_array( $state['scan'] ) ? $state['scan'] : null,
		);
	}

	/**
	 * Saves the state.
	 *
	 * @param array $state State.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Post types the scan reads.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array_values( array_diff( (array) get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

		/**
		 * Filters the post types the coverage scan reads.
		 *
		 * @param string[] $types Default: every public post type except attachments.
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_coverage_post_types', $types );

		return array_values( array_filter( array_map( 'sanitize_key', array_filter( $filtered, 'is_string' ) ) ) );
	}

	/**
	 * Posts read per batch.
	 *
	 * @return int
	 */
	public static function batch_size(): int {
		/**
		 * Filters how many posts one scan batch reads.
		 *
		 * @param int $size Default 100. Clamped to 10 to 1000.
		 */
		return Settings::filtered_int( 'coverage_batch', 100, 10, 1000 );
	}

	/**
	 * Most pages a run checks from the coverage set.
	 *
	 * @return int
	 */
	public static function max_pages(): int {
		/**
		 * Filters the most pages a run checks from the coverage set.
		 *
		 * @param int $max Default 60. Clamped to 1 to 500.
		 */
		return Settings::filtered_int( 'coverage_max_pages', 60, 1, 500 );
	}

	/**
	 * Seconds a run may spend on coverage pages, after the key URLs.
	 *
	 * @return int
	 */
	public static function budget(): int {
		/**
		 * Filters the time a run may spend on coverage pages. Pages left over are
		 * skipped, and the run says how many.
		 *
		 * @param int $seconds Default 90. Clamped to 5 to 900.
		 */
		return Settings::filtered_int( 'coverage_budget', 90, 5, 900 );
	}

	/**
	 * What one post uses.
	 *
	 * @param string $content         Post content.
	 * @param string $post_type       Post type.
	 * @param string $template        The post's template meta, empty for the default.
	 * @param string $shortcode_regex Core's shortcode regex for the registered tags, empty for none.
	 * @return string[] Keys: block:ns/name, shortcode:tag, template:slug.
	 */
	public static function scan_content( string $content, string $post_type, string $template, string $shortcode_regex ): array {
		$keys = array();

		if ( false !== strpos( $content, '<!-- wp:' ) || false !== strpos( $content, '<!--wp:' ) ) {
			if ( preg_match_all( '/<!--\s*wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(?=[\s\/}-])/', $content, $found ) ) {
				foreach ( $found[1] as $name ) {
					$name                     = false === strpos( $name, '/' ) ? 'core/' . $name : $name;
					$keys[ 'block:' . $name ] = true;
				}
			}
		}

		if ( '' !== $shortcode_regex && false !== strpos( $content, '[' ) && preg_match_all( '/' . $shortcode_regex . '/', $content, $found ) ) {
			foreach ( $found[2] as $tag ) {
				if ( '' !== $tag ) {
					$keys[ 'shortcode:' . $tag ] = true;
				}
			}
		}

		$template                        = sanitize_text_field( $template );
		$template                        = '' === $template || 'default' === $template ? 'single-' . $post_type : $template;
		$keys[ 'template:' . $template ] = true;

		return array_keys( $keys );
	}

	/**
	 * Core's regex for the registered shortcodes.
	 *
	 * @return string Empty when none are registered.
	 */
	private static function shortcode_regex(): string {
		global $shortcode_tags;
		$tags = is_array( $shortcode_tags ) ? array_keys( $shortcode_tags ) : array();

		return $tags && function_exists( 'get_shortcode_regex' ) ? get_shortcode_regex( array_map( 'strval', $tags ) ) : '';
	}

	/**
	 * Whether a pick should be replaced by a candidate: the newer post wins.
	 *
	 * @param array|null $pick      The current pick: post, date.
	 * @param int        $post_id   Candidate.
	 * @param string     $date      Candidate's GMT date.
	 * @return bool
	 */
	public static function newer( ?array $pick, int $post_id, string $date ): bool {
		if ( ! $pick ) {
			return true;
		}
		$old = (string) ( $pick['date'] ?? '' );

		return $date > $old || ( $date === $old && $post_id > (int) $pick['post'] );
	}

	/**
	 * Adds what one post uses to a set of picks.
	 *
	 * @param array    $picks   Picks by key, changed in place.
	 * @param int      $post_id Post.
	 * @param string   $date    Its GMT date.
	 * @param string[] $keys    What it uses.
	 */
	public static function merge( array &$picks, int $post_id, string $date, array $keys ): void {
		$excluded = self::excluded_keys();
		foreach ( $keys as $key ) {
			if ( in_array( $key, $excluded, true ) ) {
				continue;
			}
			if ( self::newer( $picks[ $key ] ?? null, $post_id, $date ) ) {
				$picks[ $key ] = array(
					'post' => $post_id,
					'date' => $date,
				);
			}
		}
	}

	/**
	 * Keys that are never checked.
	 *
	 * @return string[]
	 */
	public static function excluded_keys(): array {
		/**
		 * Filters what the coverage set leaves out. Use the keys the card lists:
		 * block:core/gallery, shortcode:gallery, template:single-post.
		 *
		 * @param string[] $keys Default: none.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'axle_smoke_test_coverage_excluded', array() ) ) ) );
	}

	/**
	 * Whether a post may be picked: not excluded by the filter.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	private static function post_allowed( int $post_id ): bool {
		/**
		 * Filters the posts the coverage set must never pick, for example a page
		 * with a side effect on view.
		 *
		 * @param int[] $ids Default: none.
		 */
		$ids = array_map( 'intval', (array) apply_filters( 'axle_smoke_test_coverage_excluded_posts', array() ) );

		return ! in_array( $post_id, $ids, true );
	}

	/**
	 * Starts a scan when there is none, the last is a day old or the setting just turned on.
	 */
	public function maybe_start(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		$state = self::state();
		if ( $state['scan'] ) {
			return;
		}
		if ( $state['scanned'] > 0 && time() - $state['scanned'] < DAY_IN_SECONDS ) {
			return;
		}
		$this->start();
	}

	/**
	 * Starts a full scan: the current picks stay in force until it finishes.
	 */
	public function start(): void {
		$state         = self::state();
		$state['scan'] = array(
			'cursor' => PHP_INT_MAX,
			'picks'  => array(),
			'posts'  => 0,
		);
		self::save( $state );

		/**
		 * Fires when a coverage scan starts.
		 */
		do_action( 'axle_smoke_test_coverage_scan_started' );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/**
	 * Reads one batch of posts. Schedules the next one until the scan is done.
	 */
	public function scan_batch(): void {
		global $wpdb;

		$state = self::state();
		if ( ! $state['scan'] ) {
			return;
		}
		$scan  = $state['scan'];
		$types = self::post_types();
		if ( ! $types ) {
			$types = array( 'post', 'page' );
		}

		$size         = self::batch_size();
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A read of published content in batches; the placeholders list is built from a fixed count.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_content, post_date_gmt FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND ID < %d AND post_type IN ({$placeholders}) ORDER BY ID DESC LIMIT %d", array_merge( array( (int) $scan['cursor'] ), $types, array( $size ) ) ), ARRAY_A );

		if ( $rows ) {
			update_meta_cache( 'post', array_map( 'intval', wp_list_pluck( $rows, 'ID' ) ) );
		}
		$regex = self::shortcode_regex();
		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			if ( ! self::post_allowed( $id ) ) {
				continue;
			}
			$keys = self::scan_content( (string) $row['post_content'], (string) $row['post_type'], (string) get_post_meta( $id, '_wp_page_template', true ), $regex );
			self::merge( $scan['picks'], $id, (string) $row['post_date_gmt'], $keys );
			$scan['cursor'] = $id;
		}
		$scan['posts'] = (int) $scan['posts'] + count( $rows );

		if ( count( $rows ) < $size ) {
			$state['picks']   = $scan['picks'];
			$state['scanned'] = time();
			$state['scan']    = null;
			self::save( $state );

			/**
			 * Fires when a coverage scan is done.
			 *
			 * @param array $summary pages, blocks, shortcodes, templates, posts.
			 */
			do_action( 'axle_smoke_test_coverage_scanned', $this->summary() + array( 'posts' => (int) $scan['posts'] ) );
			return;
		}

		$state['scan'] = $scan;
		self::save( $state );
		wp_schedule_single_event( time() + 5, self::HOOK );
	}

	/**
	 * A post changed state: new content may add a block type, and a picked post may be gone.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param mixed  $post       The WP_Post.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || ! $this->enabled() ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}
		$state = self::state();
		$used  = false;
		foreach ( $state['picks'] as $pick ) {
			if ( (int) $pick['post'] === (int) $post->ID ) {
				$used = true;
				break;
			}
		}
		if ( $used ) {
			// The page that stood for something changed or went: find the stand-ins again.
			$this->restart_soon();
			return;
		}
		if ( 'publish' === $new_status && '' === $post->post_password && self::post_allowed( (int) $post->ID ) ) {
			$keys = self::scan_content( (string) $post->post_content, (string) $post->post_type, (string) get_post_meta( $post->ID, '_wp_page_template', true ), self::shortcode_regex() );
			self::merge( $state['picks'], (int) $post->ID, (string) $post->post_date_gmt, $keys );
			if ( $state['scan'] ) {
				self::merge( $state['scan']['picks'], (int) $post->ID, (string) $post->post_date_gmt, $keys );
			}
			self::save( $state );
		}
	}

	/**
	 * A post was trashed or deleted.
	 *
	 * @param int $post_id Post.
	 */
	public function on_gone( $post_id ): void {
		if ( ! $this->enabled() ) {
			return;
		}
		foreach ( self::state()['picks'] as $pick ) {
			if ( (int) $pick['post'] === (int) $post_id ) {
				$this->restart_soon();
				return;
			}
		}
	}

	/**
	 * Re-scans in a minute, once for any number of changes.
	 */
	private function restart_soon(): void {
		$state = self::state();
		if ( $state['scan'] ) {
			return;
		}
		$state['scan'] = array(
			'cursor' => PHP_INT_MAX,
			'picks'  => array(),
			'posts'  => 0,
		);
		self::save( $state );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Counts for the card: pages and what they cover.
	 *
	 * @return array pages, blocks, shortcodes, templates, scanned (Unix time), scanning (bool).
	 */
	public function summary(): array {
		$state = self::state();
		$posts = array();
		$count = array(
			'block'     => 0,
			'shortcode' => 0,
			'template'  => 0,
		);
		foreach ( $state['picks'] as $key => $pick ) {
			$posts[ (int) $pick['post'] ] = true;
			$type                         = strstr( (string) $key, ':', true );
			if ( isset( $count[ $type ] ) ) {
				++$count[ $type ];
			}
		}

		return array(
			'pages'      => min( count( $posts ), self::max_pages() ),
			'blocks'     => $count['block'],
			'shortcodes' => $count['shortcode'],
			'templates'  => $count['template'],
			'scanned'    => $state['scanned'],
			'scanning'   => (bool) $state['scan'],
		);
	}

	/**
	 * The pages a run checks from the coverage set, as URL entries.
	 *
	 * Posts are taken in the order of how much they cover, so a cut at the
	 * maximum drops the pages that add the least.
	 *
	 * @param string[] $skip_urls Absolute URLs the key URLs already cover.
	 * @return array[] Entries for Checker::check(): key, label, url, expected, browser, auth, kind, source, rules, covers.
	 */
	public function entries( array $skip_urls = array() ): array {
		$by_post = array();
		foreach ( self::state()['picks'] as $key => $pick ) {
			$by_post[ (int) $pick['post'] ][] = (string) $key;
		}
		uasort(
			$by_post,
			static function ( $a, $b ) {
				return count( $b ) <=> count( $a );
			}
		);

		$entries = array();
		foreach ( $by_post as $post_id => $keys ) {
			if ( count( $entries ) >= self::max_pages() ) {
				break;
			}
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				continue;
			}
			$url = (string) get_permalink( $post );
			if ( '' === $url || in_array( $url, $skip_urls, true ) ) {
				continue;
			}
			$entry = Urls::normalise(
				array(
					'key'     => self::PREFIX . $post_id,
					'label'   => get_the_title( $post ),
					'url'     => $url,
					'browser' => false,
				)
			);
			if ( ! $entry ) {
				continue;
			}
			$entry['source'] = 'coverage';
			$entry['covers'] = $keys;
			$entries[]       = $entry;
		}

		/**
		 * Filters the coverage pages a run checks.
		 *
		 * @param array[] $entries URL entries, each with a covers list.
		 */
		return array_values( (array) apply_filters( 'axle_smoke_test_coverage_entries', $entries ) );
	}

	/**
	 * Whether a run also checks the coverage set.
	 *
	 * @param array $run The run.
	 * @return bool
	 */
	public function wanted( array $run ): bool {
		$context = (array) ( $run['context'] ?? array() );
		if ( ! $this->enabled() || ! empty( $context['urls'] ) || false === ( $context['coverage'] ?? true ) ) {
			return false;
		}

		/**
		 * Filters whether a run also checks the coverage set.
		 *
		 * @param bool  $wanted Default true for a full run, false for a run of chosen URLs.
		 * @param array $run    The run.
		 */
		return (bool) apply_filters( 'axle_smoke_test_coverage_in_run', true, $run );
	}

	/**
	 * Whether a URL key belongs to the coverage set.
	 *
	 * @param string $key URL key.
	 * @return bool
	 */
	public static function is_coverage_key( string $key ): bool {
		return 0 === strpos( $key, self::PREFIX );
	}
}
