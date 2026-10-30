<?php
/**
 * Plugin Name: Smoke Fixture
 * Description: Test fixture for Axle Smoke Test. Version 1.4.
 * Version: 1.4
 * Requires PHP: 7.4
 *
 * @package SmokeFixture
 */

// phpcs:ignoreFile

add_action(
	'init',
	function () {
		if ( get_option( 'smoke_fixture_db_version' ) !== '1.4' ) {
			update_option( 'smoke_fixture_db_version', '1.4' );
		}
	}
);

// Hides the main content with CSS.
add_action(
	'wp_head',
	function () {
		echo '<style id="smoke-fixture-css">main, .wp-site-blocks > main { display: none !important; }</style>';
	}
);
