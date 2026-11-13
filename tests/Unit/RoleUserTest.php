<?php
/**
 * Tests for the test user and the pages checked as a role.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\AdminSession;
use AryanX\Axle\SmokeTest\Attribution;
use AryanX\Axle\SmokeTest\Checker;
use AryanX\Axle\SmokeTest\RoleUser;
use AryanX\Axle\SmokeTest\Settings;
use AryanX\Axle\SmokeTest\Urls;
use Brain\Monkey\Functions;

final class RoleUserTest extends SmokeTestCase {

	/** @var array<int, array> Users made, by ID. */
	private $users = array();

	/** @var array<int, array> User meta by user. */
	private $meta = array();

	protected function setUp(): void {
		parent::setUp();
		$this->users = array();
		$this->meta  = array();

		$roles = new class() {
			public function get_names() {
				return array(
					'administrator' => 'Administrator',
					'editor'        => 'Editor',
					'subscriber'    => 'Subscriber',
				);
			}
		};
		Functions\when( 'wp_roles' )->justReturn( $roles );
		Functions\when( 'get_users' )->justReturn( array() );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'a-long-random-password' );
		Functions\when( 'get_user_by' )->alias(
			function ( $field, $value ) {
				foreach ( $this->users as $id => $user ) {
					if ( ( 'login' === $field && $user['user_login'] === $value ) || ( 'id' === $field && $id === (int) $value ) ) {
						return new class( $id, $user ) extends \WP_User {
							public $user_login = '';
							public $roles      = array();

							public function __construct( $id, $user ) {
								parent::__construct( $id );
								$this->user_login = $user['user_login'];
								$this->roles      = array( $user['role'] );
							}
						};
					}
				}
				return false;
			}
		);
		Functions\when( 'wp_insert_user' )->alias(
			function ( $data ) {
				$id                 = 100 + count( $this->users );
				$this->users[ $id ] = $data;
				return $id;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key ) {
				return $this->meta[ $user_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				$this->meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
	}

	public function test_it_makes_one_user_without_a_usable_password_and_marks_it(): void {
		$id = RoleUser::ensure( 'editor' );

		$this->assertSame( 100, $id );
		$this->assertSame( 'axle-smoke-test-editor', $this->users[100]['user_login'] );
		$this->assertSame( 'editor', $this->users[100]['role'] );
		$this->assertStringEndsWith( '.invalid', $this->users[100]['user_email'] );
		$this->assertTrue( RoleUser::is_test_user( 100 ) );
		$this->assertFalse( RoleUser::is_test_user( 1 ) );

		// Asking again finds the same user.
		$this->assertSame( 100, RoleUser::ensure( 'editor' ) );
		$this->assertCount( 1, $this->users );
	}

	public function test_it_never_uses_or_changes_someone_elses_account(): void {
		$this->users[7] = array(
			'user_login' => 'axle-smoke-test-editor',
			'role'       => 'administrator',
		);

		$result = RoleUser::ensure( 'editor' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'administrator', $this->users[7]['role'] );
		$this->assertCount( 1, $this->users );
	}

	public function test_administrator_and_unknown_roles_are_refused(): void {
		$this->assertInstanceOf( \WP_Error::class, RoleUser::ensure( 'administrator' ) );
		$this->assertInstanceOf( \WP_Error::class, RoleUser::ensure( 'ghost' ) );
		$this->assertSame( array(), $this->users );
	}

	public function test_password_ways_in_are_refused_only_for_test_users(): void {
		$this->meta[100][ RoleUser::META ] = 1;
		$service                           = new RoleUser();

		$this->assertInstanceOf( \WP_Error::class, $service->refuse_password_login( new \WP_User( 100 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $service->refuse_password_reset( true, 100 ) );
		$this->assertFalse( $service->refuse_application_passwords( true, new \WP_User( 100 ) ) );

		$real = new \WP_User( 5 );
		$this->assertSame( $real, $service->refuse_password_login( $real ) );
		$this->assertTrue( $service->refuse_password_reset( true, 5 ) );
		$this->assertTrue( $service->refuse_application_passwords( true, $real ) );
	}

	public function test_the_entries_are_view_only_and_keyed_per_role(): void {
		$entries = RoleUser::urls( 'editor', array( 'dashboard', 'profile', 'account' ) );

		$this->assertSame( array( 'dashboard-as-editor', 'profile-as-editor' ), wp_list_pluck( $entries, 'key' ) );
		$this->assertSame( 'Dashboard as Editor', $entries[0]['label'] );
		$this->assertFalse( $entries[0]['browser'] );
		$this->assertSame( 'editor', $entries[0]['as_role'] );
		$this->assertSame( array(), RoleUser::urls( 'administrator', array( 'dashboard' ) ) );
		$this->assertCount( 1, RoleUser::urls( 'editor', array( 'profile', 'nonsense' ) ) );
	}

	public function test_the_urls_list_has_the_role_pages_only_when_switched_on(): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return Settings::OPTION === $name ? array( 'role_checks' => true, 'role_check_role' => 'editor', 'role_check_pages' => array( 'dashboard' ) ) : $default;
			}
		);
		$urls = new Urls( new Settings() );
		$keys = wp_list_pluck( array_filter( $urls->auto(), static fn( $url ) => ! empty( $url['as_role'] ) ), 'key' );
		$this->assertSame( array( 'dashboard-as-editor' ), array_values( $keys ) );

		$normal = Urls::normalise( array( 'url' => 'https://example.com/wp-admin/', 'as_role' => 'Editor', 'browser' => true ) );
		$this->assertSame( 'editor', $normal['as_role'] );
		$this->assertFalse( $normal['browser'] );
	}

	public function test_a_session_for_a_lesser_role_is_only_made_for_a_test_user(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'wp_generate_auth_cookie' )->justReturn( 'cookie' );
		// The real account has no update_plugins capability here.
		$this->caps = array();

		$this->assertNull( AdminSession::create( 9, 'https://example.com/wp-admin/', true ) );
		$this->assertNull( AdminSession::create( 9, 'https://example.com/wp-admin/' ) );

		$this->meta[9][ RoleUser::META ] = 1;
		$session                         = AdminSession::create( 9, 'https://example.com/wp-admin/', true );
		$this->assertNotNull( $session );
		$this->assertNull( AdminSession::create( 9, 'https://example.com/wp-admin/' ) );
	}

	public function test_configure_needs_the_right_to_create_users_and_to_give_the_role(): void {
		Functions\when( 'get_editable_roles' )->justReturn( array( 'subscriber' => array() ) );
		Functions\when( 'update_option' )->justReturn( true );
		$settings = new Settings();

		// A user who may manage plugins but not create users.
		$this->caps = array( 'update_plugins' => true );
		$denied     = RoleUser::configure( $settings, true, 'editor' );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( array(), $this->users );

		// May create users, but not give the editor role.
		$this->caps = array( 'update_plugins' => true, 'create_users' => true );
		$denied     = RoleUser::configure( $settings, true, 'editor' );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( array(), $this->users );

		$saved = RoleUser::configure( $settings, true, 'subscriber' );
		$this->assertTrue( $saved['enabled'] );
		$this->assertSame( 'subscriber', $saved['role'] );
		$this->assertCount( 1, $this->users );
	}

	public function test_a_role_check_that_cannot_sign_in_is_reported_not_skipped(): void {
		Functions\when( 'wp_rand' )->alias( 'mt_rand' );
		Functions\when( 'wp_remote_get' )->alias(
			function () {
				$this->fail( 'No request should be made without a session.' );
			}
		);
		\Brain\Monkey\Filters\expectApplied( 'axle_smoke_test_admin_loopback_enabled' )->once()->andReturn( false );
		$checker = new Checker( new Settings(), new Attribution( array() ) );

		$result = $checker->check(
			array(
				'key'      => 'dashboard-as-editor',
				'label'    => 'Dashboard as Editor',
				'url'      => 'https://example.com/wp-admin/',
				'expected' => array( 200 ),
				'browser'  => false,
				'auth'     => false,
				'kind'     => 'html',
				'as_role'  => 'editor',
			),
			1
		);

		$this->assertSame( 'role', $result['meta']['auth_mode'] );
		$this->assertSame( 0, $result['http_status'] );
		$this->assertStringContainsString( 'could not be signed in', $result['meta']['error'] );
	}
}
