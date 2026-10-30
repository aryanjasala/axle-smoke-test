<?php
/**
 * Tests for the PHP error collector and debug.log parsing.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest\Tests\Unit;

use AryanX\Axle\SmokeTest\DebugLog;
use AryanX\Axle\SmokeTest\ErrorCollector;

final class CollectorTest extends SmokeTestCase {

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_AXLE_SMOKE_TEST'] );
		parent::tearDown();
	}

	public function test_tokens_are_checked_and_stored_hashed(): void {
		$this->assertSame( '', ErrorCollector::request_token() );
		$_SERVER['HTTP_X_AXLE_SMOKE_TEST'] = 'not-a-token';
		$this->assertSame( '', ErrorCollector::request_token() );

		$token                             = str_repeat( 'ab', 16 );
		$_SERVER['HTTP_X_AXLE_SMOKE_TEST'] = $token;
		$this->assertSame( $token, ErrorCollector::request_token() );
		$this->assertStringNotContainsString( $token, ErrorCollector::token_key( $token ) );
		$this->assertNotSame( ErrorCollector::token_key( $token ), ErrorCollector::errors_key( $token ) );
	}

	public function test_without_a_stored_token_nothing_starts(): void {
		$_SERVER['HTTP_X_AXLE_SMOKE_TEST'] = str_repeat( 'cd', 16 );

		$this->assertFalse( ErrorCollector::maybe_start() );
		$this->assertFalse( ErrorCollector::active() );
	}

	public function test_records_errors_once_with_readable_types(): void {
		ErrorCollector::record( E_USER_WARNING, 'Careful', '/x.php', 3 );
		ErrorCollector::record( E_USER_WARNING, 'Careful', '/x.php', 3 );
		ErrorCollector::record( E_USER_DEPRECATED, 'Old', '/x.php', 4 );

		$errors = array_slice( ErrorCollector::collected(), -2 );
		$this->assertSame( array( 'warning', 'deprecated' ), array_column( $errors, 'type' ) );
		$this->assertSame( 'fatal error', ErrorCollector::type( E_ERROR ) );
		$this->assertSame( 'notice', ErrorCollector::type( E_NOTICE ) );
	}

	public function test_debug_log_lines_become_errors(): void {
		$log = "[29-Oct-2026 19:48:31 UTC] PHP Warning:  Undefined variable \$x in /var/www/wp-content/plugins/shop/shop.php on line 12\n"
			. "[29-Oct-2026 19:48:31 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function y() in /var/www/wp-content/plugins/shop/shop.php:20\nStack trace:\n#0 {main}\n"
			. "[29-Oct-2026 19:48:31 UTC] PHP Warning:  Undefined variable \$x in /var/www/wp-content/plugins/shop/shop.php on line 12\n"
			. "Some other line\n";

		$errors = DebugLog::parse( $log );
		$this->assertCount( 2, $errors );
		$this->assertSame( array( 'warning', 'fatal error' ), array_column( $errors, 'type' ) );
		$this->assertSame( 20, $errors[1]['line'] );
		$this->assertSame( '/var/www/wp-content/plugins/shop/shop.php', $errors[0]['file'] );
	}
}
