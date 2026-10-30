<?php
/**
 * Plugin Name: Smoke Fixture
 * Description: Test fixture for Axle Smoke Test. Version 1.2.
 * Version: 1.2
 * Requires PHP: 7.4
 *
 * @package SmokeFixture
 */

// phpcs:ignoreFile

add_action(
	'init',
	function () {
		if ( get_option( 'smoke_fixture_db_version' ) !== '1.2' ) {
			update_option( 'smoke_fixture_db_version', '1.2' );
		}
	}
);

// Breaks every front-end page with a fatal error.
add_action(
	'template_redirect',
	function () {
		smoke_fixture_missing_function();
	}
);
