// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp } = require( './utils' );
const { STORAGE_STATE } = require( './utils/config' );
const { prepareSite, shot } = require( './utils/media' );
const { seedScreens, offer, setFixture, settleRuns, resetRuns } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-smoke-test';

test.describe( 'Screenshots', () => {
	let context;
	let page;
	let seeded;

	test.beforeAll( async ( { browser } ) => {
		test.setTimeout( 120_000 );
		prepareSite( 'Bean There Coffee' );
		seeded = seedScreens();
		context = await browser.newContext( { storageState: STORAGE_STATE, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		page = await context.newPage();
	} );

	test.afterAll( async () => {
		await context.close();
		offer( '' );
		setFixture( '1.1' );
		resetRuns();
	} );

	test( '1: a red run after an update, with the rollback', async () => {
		await page.goto( `${ SCREEN }&run=${ seeded.red }` );
		await expect( page.locator( '.ast-problems' ) ).toBeVisible();
		await shot( page, 1, { fullPage: true } );
	} );

	test( '2: the runs list', async () => {
		await page.goto( SCREEN );
		await expect( page.locator( '.ast-runs tbody tr' ) ).toHaveCount( 3 );
		await shot( page, 2 );
	} );

	test( '3: the check right after an update on the Plugins screen', async () => {
		test.setTimeout( 120_000 );
		settleRuns();
		offer( '1.2' );
		await page.goto( '/wp-admin/plugins.php?plugin_status=upgrade' );
		await page.locator( 'tr.plugin-update-tr[data-slug="smoke-fixture"] a.update-link' ).click();
		await expect( page.locator( '.ast-panel' ) ).toHaveAttribute( 'data-verdict', 'red', { timeout: 90_000 } );
		offer( '' );
		await shot( page, 3 );
		setFixture( '1.1' );
	} );

	test( '4: an amber run with a JavaScript error from the browser check', async () => {
		await page.goto( `${ SCREEN }&run=${ seeded.amber }` );
		await page.locator( '.ast-result-amber[data-tier="browser"]' ).scrollIntoViewIfNeeded();
		await shot( page, 4 );
	} );

	test( '5: settings', async () => {
		wp( 'axle-smoke-test', 'urls', 'add', '/checkout/', '--expect=200,302', '--label=Checkout' );
		await page.goto( `${ SCREEN }&tab=settings` );
		await shot( page, 5 );
		wp( 'option', 'delete', 'axle_smoke_test_urls' );
	} );
} );
