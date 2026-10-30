/**
 * Axle Smoke Test: the pure logic shared by the browser runner, the reference
 * worker script and the Node unit tests. No DOM, no WordPress.
 *
 * Exposed as window.axleSmokeTestLib in the browser and module.exports in Node.
 */
( function ( root, factory ) {
	if ( 'object' === typeof module && module.exports ) {
		module.exports = factory();
	} else {
		root.axleSmokeTestLib = factory();
	}
} )( 'undefined' !== typeof self ? self : this, function () {
	'use strict';

	const ORDER = { green: 0, amber: 1, red: 2 };

	/**
	 * HTML with the parts that change on every load taken out, mirroring
	 * Analyzer::normalise() in PHP: inline JSON, nonces, version query strings,
	 * generated ids, timestamps and comments.
	 *
	 * @param {string} html HTML.
	 * @return {string} Normalised HTML.
	 */
	function normaliseHtml( html ) {
		return String( html )
			.replace( /<script\b[^>]*type=["']?application\/(?:ld\+)?json["']?[^>]*>[\s\S]*?<\/script>/gi, '<script type="application/json"></script>' )
			.replace( /<script\b[^>]*id=["']?[\w-]+-js-(?:extra|before|after)["']?[^>]*>[\s\S]*?<\/script>/gi, '<script></script>' )
			.replace( /<script\b[^>]*type=["']?importmap["']?[^>]*>[\s\S]*?<\/script>/gi, '<script type="importmap"></script>' )
			.replace( /(nonce["']?\s*[:=]\s*["']?)[a-f0-9]{10}/gi, '$1' )
			.replace( /(_wpnonce=)[a-f0-9]{10}/gi, '$1' )
			.replace( /(name=["']_wpnonce["'][^>]*value=["'])[^"']*/gi, '$1' )
			.replace( /(data-[\w-]*nonce=["'])[^"']*/gi, '$1' )
			.replace( /([?&](?:amp;)?ver=)[^"'&\s>]*/gi, '$1' )
			.replace( /([?&](?:amp;)?axle_smoke(?:_nocache)?=)[^"'&\s>]*/gi, '$1' )
			.replace( /\b(wp-(?:elements|container|duotone|block-[\w-]+-is-layout)-)[a-f0-9]{6,}\b/gi, '$1' )
			.replace( /\b(wp-container-(?:[\w-]+-)?is-layout-)\w+\b/gi, '$1' )
			.replace( /\bwp-elements-\d+\b/g, 'wp-elements-' )
			.replace( /\b(id=["'][\w-]*?)[a-f0-9]{8,}(["'])/gi, '$1$2' )
			.replace( /\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)?\b/g, '' )
			.replace( /\b1\d{9}(?:\.\d+)?\b/g, '' )
			.replace( /<!--(?!\s*\/?wp:)[\s\S]*?-->/g, '' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	/**
	 * Landmarks that went missing or moved past the threshold, mirroring
	 * Verdict::layout_changes() in PHP.
	 *
	 * @param {Object} before    Baseline layout: key => { x, y, w, h, visible }.
	 * @param {Object} after     Current layout.
	 * @param {number} threshold Pixels.
	 * @return {Array<{key: string, change: string, delta: number}>} Changes.
	 */
	function layoutDiff( before, after, threshold ) {
		const changes = [];
		Object.keys( before || {} ).forEach( ( key ) => {
			const box = before[ key ] || {};
			if ( ! box.visible ) {
				return;
			}
			const now = ( after || {} )[ key ];
			if ( ! now || ! now.visible ) {
				changes.push( { key, change: 'missing', delta: 0 } );
				return;
			}
			const delta = Math.max( ...[ 'x', 'y', 'w', 'h' ].map( ( axis ) => Math.round( Math.abs( ( Number( now[ axis ] ) || 0 ) - ( Number( box[ axis ] ) || 0 ) ) ) ) );
			if ( delta > threshold ) {
				changes.push( { key, change: 'moved', delta } );
			}
		} );
		return changes;
	}

	/**
	 * A JavaScript error's identity: where it came from and its message with
	 * numbers collapsed, so line numbers and ids do not make it look new.
	 *
	 * @param {Object} error { message, url }.
	 * @return {string} Signature.
	 */
	function errorSignature( error ) {
		const url = String( error.url || error.source || '' ).replace( /[?#].*$/, '' );
		return url + '|' + String( error.message || '' ).toLowerCase().replace( /\d+/g, '#' ).trim();
	}

	/**
	 * Errors with duplicates removed and ignored ones left out.
	 *
	 * @param {Array}         errors  Errors.
	 * @param {Array<string>} ignored Plain text, or /regex/ strings.
	 * @param {number}        max     Most errors kept.
	 * @return {Array} Errors.
	 */
	function dedupeErrors( errors, ignored = [], max = 50 ) {
		const seen = new Set();
		const out = [];
		for ( const error of errors || [] ) {
			const message = String( ( error && error.message ) || '' );
			if ( ! message || matchesAny( message, ignored ) ) {
				continue;
			}
			const signature = errorSignature( error );
			if ( seen.has( signature ) ) {
				continue;
			}
			seen.add( signature );
			out.push( error );
			if ( out.length >= max ) {
				break;
			}
		}
		return out;
	}

	/**
	 * Whether a message matches one of the patterns.
	 *
	 * @param {string}        message  Message.
	 * @param {Array<string>} patterns Plain text, or /regex/ strings.
	 * @return {boolean} Match.
	 */
	function matchesAny( message, patterns ) {
		return ( patterns || [] ).some( ( pattern ) => {
			pattern = String( pattern || '' );
			if ( ! pattern ) {
				return false;
			}
			const regex = /^\/(.+)\/([a-z]*)$/.exec( pattern );
			if ( regex ) {
				try {
					return new RegExp( regex[ 1 ], regex[ 2 ] ).test( message );
				} catch ( e ) {
					return false;
				}
			}
			return message.toLowerCase().includes( pattern.toLowerCase() );
		} );
	}

	/**
	 * A provisional verdict for one browser result, shown while the server
	 * stores it. The server's verdict (Verdict::evaluate() in PHP) is the one
	 * that counts.
	 *
	 * @param {Object}      result    { js_errors, failed, layout, error }.
	 * @param {Object|null} baseline  The same fields from the baseline, or null.
	 * @param {number}      threshold Layout threshold in pixels.
	 * @return {{verdict: string, reasons: Array}} Verdict.
	 */
	function verdict( result, baseline, threshold ) {
		const reasons = [];
		if ( result.error ) {
			reasons.push( { level: 'amber', code: 'browser_error', message: String( result.error ) } );
		}
		const known = new Set( ( ( baseline && baseline.js_errors ) || [] ).map( errorSignature ) );
		for ( const error of result.js_errors || [] ) {
			if ( ! known.has( errorSignature( error ) ) ) {
				reasons.push( { level: 'amber', code: 'js_error', message: String( error.message ) } );
			}
		}
		const missing = new Set( ( ( baseline && baseline.failed ) || [] ).map( ( item ) => String( item.url ).replace( /[?#].*$/, '' ) ) );
		for ( const item of result.failed || [] ) {
			if ( ! missing.has( String( item.url ).replace( /[?#].*$/, '' ) ) ) {
				reasons.push( { level: 'amber', code: 'missing_asset', message: String( item.url ) } );
			}
		}
		if ( baseline ) {
			for ( const change of layoutDiff( baseline.layout, result.layout, threshold ) ) {
				reasons.push( { level: 'amber', code: 'layout', message: change.key + ' ' + change.change } );
			}
		}
		return { verdict: reasons.reduce( ( worst, reason ) => worstOf( worst, reason.level ), 'green' ), reasons };
	}

	/**
	 * The worse of two verdicts.
	 *
	 * @param {string} a Verdict.
	 * @param {string} b Verdict.
	 * @return {string} Verdict.
	 */
	function worstOf( a, b ) {
		return ( ORDER[ b ] || 0 ) > ( ORDER[ a ] || 0 ) ? b : a;
	}

	/**
	 * The verdict of a run: the worst of its URL verdicts.
	 *
	 * @param {Array<string>} verdicts Verdicts.
	 * @return {string} Verdict.
	 */
	function runVerdict( verdicts ) {
		return ( verdicts || [] ).reduce( worstOf, 'green' );
	}

	return { normaliseHtml, layoutDiff, errorSignature, dedupeErrors, matchesAny, verdict, worstOf, runVerdict };
} );
