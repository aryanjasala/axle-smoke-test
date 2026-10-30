<?php
/**
 * Saved settings.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and saves the settings, with every number clamped, every value checked
 * and the secrets encrypted, and applies the filters that let code change them.
 */
final class Settings {

	public const OPTION = 'axle_smoke_test_settings';

	/**
	 * Events that can start a run on their own. Manual, WP-CLI, REST and
	 * scheduled runs always run.
	 */
	public const TRIGGERS = array( 'update', 'install', 'theme', 'deploy' );

	public const ALERT_LEVELS = array( 'red', 'amber', 'never' );

	/**
	 * Settings for this request, after sanitising.
	 *
	 * @var array|null
	 */
	private ?array $cache = null;

	/**
	 * The defaults.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'browser_tier'      => true,
			'auto_pick'         => array_fill_keys( Urls::AUTO_TYPES, true ),
			'layout_threshold'  => 40,
			'text_threshold'    => 60,
			'blank_bytes'       => 40,
			'alert_on'          => 'red',
			'email'             => true,
			'emails'            => '',
			'webhook_url'       => '',
			'webhook_format'    => 'json',
			'notice'            => true,
			'auto_rollback'     => false,
			'cron_user'         => 0,
			'cron_admin_checks' => false,
			'auth_user'         => '',
			'auth_pass'         => '',
			'retention_days'    => 30,
			'retention_runs'    => 200,
			'daily'             => false,
			'triggers'          => self::TRIGGERS,
		);
	}

	/**
	 * Every setting, sanitised. Secrets stay encrypted; use secret() for them.
	 *
	 * @return array
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$this->cache = self::sanitize( get_option( self::OPTION, array() ) );
		}

		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * A decrypted secret: webhook_url or auth_pass.
	 *
	 * @param string $key Setting name.
	 * @return string
	 */
	public function secret( string $key ): string {
		return Crypto::decrypt( (string) $this->get( $key ) );
	}

	/**
	 * Saves changed settings. Secrets come in as plain text and are encrypted
	 * here; an empty secret from a form keeps the saved one, unless the matching
	 * "clear_" key is set.
	 *
	 * @param array $changes Settings to change.
	 * @return array The saved settings.
	 */
	public function update( array $changes ): array {
		$old = $this->all();

		foreach ( array( 'webhook_url', 'auth_pass' ) as $secret ) {
			if ( ! empty( $changes[ 'clear_' . $secret ] ) ) {
				$changes[ $secret ] = '';
			} elseif ( ! isset( $changes[ $secret ] ) || '' === $changes[ $secret ] ) {
				$changes[ $secret ] = $old[ $secret ];
			} elseif ( ! Crypto::is_encrypted( (string) $changes[ $secret ] ) ) {
				$plain              = 'webhook_url' === $secret ? esc_url_raw( trim( (string) $changes[ $secret ] ), array( 'http', 'https' ) ) : (string) $changes[ $secret ];
				$changes[ $secret ] = Crypto::encrypt( $plain );
			}
			unset( $changes[ 'clear_' . $secret ] );
		}

		$settings = self::sanitize( array_replace( $old, $changes ) );
		update_option( self::OPTION, $settings, false );
		$this->cache = $settings;

		/**
		 * Fires after the settings were saved, from the screen, WP-CLI or code.
		 *
		 * @param array $settings New settings (secrets encrypted).
		 * @param array $old      Previous settings.
		 */
		do_action( 'axle_smoke_test_settings_saved', $settings, $old );

		return $settings;
	}

	/**
	 * Forgets the settings read in this request.
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Cleans up stored or submitted settings, falling back to the default for
	 * anything invalid.
	 *
	 * @param mixed $value Stored or submitted settings.
	 * @return array
	 */
	public static function sanitize( $value ): array {
		$defaults = self::defaults();
		$value    = is_array( $value ) ? $value : array();
		$int      = static function ( string $key, int $min, int $max ) use ( $value, $defaults ): int {
			return isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ? max( $min, min( $max, (int) $value[ $key ] ) ) : $defaults[ $key ];
		};
		$bool     = static function ( string $key ) use ( $value, $defaults ): bool {
			return array_key_exists( $key, $value ) ? (bool) $value[ $key ] : $defaults[ $key ];
		};

		$auto_pick = $defaults['auto_pick'];
		if ( isset( $value['auto_pick'] ) && is_array( $value['auto_pick'] ) ) {
			foreach ( $auto_pick as $type => $on ) {
				$auto_pick[ $type ] = ! empty( $value['auto_pick'][ $type ] );
			}
		}

		$triggers = isset( $value['triggers'] ) && is_array( $value['triggers'] ) ? array_values( array_intersect( self::TRIGGERS, $value['triggers'] ) ) : $defaults['triggers'];

		$secret = static function ( string $key ) use ( $value ): string {
			$stored = isset( $value[ $key ] ) ? (string) $value[ $key ] : '';
			return Crypto::is_encrypted( $stored ) ? $stored : '';
		};

		return array(
			'browser_tier'      => $bool( 'browser_tier' ),
			'auto_pick'         => $auto_pick,
			'layout_threshold'  => $int( 'layout_threshold', 1, 2000 ),
			'text_threshold'    => $int( 'text_threshold', 1, 100 ),
			'blank_bytes'       => $int( 'blank_bytes', 0, 100000 ),
			'alert_on'          => isset( $value['alert_on'] ) && in_array( $value['alert_on'], self::ALERT_LEVELS, true ) ? $value['alert_on'] : $defaults['alert_on'],
			'email'             => $bool( 'email' ),
			'emails'            => self::sanitize_emails( $value['emails'] ?? '' ),
			'webhook_url'       => $secret( 'webhook_url' ),
			'webhook_format'    => isset( $value['webhook_format'] ) && 'slack' === $value['webhook_format'] ? 'slack' : 'json',
			'notice'            => $bool( 'notice' ),
			'auto_rollback'     => $bool( 'auto_rollback' ),
			'cron_user'         => $int( 'cron_user', 0, PHP_INT_MAX ),
			'cron_admin_checks' => $bool( 'cron_admin_checks' ),
			'auth_user'         => isset( $value['auth_user'] ) ? substr( sanitize_text_field( (string) $value['auth_user'] ), 0, 200 ) : '',
			'auth_pass'         => $secret( 'auth_pass' ),
			'retention_days'    => $int( 'retention_days', 1, 3650 ),
			'retention_runs'    => $int( 'retention_runs', 10, 100000 ),
			'daily'             => $bool( 'daily' ),
			'triggers'          => $triggers,
		);
	}

	/**
	 * A comma or line separated list of email addresses, cleaned. Invalid ones are dropped.
	 *
	 * @param mixed $value Addresses.
	 * @return string Comma separated.
	 */
	public static function sanitize_emails( $value ): string {
		$list  = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
		$clean = array();
		foreach ( (array) $list as $email ) {
			$email = sanitize_email( (string) $email );
			if ( '' !== $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}

		return implode( ', ', array_unique( $clean ) );
	}

	/**
	 * The capability needed for every screen, REST route, command and ability.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability that runs smoke tests, sees results and rolls back.
		 *
		 * @param string $capability Default update_plugins.
		 */
		$capability = (string) apply_filters( 'axle_smoke_test_capability', 'update_plugins' );

		return '' !== $capability ? $capability : 'update_plugins';
	}

	/**
	 * A filtered integer, clamped, falling back to the default on garbage.
	 *
	 * @param string $hook    Filter name without the prefix.
	 * @param int    $value   Default.
	 * @param int    $min     Lowest allowed.
	 * @param int    $max     Highest allowed.
	 * @param mixed  ...$args Extra filter arguments.
	 * @return int
	 */
	public static function filtered_int( string $hook, int $value, int $min, int $max, ...$args ): int {
		$filtered = apply_filters( 'axle_smoke_test_' . $hook, $value, ...$args );

		return is_numeric( $filtered ) ? max( $min, min( $max, (int) $filtered ) ) : $value;
	}
}
