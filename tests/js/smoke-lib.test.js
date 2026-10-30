// Node unit tests for assets/smoke-lib.js: npm run test:unit:js
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const lib = require( '../../assets/smoke-lib.js' );

test( 'normaliseHtml ignores nonces, versions, generated ids and timestamps', () => {
	const a = '<div class="wp-container-core-group-is-layout-1a2b3c4d" data-nonce="0123456789"><script id="x-js-after">var n = "abc";</script><a href="/x?_wpnonce=abcdef0123&ver=1">x</a><time>2026-10-30T10:00:00+00:00</time><!-- 1761818400 --></div>';
	const b = '<div class="wp-container-core-group-is-layout-9f8e7d6c" data-nonce="9876543210"><script id="x-js-after">var n = "xyz";</script><a href="/x?_wpnonce=0123abcdef&ver=2">x</a><time>2026-10-31T11:30:00+00:00</time><!-- 1761904800 --></div>';
	assert.equal( lib.normaliseHtml( a ), lib.normaliseHtml( b ) );
	assert.notEqual( lib.normaliseHtml( a ), lib.normaliseHtml( '<div>other</div>' ) );
	// Block comments stay: they describe the structure.
	assert.match( lib.normaliseHtml( '<!-- wp:paragraph --><p>x</p>' ), /wp:paragraph/ );
} );

test( 'layoutDiff finds missing and moved landmarks past the threshold', () => {
	const before = {
		main: { x: 0, y: 100, w: 800, h: 600, visible: true },
		footer: { x: 0, y: 700, w: 800, h: 100, visible: true },
		aside: { x: 0, y: 0, w: 0, h: 0, visible: false },
	};
	const after = {
		main: { x: 0, y: 100, w: 800, h: 600, visible: false },
		footer: { x: 0, y: 730, w: 800, h: 100, visible: true },
	};
	assert.deepEqual( lib.layoutDiff( before, after, 40 ), [ { key: 'main', change: 'missing', delta: 0 } ] );
	assert.deepEqual( lib.layoutDiff( before, after, 20 ), [
		{ key: 'main', change: 'missing', delta: 0 },
		{ key: 'footer', change: 'moved', delta: 30 },
	] );
	assert.deepEqual( lib.layoutDiff( before, before, 1 ), [] );
} );

test( 'dedupeErrors drops repeats (numbers aside) and ignored patterns', () => {
	const errors = [
		{ message: 'Uncaught TypeError: a[3] is undefined', url: 'https://example.com/app.js?ver=1' },
		{ message: 'Uncaught TypeError: a[7] is undefined', url: 'https://example.com/app.js?ver=2' },
		{ message: 'ResizeObserver loop completed with undelivered notifications.', url: '' },
		{ message: 'Script error', url: '' },
		{ message: '', url: '' },
	];
	const kept = lib.dedupeErrors( errors, [ 'resizeobserver', '/^script error$/i' ] );
	assert.deepEqual(
		kept.map( ( error ) => error.message ),
		[ 'Uncaught TypeError: a[3] is undefined' ]
	);
	assert.equal( lib.dedupeErrors( errors, [], 1 ).length, 1 );
	assert.equal( lib.matchesAny( 'x', [ '/[/' ] ), false );
} );

test( 'verdict compares a browser result with its baseline', () => {
	const baseline = {
		js_errors: [ { message: 'Known error 1', url: 'https://example.com/a.js' } ],
		failed: [ { url: 'https://example.com/old.png?x=1' } ],
		layout: { main: { x: 0, y: 0, w: 100, h: 100, visible: true } },
	};
	assert.equal( lib.verdict( { js_errors: [ { message: 'Known error 2', url: 'https://example.com/a.js' } ], failed: [ { url: 'https://example.com/old.png?x=2' } ], layout: baseline.layout }, baseline, 40 ).verdict, 'green' );

	const amber = lib.verdict( { js_errors: [ { message: 'New thing broke' } ], failed: [], layout: { main: { visible: false } } }, baseline, 40 );
	assert.equal( amber.verdict, 'amber' );
	assert.deepEqual( amber.reasons.map( ( reason ) => reason.code ), [ 'js_error', 'layout' ] );

	// Without a baseline only errors count, not layout.
	assert.equal( lib.verdict( { js_errors: [], failed: [], layout: {} }, null, 40 ).verdict, 'green' );
	assert.equal( lib.verdict( { error: 'timed out' }, null, 40 ).verdict, 'amber' );
} );

test( 'runVerdict and worstOf pick the worst', () => {
	assert.equal( lib.runVerdict( [ 'green', 'amber', 'green' ] ), 'amber' );
	assert.equal( lib.runVerdict( [ 'amber', 'red' ] ), 'red' );
	assert.equal( lib.runVerdict( [] ), 'green' );
	assert.equal( lib.worstOf( 'red', 'green' ), 'red' );
} );
