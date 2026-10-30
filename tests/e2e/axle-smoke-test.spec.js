const { execFileSync } = require( 'child_process' );
const { test, expect, wp, wpEval, logInAs, appPasswordClient } = require( './utils' );
const { COMPOSE_DIR } = require( './utils/config' );
const { installFixture, setFixture, offer, createContent, resetRuns, settleRuns, getRun, setSettings, mails, sql } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-smoke-test';
const READ_ONLY_MODE = 'https://github.com/aryanjasala/axle-read-only-mode/releases/download/v3.0.0/axle-read-only-mode.zip';

/** Whether this WordPress has the Abilities API (6.9+). */
const hasAbilities = () => wpEval( 'echo function_exists( "wp_register_ability" ) ? "yes" : "no";' ) === 'yes';

/** The installed Smoke Fixture version. */
const fixtureVersion = () => wp( 'plugin', 'get', 'smoke-fixture', '--field=version' );

/** Runs WP-CLI and returns its exit code and output, without throwing. */
function wpStatus( ...args ) {
	try {
		const out = execFileSync( 'docker', [ 'compose', 'exec', '-T', 'cli', 'wp', ...args ], { cwd: COMPOSE_DIR, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } );
		return { code: 0, out };
	} catch ( error ) {
		return { code: error.status, out: `${ error.stdout }${ error.stderr }` };
	}
}

/** Waits until a run reaches a status, polling the database. */
async function waitForRun( id, statuses = [ 'done' ], timeout = 60000 ) {
	const deadline = Date.now() + timeout;
	for ( ;; ) {
		const run = getRun( id );
		if ( run && statuses.includes( run.status ) ) {
			return run;
		}
		if ( Date.now() > deadline ) {
			throw new Error( `Run ${ id } is still ${ run && run.status }` );
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1000 ) );
	}
}

/** Presses Run now and waits for the run page it lands on. Returns the run from the database. */
async function runNow( page ) {
	await page.goto( SCREEN );
	await page.getByRole( 'button', { name: 'Run now' } ).click();
	await page.waitForURL( /[?&]run=\d+/, { timeout: 120000 } );
	const id = Number( new URL( page.url() ).searchParams.get( 'run' ) );
	return waitForRun( id );
}

/** The reasons of every non-green result, for failure messages. */
const problems = ( run ) =>
	run.results
		.filter( ( item ) => item.verdict !== 'green' )
		.map( ( item ) => `${ item.url_key } (${ item.tier }): ${ ( item.meta.reasons || [] ).map( ( reason ) => reason.message ).join( '; ' ) }` )
		.join( '\n' );

const results = ( run, tier ) => run.results.filter( ( result ) => result.tier === tier );
const result = ( run, key, tier = 'server' ) => run.results.find( ( item ) => item.url_key === key && item.tier === tier );

test.describe.serial( 'Axle Smoke Test', () => {
	test.beforeAll( () => {
		installFixture();
		createContent();
		resetRuns();
		wp( 'axle-smoke-test', 'backups', 'purge', '--all' );
	} );

	test.afterAll( () => {
		offer( '' );
		setFixture( '1.1' );
		resetRuns();
	} );

	test( 'Run now checks every key URL in both tiers and sets the baseline', async ( { page } ) => {
		await page.goto( SCREEN );
		await expect( page.getByRole( 'heading', { name: 'No runs yet' } ) ).toBeVisible();

		const run = await runNow( page );
		await expect( page.locator( '.ast-run-title .ast-badge' ) ).toHaveText( 'Green' );
		await expect( page.locator( '.ast-results tbody tr' ).first() ).toBeVisible();

		expect( run.verdict ).toBe( 'green' );
		expect( run.trigger_type ).toBe( 'manual' );
		expect( run.server_done ).toBe( 1 );
		expect( run.browser_done ).toBe( 1 );
		expect( results( run, 'server' ).length ).toBeGreaterThanOrEqual( 10 );
		expect( results( run, 'browser' ).length ).toBeGreaterThanOrEqual( 5 );
		expect( result( run, 'not-found' ).http_status ).toBe( 404 );
		expect( result( run, 'not-found' ).verdict ).toBe( 'green' );
		expect( result( run, 'home', 'browser' ).layout.main.visible ).toBe( true );
		// Every green result is now the baseline for its URL and tier.
		expect( run.results.every( ( item ) => item.is_baseline === 1 ) ).toBe( true );
		// The admin who pressed the button opened wp-admin signed in.
		expect( result( run, 'dashboard' ).http_status ).toBe( 200 );
		// Its single-use login sessions are gone again.
		expect( wpEval( '$n = 0; foreach ( (array) get_user_meta( 1, "session_tokens", true ) as $s ) { $n += isset( $s["axle_smoke_test"] ) ? 1 : 0; } echo $n;' ) ).toBe( '0' );
	} );

	test( 'an update from the Plugins screen that breaks the front end goes red, alerts, and rolls back', async ( { page } ) => {
		settleRuns();
		offer( '1.2' );

		await page.goto( '/wp-admin/plugins.php' );
		const row = page.locator( 'tr.plugin-update-tr[data-slug="smoke-fixture"]' );
		await row.locator( 'a.update-link' ).click();
		const panel = page.locator( '.ast-panel' );
		await expect( panel ).toHaveAttribute( 'data-verdict', 'red', { timeout: 90000 } );
		await expect( panel ).toContainText( 'Red: something is broken' );
		expect( fixtureVersion() ).toBe( '1.2' );

		const red = getRun();
		expect( red.trigger_type ).toBe( 'update' );
		expect( red.verdict ).toBe( 'red' );
		expect( red.summary.change ).toBe( 'Smoke Fixture 1.1 to 1.2' );
		expect( red.summary.sources[ 'plugin:smoke-fixture' ] ).toBeGreaterThan( 0 );
		expect( result( red, 'home' ).meta.fatal.source ).toBe( 'plugin:smoke-fixture' );
		expect( result( red, 'rest' ).verdict ).toBe( 'green' );
		expect( red.summary.db_versions ).toEqual( [ { option: 'smoke_fixture_db_version', before: '1.1', after: '1.2' } ] );
		expect( mails().some( ( mail ) => /Smoke test red after Smoke Fixture 1\.1 to 1\.2/.test( mail.subject ) ) ).toBe( true );

		await panel.getByRole( 'link', { name: 'See the details' } ).click();
		await expect( page.locator( '.ast-change' ) ).toHaveText( 'Smoke Fixture 1.1 to 1.2' );
		await expect( page.locator( '.ast-problems' ) ).toContainText( 'Smoke Fixture: ' );
		await expect( page.locator( '.ast-problems' ) ).toContainText( 'smoke_fixture_db_version' );
		await page.getByRole( 'button', { name: 'Roll back Smoke Fixture to 1.1' } ).click();
		await expect( page.locator( '.notice-success' ).first() ).toContainText( 'Rolled back.' );
		expect( fixtureVersion() ).toBe( '1.1' );

		const after = await waitForRun( Number( new URL( page.url() ).searchParams.get( 'run' ) ) );
		expect( after.trigger_type ).toBe( 'rollback' );
		expect( after.verdict, problems( after ) ).toBe( 'green' );
		expect( getRun( red.id ).summary.rolled_back[ 0 ] ).toMatchObject( { id: 'smoke-fixture/smoke-fixture.php', from: '1.2', to: '1.1' } );
	} );

	test( 'with auto rollback on, `wp plugin update` then `wp axle-smoke-test run` exits 1 and puts 1.1 back', () => {
		settleRuns();
		setSettings( { auto_rollback: true } );
		offer( '1.2' );
		wp( 'plugin', 'update', 'smoke-fixture' );
		expect( fixtureVersion() ).toBe( '1.2' );

		const cli = wpStatus( 'axle-smoke-test', 'run', '--reason=Deploy 4f2a9c1' );
		expect( cli.code ).toBe( 1 );
		expect( cli.out ).toContain( 'Verdict: red' );
		expect( fixtureVersion() ).toBe( '1.1' );

		const runs = JSON.parse( wp( 'axle-smoke-test', 'list', '--format=json', '--limit=2' ) );
		expect( runs[ 0 ] ).toMatchObject( { trigger: 'rollback', verdict: 'green' } );
		expect( runs[ 1 ] ).toMatchObject( { trigger: 'update', verdict: 'red', change: 'Smoke Fixture 1.1 to 1.2' } );
		const red = getRun( runs[ 1 ].id );
		expect( red.summary.auto_rollback ).toBe( true );
		expect( red.context.reason ).toBe( 'Deploy 4f2a9c1' );
		setSettings( { auto_rollback: false } );
		offer( '' );
	} );

	test( 'a JavaScript error is amber in the browser tier, green on the server, and blamed on the plugin', async ( { page } ) => {
		settleRuns();
		offer( '1.3' );
		wp( 'plugin', 'update', 'smoke-fixture' );
		offer( '' );
		settleRuns();

		const run = await runNow( page );
		expect( run.verdict ).toBe( 'amber' );
		expect( results( run, 'server' ).every( ( item ) => item.verdict === 'green' ) ).toBe( true );
		const home = result( run, 'home', 'browser' );
		expect( home.verdict ).toBe( 'amber' );
		expect( home.js_errors[ 0 ].message ).toContain( 'Smoke Fixture 1.3 broke a script' );
		expect( home.js_errors[ 0 ].source ).toBe( 'plugin:smoke-fixture' );
		await expect( page.locator( '.ast-result-amber[data-url-key="home"][data-tier="browser"]' ) ).toContainText( 'New JavaScript error' );
		await expect( page.locator( '.ast-result-amber[data-url-key="home"][data-tier="browser"]' ) ).toContainText( '(Smoke Fixture)' );
	} );

	test( 'the axleSmokeTest.ignoredJsErrors filter and urlChecked action work in the browser', async ( { page } ) => {
		settleRuns();
		await page.goto( SCREEN );
		const seen = await page.evaluate( async () => {
			const checked = [];
			window.wp.hooks.addFilter( 'axleSmokeTest.ignoredJsErrors', 'e2e', ( patterns ) => [ ...patterns, 'Smoke Fixture 1.3' ] );
			window.wp.hooks.addFilter( 'axleSmokeTest.layoutSelectors', 'e2e', ( selectors ) => [ ...selectors, '.e2e-missing' ] );
			window.wp.hooks.addAction( 'axleSmokeTest.urlChecked', 'e2e', ( stored, url ) => checked.push( url.key ) );
			const plan = await window.wp.apiFetch( { path: '/axle-smoke-test/v1/runs', method: 'POST', data: { browser: true, urls: [ 'home' ] } } );
			const run = await window.axleSmokeTest.runBrowserTier( plan );
			return { checked, id: run.id, verdict: run.verdict };
		} );
		expect( seen.checked ).toEqual( [ 'home' ] );
		const run = getRun( seen.id );
		expect( result( run, 'home', 'browser' ).js_errors ).toEqual( [] );
		expect( result( run, 'home', 'browser' ).layout ).not.toHaveProperty( '.e2e-missing' );
		expect( run.verdict ).toBe( 'green' );
	} );

	test( 'hidden main content is amber; accepting the run as baseline makes the next run green', async ( { page } ) => {
		settleRuns();
		setFixture( '1.4' );

		const amber = await runNow( page );
		expect( amber.verdict ).toBe( 'amber' );
		const home = result( amber, 'home', 'browser' );
		expect( home.verdict ).toBe( 'amber' );
		expect( home.layout.main.visible ).toBe( false );
		expect( home.meta.reasons.map( ( reason ) => reason.message ) ).toContain( 'Landmark main is gone or hidden.' );
		expect( result( amber, 'home' ).verdict ).toBe( 'green' );

		await page.getByRole( 'button', { name: 'Accept as baseline' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Accepted as the new baseline' );
		expect( getRun( amber.id ).results.every( ( item ) => item.is_baseline === 1 ) ).toBe( true );

		const next = await runNow( page );
		expect( next.verdict ).toBe( 'green' );
		setFixture( '1.1' );
		// With main back, everything below it moves: start the browser baselines over.
		sql( "UPDATE wp_axle_smoke_results SET is_baseline = 0 WHERE tier = 'browser'" );
	} );

	test( 'a deploy (code changed outside WordPress) queues a deploy run', () => {
		wp( 'cron', 'event', 'run', 'axle_smoke_test_deploy_check' );
		settleRuns();
		wpEval( '$f = WP_PLUGIN_DIR . "/smoke-fixture/smoke-fixture.php"; file_put_contents( $f, str_replace( "Version: 1.1", "Version: 1.1.1", file_get_contents( $f ) ) ); touch( $f, time() + 5 );' );
		wp( 'cron', 'event', 'run', 'axle_smoke_test_deploy_check' );

		const run = getRun();
		expect( run.trigger_type ).toBe( 'deploy' );
		expect( run.status ).toBe( 'queued' );
		expect( run.context.items ).toContainEqual( expect.objectContaining( { type: 'plugin', id: 'smoke-fixture/smoke-fixture.php', from: '1.1', to: '1.1.1' } ) );
		expect( run.summary ).toEqual( [] );

		// Run it now from WP-CLI, like a deploy pipeline would.
		const cli = wpStatus( 'axle-smoke-test', 'run' );
		expect( cli.code ).toBe( 0 );
		expect( getRun( run.id ).verdict ).toBe( 'green' );
		setFixture( '1.1' );
	} );

	test( 'custom key URLs: added on the settings screen, checked with their expected status, removed again', async ( { page } ) => {
		settleRuns();
		await page.goto( `${ SCREEN }&tab=settings` );
		await page.getByLabel( 'URL or path' ).fill( '/gone-for-good/' );
		await page.getByLabel( 'Label', { exact: true } ).fill( 'Old landing page' );
		await page.getByLabel( 'Expected status' ).fill( '404' );
		await page.getByRole( 'button', { name: 'Add URL' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'URL added.' );
		await expect( page.locator( '.ast-urls' ) ).toContainText( 'Old landing page' );

		const saved = JSON.parse( wp( 'option', 'get', 'axle_smoke_test_urls', '--format=json' ) );
		expect( saved[ 0 ] ).toMatchObject( { label: 'Old landing page', expected: [ 404 ], source: 'custom' } );

		wp( 'axle-smoke-test', 'run', `--urls=${ saved[ 0 ].key }` );
		const run = getRun();
		expect( run.results ).toHaveLength( 1 );
		expect( run.results[ 0 ] ).toMatchObject( { http_status: 404, verdict: 'green' } );

		await page.getByRole( 'button', { name: 'Remove Old landing page' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'URL removed.' );
		expect( JSON.parse( wp( 'option', 'get', 'axle_smoke_test_urls', '--format=json' ) ) ).toEqual( [] );
	} );

	test( 'settings: the webhook URL and basic-auth password are stored encrypted and never shown back', async ( { page } ) => {
		await page.goto( `${ SCREEN }&tab=settings` );
		await page.getByLabel( 'Webhook URL' ).fill( 'https://hooks.example.com/services/T000/B000/secret-token' );
		await page.getByLabel( 'Webhook format' ).selectOption( 'slack' );
		await page.getByLabel( 'Basic auth password' ).fill( 'staging-pass' );
		await page.getByLabel( 'Alert on' ).selectOption( 'amber' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
		await expect( page.locator( '#ast-webhook-help' ) ).toContainText( 'oken' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'secret-token' );
		await expect( page.getByLabel( 'Webhook URL' ) ).toHaveValue( '' );

		const settings = JSON.parse( wp( 'option', 'get', 'axle_smoke_test_settings', '--format=json' ) );
		expect( settings.webhook_url ).toMatch( /^ast1:/ );
		expect( settings.auth_pass ).toMatch( /^ast1:/ );
		expect( JSON.stringify( settings ) ).not.toContain( 'secret-token' );
		expect( JSON.stringify( settings ) ).not.toContain( 'staging-pass' );
		expect( settings ).toMatchObject( { webhook_format: 'slack', alert_on: 'amber' } );
		wp( 'option', 'delete', 'axle_smoke_test_settings' );
	} );

	test( 'a run waiting for a browser is finished by the next admin page view', async ( { page } ) => {
		settleRuns();
		const id = Number( wpEval( '$run = AryanX\\Axle\\SmokeTest\\Plugin::instance()->runner()->start( "schedule", array(), 0 ); echo $run["id"];' ) );
		expect( getRun( id ).status ).toBe( 'waiting_browser' );

		await page.goto( '/wp-admin/index.php' );
		const panel = page.locator( '.ast-panel' );
		await expect( panel ).toBeVisible();
		await expect( panel ).toHaveAttribute( 'data-verdict', /green|amber|red/, { timeout: 60000 } );
		const run = getRun( id );
		expect( run.verdict, problems( run ) ).toBe( 'green' );
		expect( run ).toMatchObject( { status: 'done', browser_done: 1, verdict: 'green' } );
		// The scheduled run had no user, so the wp-admin URLs were checked as signed out.
		expect( result( run, 'dashboard-signed-out' ).http_status ).toBe( 302 );
	} );

	test( 'Site Health and the admin notice report a failed run', async ( { page } ) => {
		settleRuns();
		setFixture( '1.2' );
		const cli = wpStatus( 'axle-smoke-test', 'run' );
		expect( cli.code ).toBe( 1 );
		setFixture( '1.1' );

		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.ast-alert-notice' ) ).toContainText( 'The last smoke test failed.' );
		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.getByRole( 'button', { name: 'Last smoke test failed' } ) ).toBeVisible( { timeout: 30000 } );

		await page.goto( '/wp-admin/index.php' );
		await page.locator( '.ast-alert-notice' ).getByRole( 'button', { name: 'Dismiss the smoke test notice' } ).click();
		await expect( page.locator( '.ast-alert-notice' ) ).toHaveCount( 0 );
		expect( wpStatus( 'option', 'get', 'axle_smoke_test_notice' ).code ).toBe( 1 );
	} );

	test( 'an editor cannot open the screen, the REST API or the abilities', async ( { browser } ) => {
		try {
			wp( 'user', 'get', 'eddie', '--field=ID' );
		} catch ( error ) {
			wp( 'user', 'create', 'eddie', 'eddie@example.com', '--role=editor', '--user_pass=editor-pass-123' );
		}
		const { context, page } = await logInAs( browser, 'eddie', 'editor-pass-123' );
		const response = await page.goto( SCREEN );
		expect( response.status() ).toBe( 403 );
		await context.close();

		const client = await appPasswordClient( 'eddie', 'e2e editor' );
		expect( ( await client.call( 'GET', '/axle-smoke-test/v1/runs' ) ).status ).toBe( 403 );
		expect( ( await client.call( 'POST', '/axle-smoke-test/v1/runs', {} ) ).status ).toBe( 403 );
		if ( hasAbilities() ) {
			expect( ( await client.call( 'GET', '/wp-abilities/v1/abilities/axle-smoke-test/list-runs/run' ) ).status ).toBe( 403 );
			expect( ( await client.call( 'POST', '/wp-abilities/v1/abilities/axle-smoke-test/run-smoke-test/run', { input: {} } ) ).status ).toBe( 403 );
		}
		await client.dispose();
		wp( 'user', 'delete', 'eddie', '--yes' );
	} );

	test( 'abilities: run, list, read, list URLs and accept a baseline', async () => {
		test.skip( ! hasAbilities(), 'The Abilities API arrived in WordPress 6.9.' );
		settleRuns();
		const client = await appPasswordClient( 'admin', 'e2e abilities' );
		const run = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-smoke-test/run-smoke-test/run', { input: { urls: [ 'home', 'rest' ] } } );
		expect( run.status ).toBe( 200 );
		expect( run.body ).toMatchObject( { status: 'done', verdict: 'green', trigger_type: 'rest' } );
		expect( run.body.note ).toContain( 'Server tier only' );
		expect( run.body.results ).toHaveLength( 2 );

		const list = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-smoke-test/list-runs/run?input[limit]=1' );
		expect( list.body[ 0 ].id ).toBe( run.body.id );
		const one = await client.call( 'GET', `/wp-abilities/v1/abilities/axle-smoke-test/get-run/run?input[id]=${ run.body.id }` );
		expect( one.body.results.map( ( item ) => item.url_key ) ).toEqual( [ 'home', 'rest' ] );
		const urls = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-smoke-test/list-urls/run' );
		expect( urls.body.map( ( url ) => url.key ) ).toContain( 'not-found' );
		const accepted = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-smoke-test/accept-baseline/run', { input: { id: run.body.id, url_key: 'rest' } } );
		expect( accepted.body ).toEqual( { accepted: 1 } );
		expect( sql( `SELECT is_baseline FROM wp_axle_smoke_results WHERE run_id = ${ run.body.id } AND url_key = 'rest'` ).trim() ).toBe( '1' );

		const annotations = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-smoke-test/rollback-update' );
		expect( annotations.body.meta.annotations ).toMatchObject( { destructive: true, readonly: false } );
		await client.dispose();
	} );

	test( 'Axle Read-Only Mode blocks rollbacks while the site is frozen', async () => {
		settleRuns();
		wp( 'plugin', 'install', READ_ONLY_MODE, '--activate', '--force' );
		offer( '1.2' );
		wp( 'plugin', 'update', 'smoke-fixture' );
		offer( '' );
		expect( wpStatus( 'axle-smoke-test', 'run' ).code ).toBe( 1 );
		const red = getRun();
		expect( red.verdict ).toBe( 'red' );

		wp( 'axle-read-only-mode', 'on', '--minutes=10' );
		try {
			const cli = wpStatus( 'axle-smoke-test', 'rollback', String( red.id ), '--yes' );
			expect( cli.code ).toBe( 1 );
			expect( cli.out ).toContain( 'read-only mode' );
			if ( hasAbilities() ) {
				const client = await appPasswordClient( 'admin', 'e2e rollback' );
				const refused = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-smoke-test/rollback-update/run', { input: { id: red.id } } );
				expect( refused.status ).toBeGreaterThanOrEqual( 400 );
				await client.dispose();
			}
			expect( fixtureVersion() ).toBe( '1.2' );
		} finally {
			wp( 'axle-read-only-mode', 'off' );
			wp( 'plugin', 'deactivate', 'axle-read-only-mode' );
		}

		const done = wpStatus( 'axle-smoke-test', 'rollback', String( red.id ), '--yes' );
		expect( done.code ).toBe( 0 );
		expect( fixtureVersion() ).toBe( '1.1' );
	} );

	test( 'an external worker uploads results and a screenshot with an application password', async ( { request } ) => {
		settleRuns();
		const id = Number( wpEval( '$run = AryanX\\Axle\\SmokeTest\\Plugin::instance()->runner()->start( "manual", array( "browser" => false, "urls" => array( "home", "latest-post" ) ), 1 ); echo $run["id"];' ) );
		const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
		const worker = await appPasswordClient( 'admin', 'e2e worker' );

		const plan = await worker.call( 'GET', `/axle-smoke-test/v1/runs/${ id }` );
		expect( plan.status ).toBe( 200 );
		expect( plan.body.urls.map( ( url ) => url.key ) ).toEqual( [ 'home', 'latest-post' ] );

		const stored = await worker.call( 'POST', `/axle-smoke-test/v1/runs/${ id }/results`, {
			tier: 'worker',
			url_key: 'home',
			data: { http_status: 200, js_errors: [ { message: 'Worker saw an error', url: 'https://cdn.example.net/x.js' } ], layout: {}, thumbnail: png },
		} );
		expect( stored.status ).toBe( 201 );
		expect( stored.body.verdict ).toBe( 'amber' );
		const bad = await worker.call( 'POST', `/axle-smoke-test/v1/runs/${ id }/results`, { tier: 'worker', url_key: 'latest-post', data: { thumbnail: 'bm90IGEgcG5n' } } );
		expect( bad.status ).toBe( 400 );
		const done = await worker.call( 'POST', `/axle-smoke-test/v1/runs/${ id }/complete`, { tier: 'worker' } );
		expect( done.body ).toMatchObject( { worker_done: 1, verdict: 'amber' } );
		await worker.dispose();

		const run = getRun( id );
		const home = result( run, 'home', 'worker' );
		expect( home.thumb_path ).toMatch( /\.png$/ );
		expect( wpEval( `echo file_exists( wp_get_upload_dir()["basedir"] . "/axle-smoke-test/${ home.thumb_path }" ) ? "yes" : "no";` ) ).toBe( 'yes' );

		// Only the capability gets the screenshot: as admin through REST, never as a plain file.
		const nonce = ( await ( await request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const image = await request.get( `/index.php?rest_route=/axle-smoke-test/v1/results/${ home.id }/thumbnail&_wpnonce=${ nonce }` );
		expect( image.status() ).toBe( 200 );
		expect( image.headers()[ 'content-type' ] ).toBe( 'image/png' );
		const anonymous = await ( await require( '@playwright/test' ).request.newContext( { baseURL: process.env.WP_BASE_URL || `http://localhost:${ process.env.WP_PORT || 8917 }` } ) ).get( `/index.php?rest_route=/axle-smoke-test/v1/results/${ home.id }/thumbnail` );
		expect( anonymous.status() ).toBe( 401 );
	} );
} );

