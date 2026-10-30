<?php
/**
 * Plugin Name: Smoke Fixture
 * Description: Test fixture for Axle Smoke Test. Version 1.3.
 * Version: 1.3
 * Requires PHP: 7.4
 *
 * @package SmokeFixture
 */

// phpcs:ignoreFile

add_action(
	'init',
	function () {
		if ( get_option( 'smoke_fixture_db_version' ) !== '1.3' ) {
			update_option( 'smoke_fixture_db_version', '1.3' );
		}
	}
);

// Throws a JavaScript error on every front-end page.
add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_script( 'smoke-fixture', plugins_url( 'fixture.js', __FILE__ ), array(), '1.3', false );
	}
);
