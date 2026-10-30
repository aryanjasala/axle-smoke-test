/**
 * Axle Smoke Test runner: the browser tier.
 *
 * Loads each key URL in a hidden same-origin iframe with the run's browser
 * token, receives what the collector found, and posts it to the REST API.
 * Runs on Tools > Smoke Test (Run now), on the update screens (the "Checking
 * your site" panel) and on any admin page while a run waits for a browser.
 *
 * Hooks (wp.hooks, namespace axleSmokeTest), read when they are used, so a
 * script loaded later can still add them:
 * - filter axleSmokeTest.layoutSelectors( selectors, url )
 * - filter axleSmokeTest.ignoredJsErrors( patterns, url )
 * - filter axleSmokeTest.settleMs( ms, url )
 * - filter axleSmokeTest.collectResult( data, url, run )
 * - action axleSmokeTest.urlChecked( result, url, run )
 * - action axleSmokeTest.runFinished( run )
 */
( function ( wp, config, lib ) {
	'use strict';

	if ( ! wp || ! config || ! lib ) {
		return;
	}

	const { __, sprintf } = wp.i18n;
	const hooks = () => wp.hooks;
	const api = ( path, options = {} ) => wp.apiFetch( { path: '/axle-smoke-test/v1' + path, ...options } );

	/**
	 * Loads one URL in a hidden iframe and waits for the collector's result.
	 *
	 * @param {Object} url   { key, url, label }.
	 * @param {string} token Browser token.
	 * @return {Promise<Object>} Collected data.
	 */
	function checkInFrame( url, token ) {
		return new Promise( ( resolve ) => {
			const frame = document.createElement( 'iframe' );
			frame.className = 'ast-frame';
			frame.setAttribute( 'aria-hidden', 'true' );
			frame.setAttribute( 'tabindex', '-1' );
			frame.title = url.label;
			let done = false;
			let readyTimer = null;
			const finish = ( data ) => {
				if ( done ) {
					return;
				}
				done = true;
				window.clearTimeout( timer );
				window.clearTimeout( readyTimer );
				window.removeEventListener( 'message', onMessage );
				frame.remove();
				resolve( data );
			};
			const timer = window.setTimeout( () => finish( { error: __( 'The page did not finish loading within 30 seconds.', 'axle-smoke-test' ) } ), config.timeoutMs || 30000 );
			const onMessage = ( event ) => {
				if ( event.origin !== window.location.origin || event.source !== frame.contentWindow || ! event.data ) {
					return;
				}
				if ( 'ready' === event.data.axleSmoke ) {
					window.clearTimeout( readyTimer );
					const selectors = hooks().applyFilters( 'axleSmokeTest.layoutSelectors', config.selectors.slice(), url );
					const settleMs = Number( hooks().applyFilters( 'axleSmokeTest.settleMs', config.settleMs, url ) ) || 0;
					frame.contentWindow.postMessage( { axleSmoke: 'config', selectors, settleMs }, window.location.origin );
				} else if ( 'result' === event.data.axleSmoke ) {
					finish( event.data.data || {} );
				}
			};
			window.addEventListener( 'message', onMessage );
			frame.addEventListener( 'load', () => {
				// No collector: the page redirected somewhere else, or is not HTML.
				readyTimer = window.setTimeout( () => finish( { error: __( 'The page loaded, but the collector did not start.', 'axle-smoke-test' ) } ), 5000 );
			} );
			frame.src = url.url + ( url.url.includes( '?' ) ? '&' : '?' ) + 'axle_smoke=' + encodeURIComponent( token );
			document.body.appendChild( frame );
		} );
	}

	/**
	 * Runs the browser tier of a run.
	 *
	 * @param {Object}   plan       { run, token, urls }.
	 * @param {Function} onProgress Called with ( done, total, url ).
	 * @return {Promise<Object>} The finished run.
	 */
	async function runBrowserTier( plan, onProgress = () => {} ) {
		const run = plan.run;
		let done = 0;
		for ( const url of plan.urls ) {
			onProgress( done, plan.urls.length, url );
			let data = await checkInFrame( url, plan.token );
			const ignored = hooks().applyFilters( 'axleSmokeTest.ignoredJsErrors', [], url );
			data.js_errors = lib.dedupeErrors( data.js_errors || [], ignored );
			data = hooks().applyFilters( 'axleSmokeTest.collectResult', data, url, run );
			let stored = null;
			try {
				stored = await api( `/runs/${ run.id }/results`, { method: 'POST', data: { tier: 'browser', url_key: url.key, data } } );
			} catch ( error ) {
				stored = { error };
			}
			hooks().doAction( 'axleSmokeTest.urlChecked', stored, url, run );
			done++;
		}
		onProgress( done, plan.urls.length, null );
		const finished = await api( `/runs/${ run.id }/complete`, { method: 'POST', data: { tier: 'browser' } } );
		hooks().doAction( 'axleSmokeTest.runFinished', finished );
		return finished;
	}

	/** Verdict words for people. */
	const VERDICTS = {
		red: __( 'Red: something is broken', 'axle-smoke-test' ),
		amber: __( 'Amber: worth a look', 'axle-smoke-test' ),
		green: __( 'Green: all key pages look fine', 'axle-smoke-test' ),
	};

	/**
	 * Fills a status box with the finished run.
	 *
	 * @param {HTMLElement} box Box.
	 * @param {Object}      run Run.
	 */
	function showVerdict( box, run ) {
		const verdict = run.verdict || 'green';
		const counts = ( run.summary && run.summary.counts ) || {};
		box.classList.remove( 'ast-busy' );
		box.dataset.verdict = verdict;
		const status = box.querySelector( '.ast-panel-status' );
		status.textContent = '';
		const badge = document.createElement( 'span' );
		badge.className = `ast-badge ast-badge-${ verdict }`;
		badge.textContent = VERDICTS[ verdict ] || verdict;
		status.appendChild( badge );
		const text = document.createElement( 'span' );
		text.className = 'ast-panel-counts';
		text.textContent = ' ' + sprintf(
			/* translators: 1: red pages, 2: amber pages, 3: green pages. */
			__( '%1$d red, %2$d amber, %3$d green.', 'axle-smoke-test' ),
			counts.red || 0,
			counts.amber || 0,
			counts.green || 0
		);
		status.appendChild( text );
		const link = box.querySelector( '.ast-panel-link' );
		if ( link ) {
			link.href = config.screenUrl + '&run=' + run.id;
			link.hidden = false;
		}
	}

	/**
	 * Runs a plan inside a status box, with progress.
	 *
	 * @param {HTMLElement} box  Box.
	 * @param {Object}      plan Plan from the REST API.
	 * @return {Promise<Object>} Finished run.
	 */
	async function runInBox( box, plan ) {
		const status = box.querySelector( '.ast-panel-status' );
		box.classList.add( 'ast-busy' );
		if ( ! plan.urls || ! plan.urls.length ) {
			const run = plan.run.status === 'waiting_browser' ? await api( `/runs/${ plan.run.id }/complete`, { method: 'POST', data: { tier: 'browser' } } ) : plan.run;
			showVerdict( box, run );
			return run;
		}
		const run = await runBrowserTier( plan, ( done, total, url ) => {
			status.textContent = url
				? sprintf(
						/* translators: 1: page label, 2: pages done, 3: pages in total. */
						__( 'Checking %1$s in this browser (%2$d of %3$d)', 'axle-smoke-test' ),
						url.label,
						done + 1,
						total
				  )
				: __( 'Saving the results', 'axle-smoke-test' );
		} );
		showVerdict( box, run );
		return run;
	}

	/**
	 * Shows an error in a box.
	 *
	 * @param {HTMLElement} box   Box.
	 * @param {Object}      error Error from apiFetch.
	 */
	function showError( box, error ) {
		box.classList.remove( 'ast-busy' );
		box.dataset.verdict = 'error';
		box.querySelector( '.ast-panel-status' ).textContent = ( error && error.message ) || __( 'The smoke test could not run.', 'axle-smoke-test' );
	}

	/** Tools > Smoke Test: Run now. */
	function screen() {
		const button = document.querySelector( '.ast-run-now' );
		const box = document.querySelector( '.ast-run-box' );
		if ( ! button || ! box ) {
			return;
		}
		button.addEventListener( 'click', async ( event ) => {
			event.preventDefault();
			button.disabled = true;
			box.hidden = false;
			box.querySelector( '.ast-panel-status' ).textContent = __( 'Checking every key URL from the server', 'axle-smoke-test' );
			box.classList.add( 'ast-busy' );
			try {
				const plan = await api( '/runs', { method: 'POST', data: { browser: true } } );
				const run = await runInBox( box, plan );
				window.location.href = config.screenUrl + '&run=' + run.id + '&ran=1';
			} catch ( error ) {
				showError( box, error );
				button.disabled = false;
			}
		} );
	}

	/** Update screens and waiting runs: the "Checking your site" panel. */
	function panel() {
		const box = document.querySelector( '.ast-panel' );
		if ( ! box ) {
			return;
		}
		let busy = false;
		const start = async ( path ) => {
			if ( busy ) {
				return;
			}
			busy = true;
			box.hidden = false;
			box.classList.add( 'ast-busy' );
			box.querySelector( '.ast-panel-status' ).textContent = __( 'Checking your site after the change', 'axle-smoke-test' );
			try {
				const plan = await api( path, { method: 'POST' } );
				if ( ! plan || ! plan.run ) {
					box.hidden = true;
				} else {
					await runInBox( box, plan );
				}
			} catch ( error ) {
				showError( box, error );
			}
			busy = false;
		};

		if ( config.runId ) {
			start( `/runs/${ config.runId }/${ 'waiting_browser' === config.runStatus ? 'browser' : 'start' }` );
		}
		// Updates and installs through the Plugins and Themes screens happen over AJAX.
		if ( window.jQuery ) {
			window.jQuery( document ).on( 'wp-plugin-update-success wp-theme-update-success wp-plugin-install-success wp-theme-install-success', () => {
				window.setTimeout( () => start( '/runs/next' ), 500 );
			} );
		}
	}

	const ready = () => {
		screen();
		panel();
	};
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}

	// For other scripts and the tests.
	window.axleSmokeTest = { runBrowserTier, checkInFrame };
} )( window.wp, window.axleSmokeTestRunner, window.axleSmokeTestLib );
