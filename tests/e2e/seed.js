// Shared set-up for the Axle Smoke Test specs, the accessibility checks, the
// screenshots and the demo: the fixture must-use plugin, the "Smoke Fixture"
// plugin in its versions, and quick ways to read runs from the database.
const { wp, wpEval } = require( './utils' );

const sql = ( query ) => wp( 'db', 'query', query, '--skip-column-names' );

/** Copies the must-use fixture and installs Smoke Fixture 1.1, active. */
function installFixture() {
	wpEval( 'wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . "/axle-smoke-test/tests/e2e/fixtures/axle-smoke-test-fixture.php", WPMU_PLUGIN_DIR . "/axle-smoke-test-fixture.php" );' );
	setFixture( '1.1' );
	wp( 'plugin', 'activate', 'smoke-fixture' );
	offer( '' );
}

/** Puts a Smoke Fixture version in place directly, without an update. */
function setFixture( version ) {
	wpEval( `ast_fixture_install( '${ version }' );` );
}

/** Makes WordPress offer a Smoke Fixture version as an update. Empty: no offer. */
function offer( version ) {
	if ( version ) {
		wp( 'option', 'update', 'ast_fixture_offer', version );
	} else {
		try {
			wp( 'option', 'delete', 'ast_fixture_offer' );
		} catch ( error ) {
			// Not set.
		}
	}
}

/** Some content, so the latest post, page and category URLs exist. */
function createContent() {
	if ( wp( 'post', 'list', '--post_type=post', '--post_status=publish', '--format=count' ) === '0' ) {
		const cat = wp( 'term', 'create', 'category', 'Roasting', '--porcelain' );
		wp( 'post', 'create', '--post_title=How we roast', '--post_status=publish', '--post_content=<!-- wp:paragraph --><p>Small batches, every Tuesday.</p><!-- /wp:paragraph -->', `--post_category=${ cat }` );
	}
	if ( wp( 'post', 'list', '--post_type=page', '--post_status=publish', '--format=count' ) === '0' ) {
		wp( 'post', 'create', '--post_type=page', '--post_title=About us', '--post_status=publish', '--post_content=<!-- wp:paragraph --><p>We roast coffee in Pune.</p><!-- /wp:paragraph -->' );
	}
}

/** Empties both tables and the plugin's options: no runs, no baselines. */
function resetRuns() {
	sql( 'TRUNCATE TABLE wp_axle_smoke_runs' );
	sql( 'TRUNCATE TABLE wp_axle_smoke_results' );
	for ( const name of [ 'axle_smoke_test_alert_log', 'axle_smoke_test_notice', 'axle_smoke_test_settings', 'axle_smoke_test_urls', 'ast_fixture_mail' ] ) {
		try {
			wp( 'option', 'delete', name );
		} catch ( error ) {
			// Not set.
		}
	}
}

/** Marks queued and waiting runs done, so no screen picks them up. */
function settleRuns() {
	sql( "UPDATE wp_axle_smoke_runs SET status = 'done' WHERE status IN ('queued', 'waiting_browser', 'running')" );
}

/** A run with its results, from the database. Default: the newest. */
function getRun( id = 0 ) {
	const php = id
		? `$r = AryanX\\Axle\\SmokeTest\\Plugin::instance()->runner()->runs(); $run = $r->get( ${ Number( id ) } );`
		: '$r = AryanX\\Axle\\SmokeTest\\Plugin::instance()->runner()->runs(); $run = $r->latest();';
	const out = wpEval( `${ php } if ( $run ) { $run['results'] = $r->results( (int) $run['id'] ); } echo wp_json_encode( $run );` );
	return JSON.parse( out || 'null' );
}

/** Changes settings through the plugin, as the screen would. */
function setSettings( changes ) {
	wpEval( `AryanX\\Axle\\SmokeTest\\Plugin::instance()->settings()->update( json_decode( '${ JSON.stringify( changes ) }', true ) );` );
}

/** Three finished runs (green, amber, red) with results. Returns their IDs. */
function seedRuns() {
	return JSON.parse( wpEval( 'echo wp_json_encode( ast_fixture_seed() );' ) );
}

/** Emails the fixture caught. */
function mails() {
	try {
		return JSON.parse( wp( 'option', 'get', 'ast_fixture_mail', '--format=json' ) );
	} catch ( error ) {
		return [];
	}
}

/** Fixture, content, no runs, then three seeded runs. For the accessibility checks and screenshots. */
function seedScreens() {
	installFixture();
	createContent();
	resetRuns();
	return seedRuns();
}

/** A finished server tier that waits for a browser, as cron leaves it. Returns its ID. */
function waitingRun() {
	settleRuns();
	return Number( wpEval( '$run = AryanX\\Axle\\SmokeTest\\Plugin::instance()->runner()->start( "schedule", array(), 0 ); echo $run["id"];' ) );
}

/** Shows the failed-run notice for a run. */
function showNotice( id ) {
	wp( 'option', 'update', 'axle_smoke_test_notice', String( id ) );
}

module.exports = {
	seedScreens,
	waitingRun,
	showNotice, sql, installFixture, setFixture, offer, createContent, resetRuns, settleRuns, getRun, setSettings, seedRuns, mails };
