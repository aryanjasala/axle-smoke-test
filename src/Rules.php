<?php
/**
 * The rules a person adds to a key URL, and the findings they have accepted.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * "Must contain" and "must not contain" rules per key URL, kept apart from
 * the URL list so they work for pages picked from the site too, and the
 * findings of the structure check a person accepted per URL.
 */
final class Rules {

	public const OPTION   = 'axle_smoke_test_rules';
	public const ACCEPTED = 'axle_smoke_test_accepted';

	/**
	 * Most rules of each kind per URL.
	 *
	 * @return int
	 */
	public static function max(): int {
		/**
		 * Filters the most "must contain" rules, and the most "must not contain" rules, one URL may have.
		 *
		 * @param int $max Default 10. Clamped to 1 to 50.
		 */
		return Settings::filtered_int( 'max_rules', 10, 1, 50 );
	}

	/**
	 * Cleans a rule set: trimmed, no empty or repeated lines, capped.
	 *
	 * @param mixed $rules Array with must and must_not, each a list or a text with one rule per line.
	 * @return array{must: string[], must_not: string[]}
	 */
	public static function clean( $rules ): array {
		$rules = is_array( $rules ) ? $rules : array();
		$out   = array(
			'must'     => array(),
			'must_not' => array(),
		);
		foreach ( array_keys( $out ) as $kind ) {
			$list = $rules[ $kind ] ?? array();
			$list = is_array( $list ) ? $list : preg_split( '/\r\n|\r|\n/', (string) $list );
			foreach ( (array) $list as $rule ) {
				$rule = substr( trim( sanitize_text_field( (string) $rule ) ), 0, 300 );
				if ( '' !== $rule ) {
					$out[ $kind ][] = $rule;
				}
			}
			$out[ $kind ] = array_slice( array_values( array_unique( $out[ $kind ] ) ), 0, self::max() );
		}

		return $out;
	}

	/**
	 * Two rule sets as one.
	 *
	 * @param array $a Rules.
	 * @param array $b Rules.
	 * @return array{must: string[], must_not: string[]}
	 */
	public static function merge( array $a, array $b ): array {
		$a = self::clean( $a );
		$b = self::clean( $b );

		return array(
			'must'     => array_values( array_unique( array_merge( $a['must'], $b['must'] ) ) ),
			'must_not' => array_values( array_unique( array_merge( $a['must_not'], $b['must_not'] ) ) ),
		);
	}

	/**
	 * Every saved rule set, by URL key.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );

		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * The rules saved for one URL.
	 *
	 * @param string $key URL key.
	 * @return array{must: string[], must_not: string[]}
	 */
	public static function saved( string $key ): array {
		return self::clean( self::all()[ $key ] ?? array() );
	}

	/**
	 * Saves the rules of one URL. Empty rules remove the entry.
	 *
	 * @param string $key   URL key.
	 * @param array  $rules Rules.
	 * @return array The rules as saved.
	 */
	public static function save( string $key, array $rules ): array {
		$key   = sanitize_key( $key );
		$rules = self::clean( $rules );
		$all   = self::all();
		if ( $rules['must'] || $rules['must_not'] ) {
			$all[ $key ] = $rules;
		} else {
			unset( $all[ $key ] );
		}
		update_option( self::OPTION, array_slice( $all, 0, 500, true ), false );

		/**
		 * Fires after the rules of a key URL were saved.
		 *
		 * @param string $key   URL key.
		 * @param array  $rules must and must_not.
		 */
		do_action( 'axle_smoke_test_rules_saved', $key, $rules );

		return $rules;
	}

	/**
	 * Forgets everything kept for a URL that was removed.
	 *
	 * @param string $key URL key.
	 */
	public static function forget( string $key ): void {
		$all = self::all();
		if ( isset( $all[ $key ] ) ) {
			unset( $all[ $key ] );
			update_option( self::OPTION, $all, false );
		}
		self::clear_accepted( $key );
	}

	/**
	 * The findings accepted for a URL, by signature.
	 *
	 * @param string $key URL key.
	 * @return string[]
	 */
	public static function accepted( string $key ): array {
		$all = get_option( self::ACCEPTED, array() );

		return is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? array_values( array_map( 'strval', $all[ $key ] ) ) : array();
	}

	/**
	 * Accepts a finding of a URL: later runs stop reporting it.
	 *
	 * @param string $key URL key.
	 * @param string $sig Finding signature.
	 */
	public static function accept( string $key, string $sig ): void {
		$all = get_option( self::ACCEPTED, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ $key ] = array_slice( array_values( array_unique( array_merge( self::accepted( $key ), array( $sig ) ) ) ), -50 );
		update_option( self::ACCEPTED, array_slice( $all, -300, null, true ), false );
	}

	/**
	 * Takes an accepted finding back.
	 *
	 * @param string $key URL key.
	 * @param string $sig Finding signature.
	 */
	public static function unaccept( string $key, string $sig ): void {
		$all = get_option( self::ACCEPTED, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $key ] ) ) {
			return;
		}
		$all[ $key ] = array_values( array_diff( (array) $all[ $key ], array( $sig ) ) );
		if ( ! $all[ $key ] ) {
			unset( $all[ $key ] );
		}
		update_option( self::ACCEPTED, $all, false );
	}

	/**
	 * Forgets the accepted findings of a URL, for example once its baseline is new.
	 *
	 * @param string $key URL key.
	 */
	public static function clear_accepted( string $key ): void {
		$all = get_option( self::ACCEPTED, array() );
		if ( is_array( $all ) && isset( $all[ $key ] ) ) {
			unset( $all[ $key ] );
			update_option( self::ACCEPTED, $all, false );
		}
	}
}
