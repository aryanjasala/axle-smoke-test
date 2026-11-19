const { test, expect, wp, wpEval } = require( './utils' );
const { installFixture, createContent, resetRuns, settleRuns, getRun, seedScreens, seedCoverage, scanCoverage, setFixture, offer } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-smoke-test';

/** Presses Run now and waits for the run it lands on to finish. */
async function runNow( page ) {
	await page.goto( SCREEN );
	await page.getByRole( 'button', { name: 'Run now' } ).click();
	await page.waitForURL( /[?&]run=\d+/, { timeout: 120000 } );
	const id = Number( new URL( page.url() ).searchParams.get( 'run' ) );
	const deadline = Date.now() + 90000;
	for ( ;; ) {
		const run = getRun( id );
		if ( run && [ 'done', 'waiting_browser' ].includes( run.status ) ) {
			return run;
		}
		if ( Date.now() > deadline ) {
			throw new Error( `Run ${ id } did not finish` );
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1000 ) );
	}
}

const coverageStatus = () => JSON.parse( wp( 'axle-smoke-test', 'coverage', 'status', '--format=json' ) )[ 0 ];

test.describe.serial( 'Axle Smoke Test: coverage, grouped findings and baselines', () => {
	test.beforeAll( () => {
		installFixture();
		createContent();
		resetRuns();
	} );

	test.afterAll( () => {
		offer( '' );
		setFixture( '1.1' );
		resetRuns();
	} );

	test( 'the coverage set picks one page per block type, shortcode and template, and a run checks them after the key URLs', async ( { page } ) => {
		seedCoverage();
		const status = coverageStatus();
		expect( status.enabled ).toBe( 'yes' );
		expect( Number( status.pages ) ).toBeGreaterThanOrEqual( 3 );
		expect( Number( status.blocks ) ).toBeGreaterThanOrEqual( 3 );
		expect( Number( status.shortcodes ) ).toBe( 1 );
		expect( Number( status.templates ) ).toBeGreaterThanOrEqual( 2 );

		await page.goto( SCREEN );
		await expect( page.locator( '.ast-coverage-text' ) ).toContainText( /Checks \d+ pages? covering \d+ block types?, 1 shortcode, \d+ templates?\./ );

		const run = await runNow( page );
		const covered = run.results.filter( ( item ) => item.url_key.startsWith( 'cov-' ) );
		expect( covered.length ).toBeGreaterThanOrEqual( 2 );
		expect( covered.length ).toBe( run.context.coverage.planned );
		expect( run.context.coverage.checked ).toBe( covered.length );
		await expect( page.locator( '.ast-coverage-run' ) ).toContainText( 'covering' );
		// A first look is green and becomes the baseline.
		expect( covered.every( ( item ) => item.verdict === 'green' ) ).toBe( true );
	} );

	test( 'a failure only the gallery-and-shortcode post shows is caught, grouped, and the key URLs stay green', async ( { page } ) => {
		settleRuns();
		wp( 'option', 'update', 'ast_fixture_widget_break', '1' );
		const run = await runNow( page );
		wp( 'option', 'delete', 'ast_fixture_widget_break' );

		expect( run.verdict ).toBe( 'red' );
		const keyUrls = run.results.filter( ( item ) => ! item.url_key.startsWith( 'cov-' ) && item.tier === 'server' );
		expect( keyUrls.every( ( item ) => item.verdict !== 'red' ) ).toBe( true );
		const broken = run.results.filter( ( item ) => item.url_key.startsWith( 'cov-' ) && item.verdict === 'red' );
		expect( broken ).toHaveLength( 1 );
		expect( broken[ 0 ].meta.reasons.map( ( reason ) => reason.code ) ).toContain( 'fatal' );

		await expect( page.locator( '.ast-finding' ).first() ).toContainText( '1 fatal error on 1 page' );
	} );

	test( 'the same error on many pages is one finding with the pages folded under it, and the table points at it', async ( { page } ) => {
		const seeded = seedScreens();
		await page.goto( `${ SCREEN }&run=${ seeded.red }` );

		const first = page.locator( '.ast-finding' ).first();
		await expect( first.locator( '.ast-finding-count' ) ).toHaveText( /^1 fatal error on \d+ pages$/ );
		await expect( first.locator( 'li' ).first() ).not.toBeVisible();
		await first.locator( 'summary' ).click();
		await expect( first.locator( 'li' ).first() ).toBeVisible();
		await expect( page.locator( '.ast-reason', { hasText: 'Same as the finding above' } ).first() ).toBeVisible();
		await expect( page.locator( 'h1.wp-heading-inline' ) ).toHaveText( 'Smoke Test' );
	} );

	test( 'a page that failed on status or a fatal error cannot be accepted as the baseline; a changed one can, with an Undo', async ( { page } ) => {
		const seeded = seedScreens();

		await page.goto( `${ SCREEN }&run=${ seeded.red }` );
		const row = page.locator( 'tr.ast-result-red[data-tier="server"]' ).first();
		await expect( row ).toContainText( 'Failed, so it cannot be the baseline' );
		await expect( row.locator( 'summary', { hasText: /^Accept$/ } ) ).toHaveCount( 0 );
		const failed = getRun( seeded.red ).results.find( ( item ) => item.verdict === 'red' );
		try {
			wp( 'axle-smoke-test', 'baseline', 'accept', String( seeded.red ), `--url=${ failed.url_key }` );
		} catch ( error ) {
			// The command refuses, as it should.
		}
		expect( getRun( seeded.red ).results.find( ( item ) => item.id === failed.id ).is_baseline ).toBe( 0 );
	} );

	test( 'accepting an amber page as the baseline can be undone', async ( { page } ) => {
		const seeded = seedScreens();
		const before = getRun( seeded.amber ).results.filter( ( item ) => item.is_baseline === 1 ).length;

		await page.goto( `${ SCREEN }&run=${ seeded.amber }` );
		const row = page.locator( 'tr.ast-result-amber[data-tier="server"]' ).first();
		await row.locator( 'summary', { hasText: /^Accept$/ } ).click();
		const confirm = row.locator( '.axle-confirm', { hasText: 'becomes its baseline' } );
		await expect( confirm ).toContainText( 'You can undo it right after' );
		await confirm.getByRole( 'button', { name: 'Replace the baseline' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Accepted as the new baseline' );
		expect( getRun( seeded.amber ).results.filter( ( item ) => item.is_baseline === 1 ).length ).toBeGreaterThan( before );

		await page.locator( '.ast-notice' ).getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Taken back' );
		expect( getRun( seeded.amber ).results.filter( ( item ) => item.is_baseline === 1 ).length ).toBe( before );
	} );

	test( 'the shared hooks: run_now queues a run, and run_finished carries id, verdict, source and finished', () => {
		settleRuns();
		const out = wpEval(
			`$seen = array(); add_action( 'axle_smoke_test_run_finished', function ( $run ) use ( &$seen ) { $seen[] = array_intersect_key( $run, array_flip( array( 'id', 'verdict', 'source', 'finished', 'final' ) ) ); } );
			do_action( 'axle_smoke_test_run_now', 'e2e-source' );
			$r = AryanX\\Axle\\SmokeTest\\Plugin::instance()->runner(); $run = $r->runs()->latest( array( 'queued' ) );
			$r->execute( (int) $run['id'], true );
			echo wp_json_encode( array( 'queued' => $run['status'], 'seen' => $seen ) );`
		);
		const data = JSON.parse( out );
		expect( data.queued ).toBe( 'queued' );
		const last = data.seen[ data.seen.length - 1 ];
		expect( last.source ).toBe( 'e2e-source' );
		expect( [ 'green', 'amber', 'red' ] ).toContain( last.verdict );
		expect( last.finished ).toBeGreaterThan( 1700000000 );
		expect( last.id ).toBeGreaterThan( 0 );
	} );

	test( 'a rescan after a picked post is trashed finds another page', () => {
		seedCoverage();
		const picked = Number( wpEval( 'echo count( array_unique( array_column( AryanX\\Axle\\SmokeTest\\Coverage::state()["picks"], "post" ) ) );' ) );
		const sample = wp( 'post', 'list', '--post_type=post', '--s=Coverage sample gallery', '--field=ID' ).split( '\n' )[ 0 ];
		wp( 'post', 'delete', sample );
		expect( wpEval( 'echo AryanX\\Axle\\SmokeTest\\Coverage::state()["scan"] ? "scanning" : "idle";' ) ).toBe( 'scanning' );
		scanCoverage();
		const after = Number( wpEval( 'echo count( array_unique( array_column( AryanX\\Axle\\SmokeTest\\Coverage::state()["picks"], "post" ) ) );' ) );
		expect( after ).toBeLessThanOrEqual( picked );
		expect( wpEval( 'echo in_array( ' + sample + ', array_column( AryanX\\Axle\\SmokeTest\\Coverage::state()["picks"], "post" ), true ) ? "still" : "gone";' ) ).toBe( 'gone' );
	} );

	test( 'the settings screen switches the coverage check off, and a run then checks key URLs only', async ( { page } ) => {
		seedCoverage();
		await page.goto( `${ SCREEN }&tab=settings` );
		await page.getByLabel( 'After the key URLs, also open the newest page for every block type, shortcode and template the site uses.' ).uncheck();
		await page.getByRole( 'button', { name: 'Save settings' } ).first().click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved' );
		settleRuns();
		const run = await runNow( page );
		expect( run.results.filter( ( item ) => item.url_key.startsWith( 'cov-' ) ) ).toHaveLength( 0 );
		await page.goto( SCREEN );
		await expect( page.locator( '.ast-coverage' ) ).toContainText( 'The coverage check is off' );
	} );
} );
