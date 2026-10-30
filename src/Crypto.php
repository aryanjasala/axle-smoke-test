<?php
/**
 * Encrypts the secrets in the settings.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts webhook URLs and basic-auth passwords at rest with a key derived
 * from the site's salts, using libsodium (core ships a polyfill, so it is
 * always there). Changing the salts makes old values unreadable: the settings
 * screen then asks for them again.
 */
final class Crypto {

	private const PREFIX = 'ast1:';

	/**
	 * Encrypts a value. An empty value stays empty.
	 *
	 * @param string $plain Plain text.
	 * @return string Prefixed, base64 encoded nonce and cipher text.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary cipher text stored in an option.
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypts a value from encrypt(). Anything else comes back empty.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( string $stored ): string {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary cipher text stored in an option.
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( \Throwable $error ) {
			return '';
		}

		return false === $plain ? '' : (string) $plain;
	}

	/**
	 * Whether a stored value is an encrypted one.
	 *
	 * @param string $stored Stored value.
	 * @return bool
	 */
	public static function is_encrypted( string $stored ): bool {
		return 0 === strpos( $stored, self::PREFIX );
	}

	/**
	 * A masked version for the screen: the last four characters only.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function mask( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		return str_repeat( '*', 8 ) . substr( $plain, -4 );
	}

	/**
	 * The 32-byte key.
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|axle-smoke-test', true );
	}
}
