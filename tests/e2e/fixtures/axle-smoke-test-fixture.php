<?php
/**
 * Plugin Name: Axle Smoke Test e2e fixture
 * Description: Test-only helpers, copied into mu-plugins by the end-to-end suite.
 *
 * - Loopback requests reach Apache in the wp container (the site URL uses the host port).
 * - WP-Cron never runs by itself, so tests decide when queued runs start.
 * - Emails are logged to the ast_fixture_mail option instead of being sent.
 * - Application passwords work over plain HTTP.
 * - "Smoke Fixture" updates: set the ast_fixture_offer option to a version
 *   (1.1, 1.2, 1.3 or 1.4) and WordPress offers that version as an update.
 *
 * @package AryanX\Axle\SmokeTest
 */

// phpcs:ignoreFile

remove_action( 'init', 'wp_cron' );
remove_action( 'wp_loaded', '_wp_cron' );

add_action(
	'http_api_curl',
	function ( $handle ) {
		$home = wp_parse_url( home_url() );
		if ( ! empty( $home['port'] ) && 80 !== (int) $home['port'] && defined( 'CURLOPT_CONNECT_TO' ) ) {
			curl_setopt( $handle, CURLOPT_CONNECT_TO, array( $home['host'] . ':' . $home['port'] . ':wp:80' ) );
		}
	}
);

add_filter(
	'pre_wp_mail',
	function ( $return, $atts ) {
		$log   = get_option( 'ast_fixture_mail', array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'to'      => $atts['to'],
			'subject' => $atts['subject'],
			'message' => $atts['message'],
		);
		update_option( 'ast_fixture_mail', $log, false );
		return true;
	},
	10,
	2
);

add_filter( 'wp_is_application_passwords_available', '__return_true' );

/**
 * The folder of a fixture version.
 */
function ast_fixture_dir( $version ) {
	return WP_PLUGIN_DIR . '/axle-smoke-test/tests/e2e/fixtures/smoke-fixture/' . $version;
}

/**
 * Zips a fixture version as smoke-fixture/.
 */
function ast_fixture_zip( $version ) {
	$zip_path = get_temp_dir() . 'smoke-fixture-' . $version . '-' . wp_generate_password( 6, false ) . '.zip';
	$zip      = new ZipArchive();
	$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( glob( ast_fixture_dir( $version ) . '/*' ) as $file ) {
		$zip->addFile( $file, 'smoke-fixture/' . basename( $file ) );
	}
	$zip->close();
	return $zip_path;
}

/**
 * Installs a fixture version directly, replacing what is there.
 */
function ast_fixture_install( $version ) {
	$target = WP_PLUGIN_DIR . '/smoke-fixture';
	if ( is_dir( $target ) ) {
		foreach ( glob( $target . '/*' ) as $file ) {
			unlink( $file );
		}
	} else {
		mkdir( $target );
	}
	foreach ( glob( ast_fixture_dir( $version ) . '/*' ) as $file ) {
		copy( $file, $target . '/' . basename( $file ) );
	}
	if ( function_exists( 'opcache_reset' ) ) {
		opcache_reset();
	}
	// The web server has its own OPcache.
	AryanX\Axle\SmokeTest\Rollback::clear_web_opcache( $target );
	wp_clean_plugins_cache( true );
}

add_filter(
	'site_transient_update_plugins',
	function ( $value ) {
		$offer = get_option( 'ast_fixture_offer' );
		if ( ! $offer ) {
			return $value;
		}
		if ( ! is_object( $value ) ) {
			$value = (object) array( 'last_checked' => time(), 'checked' => array(), 'response' => array(), 'translations' => array(), 'no_update' => array() );
		}
		$value->response = isset( $value->response ) && is_array( $value->response ) ? $value->response : array();
		$value->response['smoke-fixture/smoke-fixture.php'] = (object) array(
			'id'          => 'smoke-fixture/smoke-fixture.php',
			'slug'        => 'smoke-fixture',
			'plugin'      => 'smoke-fixture/smoke-fixture.php',
			'new_version' => (string) $offer,
			'url'         => home_url( '/' ),
			'package'     => 'ast-fixture:' . $offer,
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => '',
			'requires_php' => '7.4',
		);
		return $value;
	}
);

add_filter(
	'upgrader_pre_download',
	function ( $reply, $package ) {
		if ( is_string( $package ) && 0 === strpos( $package, 'ast-fixture:' ) ) {
			return ast_fixture_zip( substr( $package, strlen( 'ast-fixture:' ) ) );
		}
		return $reply;
	},
	10,
	2
);

/**
 * Seeds three finished runs (green, amber, red) with results, for the
 * accessibility checks and screenshots. Returns their IDs.
 */
function ast_fixture_seed() {
	$plugin  = AryanX\Axle\SmokeTest\Plugin::instance();
	$runner  = $plugin->runner();
	$runs    = $runner->runs();
	$urls    = $runner->urls()->all();
	$out     = array();
	$backups = new AryanX\Axle\SmokeTest\Backups();
	if ( ! AryanX\Axle\SmokeTest\Backups::find( 'plugin', 'smoke-fixture/smoke-fixture.php', '1.1' ) ) {
		$backups->create( 'plugin', 'smoke-fixture/smoke-fixture.php', '1.1' );
	}

	$plans = array(
		'green' => array( 'manual', array() ),
		'amber' => array(
			'update',
			array(
				array( 'type' => 'plugin', 'id' => 'smoke-fixture/smoke-fixture.php', 'name' => 'Smoke Fixture', 'from' => '1.1', 'to' => '1.3' ),
			),
		),
		'red'   => array(
			'update',
			array(
				array( 'type' => 'plugin', 'id' => 'smoke-fixture/smoke-fixture.php', 'name' => 'Smoke Fixture', 'from' => '1.1', 'to' => '1.2' ),
			),
		),
	);
	$fixture = WP_PLUGIN_DIR . '/smoke-fixture/smoke-fixture.php';

	foreach ( $plans as $verdict => $plan ) {
		$id = $runs->create( $plan[0], array( 'items' => $plan[1], 'versions_before' => array( 'smoke_fixture_db_version' => '1.0' ), 'duration_ms' => 1840 ), 1, 'done' );
		$checked = array();
		foreach ( $urls as $i => $url ) {
			$checked[] = $url['key'];
			$reasons   = array();
			$result    = 'green';
			$php       = array();
			if ( 'red' === $verdict && 'html' === $url['kind'] && ! $url['auth'] && 'login' !== $url['key'] ) {
				$result  = 'red';
				$reasons = array(
					array( 'level' => 'red', 'code' => 'fatal', 'message' => 'Fatal error: Uncaught Error: Call to undefined function smoke_fixture_missing_function() in wp-content/plugins/smoke-fixture/smoke-fixture.php on line 26', 'source' => 'plugin:smoke-fixture' ),
					array( 'level' => 'red', 'code' => 'status', 'message' => 'HTTP 500, expected ' . implode( ', ', $url['expected'] ) . '.', 'source' => '' ),
				);
			}
			if ( 'amber' === $verdict && 'home' === $url['key'] ) {
				$result  = 'amber';
				$php     = array( array( 'type' => 'warning', 'message' => 'Undefined array key "layout"', 'file' => $fixture, 'line' => 18, 'source' => 'plugin:smoke-fixture' ) );
				$reasons = array( array( 'level' => 'amber', 'code' => 'php_error', 'message' => 'New PHP warning: Undefined array key "layout"', 'source' => 'plugin:smoke-fixture' ) );
			}
			$runs->add_result(
				array(
					'run_id'      => $id,
					'url_key'     => $url['key'],
					'url'         => $url['url'],
					'tier'        => 'server',
					'http_status' => 'red' === $result ? 500 : $url['expected'][0],
					'ttfb_ms'     => 80 + 13 * $i,
					'body_bytes'  => 24000,
					'php_errors'  => $php,
					'verdict'     => $result,
					'meta'        => array( 'label' => $url['label'], 'expected' => $url['expected'], 'kind' => $url['kind'], 'reasons' => $reasons, 'compared_with' => 1, 'baseline_status' => $url['expected'][0] ),
				)
			);
			if ( $url['browser'] && 'red' !== $result ) {
				$browser_verdict = 'amber' === $verdict && 'home' === $url['key'] ? 'amber' : 'green';
				$runs->add_result(
					array(
						'run_id'    => $id,
						'url_key'   => $url['key'],
						'url'       => $url['url'],
						'tier'      => 'browser',
						'js_errors' => 'amber' === $browser_verdict ? array( array( 'message' => 'Uncaught Error: Smoke Fixture 1.3 broke a script', 'url' => plugins_url( 'fixture.js', $fixture ), 'line' => 1, 'kind' => 'error', 'source' => 'plugin:smoke-fixture' ) ) : array(),
						'verdict'   => $browser_verdict,
						'meta'      => array(
							'label'         => $url['label'],
							'load_ms'       => 900 + 40 * $i,
							'compared_with' => 1,
							'reasons'       => 'amber' === $browser_verdict ? array( array( 'level' => 'amber', 'code' => 'js_error', 'message' => 'New JavaScript error: Uncaught Error: Smoke Fixture 1.3 broke a script', 'source' => 'plugin:smoke-fixture' ) ) : array(),
						),
					)
				);
			}
		}
		$runs->update( $id, array( 'context' => array( 'items' => $plan[1], 'checked' => $checked, 'duration_ms' => 1840, 'versions_before' => array( 'smoke_fixture_db_version' => '1.0' ) ), 'server_done' => 1, 'browser_done' => 'red' === $verdict ? 0 : 1, 'status' => 'done' ) );
		$runner->evaluate( $id );
		$out[ $verdict ] = $id;
	}
	delete_option( 'axle_smoke_test_notice' );

	return $out;
}
