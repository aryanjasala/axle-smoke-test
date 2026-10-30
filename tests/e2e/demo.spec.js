// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { installFixture, createContent, resetRuns, offer, setFixture, settleRuns } = require( './seed' );

test( 'demo: an update breaks the site, the smoke test catches it, one click rolls it back', async ( { browser } ) => {
	test.setTimeout( 240_000 );
	prepareSite( 'Bean There Coffee' );
	installFixture();
	createContent();
	resetRuns();
	// A first green run sets the baseline.
	wp( 'axle-smoke-test', 'run' );
	settleRuns();
	offer( '1.2' );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/plugins.php?plugin_status=upgrade' );
			await demo.start();
			await demo.pause( 1200 );

			// Update the plugin like on any normal day.
			await demo.click( page.locator( 'tr.plugin-update-tr[data-slug="smoke-fixture"] a.update-link' ), 300 );
			await expect( page.locator( '.ast-panel' ) ).toHaveAttribute( 'data-verdict', 'red', { timeout: 90_000 } );
			await demo.moveTo( page.locator( '.ast-panel' ) );
			await demo.pause( 2500 );

			// What broke, and who broke it.
			await demo.click( page.locator( '.ast-panel-link' ), 300 );
			await expect( page.locator( '.ast-problems' ) ).toBeVisible();
			await demo.moveTo( page.locator( '.ast-results tbody tr' ).first() );
			await demo.pause( 2500 );

			// One click back to the version that worked, and the check runs again.
			await demo.click( page.getByRole( 'button', { name: /Roll back Smoke Fixture/ } ), 300 );
			await expect( page.locator( '.notice-success' ).first() ).toContainText( 'Rolled back.' );
			await expect( page.locator( '.ast-run-title .ast-badge' ) ).toHaveText( 'Green', { timeout: 60_000 } );
			await demo.pause( 3000 );
		} );
	} finally {
		offer( '' );
	}

	expect( wp( 'plugin', 'get', 'smoke-fixture', '--field=version' ) ).toBe( '1.1' );
	setFixture( '1.1' );
	resetRuns();
} );
