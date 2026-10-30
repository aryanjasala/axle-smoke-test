<?php
/**
 * Plugin Name: Smoke Fixture
 * Description: Test fixture for Axle Smoke Test. Version 1.1.
 * Version: 1.1
 * Requires PHP: 7.4
 *
 * @package SmokeFixture
 */

// phpcs:ignoreFile

add_action(
	'init',
	function () {
		if ( get_option( 'smoke_fixture_db_version' ) !== '1.1' ) {
			update_option( 'smoke_fixture_db_version', '1.1' );
		}
	}
);

// A well-behaved version.
