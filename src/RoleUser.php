<?php
/**
 * The test user the "check as a role" pages are opened as.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, finds and removes the one test user per chosen role.
 *
 * The user belongs to this plugin: it is never a real person's account. It
 * has a random password nobody sees, and every way to sign in with a password
 * is refused for it, so the only way in is the single-use, single-page,
 * 60 second login the checker makes (see AdminSession). It is removed when the
 * feature is switched off, when another role is chosen and on uninstall.
 */
final class RoleUser implements Service {

	/** User meta that marks a test user. */
	public const META = 'axle_smoke_test_role_user';

	/** The pages that can be checked, in the order they are shown. */
	public const PAGES = array( 'dashboard', 'profile', 'account' );

	/**
	 * Refuses every password based way into a test user.
	 */
	public function register(): void {
		add_filter( 'wp_authenticate_user', array( $this, 'refuse_password_login' ) );
		add_filter( 'allow_password_reset', array( $this, 'refuse_password_reset' ), 10, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( $this, 'refuse_application_passwords' ), 10, 2 );
	}

	/**
	 * No sign in with a password for a test user.
	 *
	 * @param \WP_User|\WP_Error $user The user found by login.
	 * @return \WP_User|\WP_Error
	 */
	public function refuse_password_login( $user ) {
		if ( $user instanceof \WP_User && self::is_test_user( (int) $user->ID ) ) {
			return new \WP_Error( 'axle_smoke_test_no_login', __( 'This account belongs to Axle Smoke Test and cannot sign in.', 'axle-smoke-test' ) );
		}

		return $user;
	}

	/**
	 * No password reset for a test user.
	 *
	 * @param bool|\WP_Error $allow   Whether a reset is allowed.
	 * @param int            $user_id User ID.
	 * @return bool|\WP_Error
	 */
	public function refuse_password_reset( $allow, $user_id ) {
		return self::is_test_user( (int) $user_id ) ? new \WP_Error( 'axle_smoke_test_no_reset', __( 'This account belongs to Axle Smoke Test and has no password.', 'axle-smoke-test' ) ) : $allow;
	}

	/**
	 * No application passwords for a test user.
	 *
	 * @param bool     $available Whether they are available.
	 * @param \WP_User $user      The user.
	 * @return bool
	 */
	public function refuse_application_passwords( $available, $user ) {
		return self::is_test_user( (int) $user->ID ) ? false : $available;
	}

	/**
	 * Whether a user is a test user made by this plugin.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_test_user( int $user_id ): bool {
		return $user_id > 0 && (bool) get_user_meta( $user_id, self::META, true );
	}

	/**
	 * The roles a test user may have: every role except administrator.
	 *
	 * @return array<string, string> Role slug => name.
	 */
	public static function roles(): array {
		$names = wp_roles()->get_names();
		unset( $names['administrator'] );

		/**
		 * Filters the roles the "check as a role" pages can be checked as.
		 * Administrator is not offered by default: those screens are already
		 * checked as the signed-in administrator.
		 *
		 * @param array<string, string> $roles Role slug => name.
		 */
		$roles = (array) apply_filters( 'axle_smoke_test_role_check_roles', $names );

		return array_filter(
			array_map( 'strval', $roles ),
			static function ( string $name ): bool {
				return '' !== $name;
			}
		);
	}

	/**
	 * The pages that can be checked as a role.
	 *
	 * @return array<string, array{label: string, url: string, rules: array}> Keyed by page: dashboard, profile, account.
	 */
	public static function pages(): array {
		$pages = array(
			'dashboard' => array(
				'label' => __( 'Dashboard', 'axle-smoke-test' ),
				'url'   => admin_url( '/' ),
				'rules' => array( 'must' => array( '#wpbody-content' ) ),
			),
			'profile'   => array(
				'label' => __( 'Profile', 'axle-smoke-test' ),
				'url'   => admin_url( 'profile.php' ),
				'rules' => array( 'must' => array( 'form#your-profile' ) ),
			),
		);
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$account = (string) wc_get_page_permalink( 'myaccount' );
			if ( '' !== $account ) {
				$pages['account'] = array(
					'label' => __( 'My account', 'axle-smoke-test' ),
					'url'   => $account,
					'rules' => array(
						'must'     => array( '.woocommerce' ),
						'must_not' => array( 'text:[woocommerce_my_account]' ),
					),
				);
			}
		}

		/**
		 * Filters the pages checked as a role. Each page is only rendered:
		 * nothing is submitted.
		 *
		 * @param array[] $pages Keyed by a short name; each: label, url, rules (must and must_not).
		 */
		$filtered = (array) apply_filters( 'axle_smoke_test_role_check_pages', $pages );
		$out      = array();
		foreach ( $filtered as $name => $page ) {
			$name = sanitize_key( (string) $name );
			if ( '' !== $name && ! empty( $page['url'] ) ) {
				$out[ $name ] = $page + array(
					'label' => $name,
					'rules' => array(),
				);
			}
		}

		return $out;
	}

	/**
	 * The key URL entries for a role, for the pages switched on.
	 *
	 * @param string   $role  Role slug.
	 * @param string[] $pages Page names to check.
	 * @return array[] Entries for Urls.
	 */
	public static function urls( string $role, array $pages ): array {
		$roles = self::roles();
		if ( ! isset( $roles[ $role ] ) ) {
			return array();
		}

		$entries = array();
		foreach ( self::pages() as $name => $page ) {
			if ( ! in_array( $name, $pages, true ) ) {
				continue;
			}
			$entries[] = array(
				/* translators: 1: page name such as "Dashboard", 2: role name such as "Editor". */
				'label'    => sprintf( __( '%1$s as %2$s', 'axle-smoke-test' ), $page['label'], $roles[ $role ] ),
				'key'      => substr( $name, 0, 40 ) . '-as-' . substr( sanitize_key( $role ), 0, 18 ),
				'url'      => (string) $page['url'],
				'expected' => array( 200 ),
				'browser'  => false,
				'kind'     => 'html',
				'source'   => 'auto',
				'group'    => 'role',
				'as_role'  => $role,
				'rules'    => (array) $page['rules'],
			);
		}

		return $entries;
	}

	/**
	 * The login of the test user for a role.
	 *
	 * @param string $role Role slug.
	 * @return string
	 */
	public static function login( string $role ): string {
		return substr( 'axle-smoke-test-' . sanitize_key( $role ), 0, 60 );
	}

	/**
	 * The test user for a role: found, or created without a usable password.
	 *
	 * @param string $role Role slug.
	 * @return int|\WP_Error User ID.
	 */
	public static function ensure( string $role ) {
		$role = sanitize_key( $role );
		if ( ! isset( self::roles()[ $role ] ) ) {
			return new \WP_Error( 'axle_smoke_test_role_unknown', __( 'That role is not one the smoke test can check as.', 'axle-smoke-test' ) );
		}

		$login = self::login( $role );
		$user  = get_user_by( 'login', $login );
		if ( $user ) {
			if ( ! self::is_test_user( (int) $user->ID ) ) {
				// Someone else's account with the name of a test user. Never use it.
				return new \WP_Error( 'axle_smoke_test_role_taken', __( 'A user named like the test user already exists and is not the smoke test\'s. It was left alone.', 'axle-smoke-test' ) );
			}
			if ( is_multisite() && ! is_user_member_of_blog( (int) $user->ID ) ) {
				add_user_to_blog( get_current_blog_id(), (int) $user->ID, $role );
				$user = get_user_by( 'id', (int) $user->ID );
			}
			if ( $user && ! in_array( $role, (array) $user->roles, true ) ) {
				$user->set_role( $role );
			}

			return $user ? (int) $user->ID : 0;
		}

		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$host  = is_string( $host ) && '' !== $host ? $host : 'localhost';
		$roles = self::roles();
		$id    = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => $login . '@' . $host . '.invalid',
				'display_name' => sprintf(
					/* translators: %s: role name such as "Editor". */
					__( 'Smoke test (%s)', 'axle-smoke-test' ),
					$roles[ $role ]
				),
				'role'         => $role,
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_user_meta( (int) $id, self::META, 1 );

		/**
		 * Fires after the smoke test made a test user for a role.
		 *
		 * @param int    $user_id The new user.
		 * @param string $role    Role slug.
		 */
		do_action( 'axle_smoke_test_role_user_created', (int) $id, $role );

		return (int) $id;
	}

	/**
	 * Every test user, on any site of the network.
	 *
	 * @return int[]
	 */
	public static function ids(): array {
		return array_map(
			'intval',
			get_users(
				array(
					'blog_id'    => 0,
					'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A handful of users at most.
					'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'     => 'ID',
					'number'     => 50,
				)
			)
		);
	}

	/**
	 * Deletes the test users, except the one for a role.
	 *
	 * @param string $keep_role Role whose user stays, empty to delete all.
	 * @return int How many were deleted.
	 */
	public static function remove( string $keep_role = '' ): int {
		$keep    = '' === $keep_role ? '' : self::login( $keep_role );
		$removed = 0;
		foreach ( self::ids() as $id ) {
			$user = get_user_by( 'id', $id );
			if ( ! $user || ( '' !== $keep && $user->user_login === $keep ) ) {
				continue;
			}
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			if ( is_multisite() ) {
				if ( ! function_exists( 'wpmu_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/ms.php';
				}
				$done = wpmu_delete_user( $id );
			} else {
				$done = wp_delete_user( $id );
			}
			$removed += $done ? 1 : 0;
		}

		return $removed;
	}

	/**
	 * Turns the feature on or off, from the screen, WP-CLI or an ability.
	 *
	 * A person doing this must be allowed to create users and to give the
	 * role. Nothing is saved when that fails. Switching on creates the test
	 * user straight away, so a problem shows now and not in the next run.
	 *
	 * @param Settings      $settings Settings.
	 * @param bool          $on       Whether the pages are checked as the role.
	 * @param string        $role     Role slug.
	 * @param string[]|null $pages Page names to check, null to keep the saved ones.
	 * @return array|\WP_Error The saved role-check settings.
	 */
	public static function configure( Settings $settings, bool $on, string $role, ?array $pages = null ) {
		$role = sanitize_key( $role );
		if ( '' === $role ) {
			$role = (string) $settings->get( 'role_check_role' );
		}

		if ( $on ) {
			if ( get_current_user_id() > 0 ) {
				if ( ! current_user_can( 'create_users' ) ) {
					return new \WP_Error( 'axle_smoke_test_role_forbidden', __( 'Sorry, you are not allowed to create users, so you cannot switch on checks as a role.', 'axle-smoke-test' ) );
				}
				if ( ! function_exists( 'get_editable_roles' ) ) {
					require_once ABSPATH . 'wp-admin/includes/user.php';
				}
				if ( ! isset( get_editable_roles()[ $role ] ) ) {
					return new \WP_Error( 'axle_smoke_test_role_forbidden', __( 'Sorry, you are not allowed to give that role.', 'axle-smoke-test' ) );
				}
			}
			$user = self::ensure( $role );
			if ( is_wp_error( $user ) ) {
				return $user;
			}
			self::remove( $role );
		} else {
			self::remove();
		}

		$changes = array(
			'role_checks'     => $on,
			'role_check_role' => $role,
		);
		if ( null !== $pages ) {
			$changes['role_check_pages'] = array_values( array_intersect( array_keys( self::pages() ), array_map( 'sanitize_key', $pages ) ) );
		}
		$saved = $settings->update( $changes );

		/**
		 * Fires after checks as a role were switched on or off.
		 *
		 * @param bool   $on   Whether they are on.
		 * @param string $role Role slug.
		 */
		do_action( 'axle_smoke_test_role_checks_changed', $on, $role );

		return array(
			'enabled' => (bool) $saved['role_checks'],
			'role'    => (string) $saved['role_check_role'],
			'pages'   => (array) $saved['role_check_pages'],
		);
	}
}
