<?php
/**
 * Constants PHPStan needs to know about. WordPress defines these while it
 * loads; the stubs do not have them.
 *
 * @package AryanX\Axle\SmokeTest
 */

defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );
defined( 'AUTH_COOKIE' ) || define( 'AUTH_COOKIE', 'wordpress_' );
defined( 'SECURE_AUTH_COOKIE' ) || define( 'SECURE_AUTH_COOKIE', 'wordpress_sec_' );
defined( 'LOGGED_IN_COOKIE' ) || define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_' );
