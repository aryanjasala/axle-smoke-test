/**
 * Axle Smoke Test collector. Printed inline right after <head> on pages the
 * browser tier loads with ?axle_smoke=<token>, so it runs before any other
 * script. It records JavaScript errors, failed files, broken images, CSP
 * violations and console.error calls, then measures the landmarks once the
 * page has loaded and settled, and hands everything to the Smoke Test runner
 * in the parent window with postMessage (same origin only).
 */
( function () {
	'use strict';

	if ( window.axleSmokeCollector || window.parent === window ) {
		return;
	}

	var state = { errors: [], failed: [], csp: [], start: Date.now() };
	window.axleSmokeCollector = state;
	var origin = window.location.origin;

	function push( list, item ) {
		if ( list.length < 50 ) {
			list.push( item );
		}
	}

	function urlFromStack( stack, skip ) {
		var lines = String( stack || '' ).split( '\n' );
		var found = [];
		for ( var i = 0; i < lines.length; i++ ) {
			var match = /(https?:\/\/[^\s)]+?):(\d+):\d+/.exec( lines[ i ] );
			if ( match ) {
				found.push( match );
			}
		}
		var pick = found[ skip || 0 ] || found[ 0 ];
		return pick ? { url: pick[ 1 ], line: Number( pick[ 2 ] ) } : { url: '', line: 0 };
	}

	function text( value ) {
		if ( value instanceof Error ) {
			return value.message;
		}
		if ( 'object' === typeof value ) {
			try {
				return JSON.stringify( value );
			} catch ( e ) {
				return String( value );
			}
		}
		return String( value );
	}

	window.addEventListener(
		'error',
		function ( event ) {
			var target = event.target;
			if ( target && target !== window && target.tagName ) {
				var url = target.src || target.href || '';
				if ( url ) {
					push( state.failed, { url: url, kind: target.tagName.toLowerCase(), status: 0 } );
				}
				return;
			}
			push( state.errors, {
				message: String( event.message || ( event.error && event.error.message ) || 'Script error' ).slice( 0, 500 ),
				url: event.filename || '',
				line: event.lineno || 0,
				kind: 'error',
			} );
		},
		true
	);

	window.addEventListener( 'unhandledrejection', function ( event ) {
		var reason = event.reason;
		var where = urlFromStack( reason && reason.stack, 0 );
		push( state.errors, {
			message: ( 'Unhandled promise rejection: ' + text( reason ) ).slice( 0, 500 ),
			url: where.url,
			line: where.line,
			kind: 'rejection',
		} );
	} );

	document.addEventListener( 'securitypolicyviolation', function ( event ) {
		if ( state.csp.length < 20 ) {
			state.csp.push( event.violatedDirective + ' ' + event.blockedURI );
		}
	} );

	if ( window.console && 'function' === typeof window.console.error ) {
		var original = window.console.error;
		window.console.error = function () {
			try {
				// Frame 0 is this wrapper, frame 1 is whoever called console.error.
				var where = urlFromStack( new Error().stack, 1 );
				push( state.errors, {
					message: Array.prototype.map.call( arguments, text ).join( ' ' ).slice( 0, 500 ),
					url: where.url,
					line: where.line,
					kind: 'console',
				} );
			} catch ( e ) {}
			return original.apply( window.console, arguments );
		};
	}

	function visible( element ) {
		var rect = element.getBoundingClientRect();
		if ( rect.width <= 0 || rect.height <= 0 ) {
			return false;
		}
		if ( 'function' === typeof element.checkVisibility ) {
			return element.checkVisibility( { opacityProperty: true, visibilityProperty: true } );
		}
		var style = window.getComputedStyle( element );
		return 'hidden' !== style.visibility && 'none' !== style.display && 0 < parseFloat( style.opacity );
	}

	function measure( selectors ) {
		var layout = {};
		for ( var i = 0; i < selectors.length; i++ ) {
			var list;
			try {
				list = document.querySelectorAll( selectors[ i ] );
			} catch ( e ) {
				continue;
			}
			for ( var n = 0; n < list.length && n < 3; n++ ) {
				var rect = list[ n ].getBoundingClientRect();
				layout[ selectors[ i ] + ( n ? ' #' + ( n + 1 ) : '' ) ] = {
					x: Math.round( rect.left + window.scrollX ),
					y: Math.round( rect.top + window.scrollY ),
					w: Math.round( rect.width ),
					h: Math.round( rect.height ),
					visible: visible( list[ n ] ),
				};
			}
		}
		return layout;
	}

	function brokenImages() {
		var broken = [];
		for ( var i = 0; i < document.images.length && broken.length < 20; i++ ) {
			var image = document.images[ i ];
			if ( image.complete && image.currentSrc && 0 === image.naturalWidth ) {
				broken.push( { url: image.currentSrc, kind: 'img', status: 0 } );
			}
		}
		return broken;
	}

	var config = null;
	var loaded = false;
	var sent = false;

	function finish() {
		if ( sent || ! config || ! loaded ) {
			return;
		}
		sent = true;
		window.setTimeout( function () {
			var failed = state.failed.slice();
			var seen = {};
			failed.forEach( function ( item ) {
				seen[ item.url ] = true;
			} );
			brokenImages().forEach( function ( item ) {
				if ( ! seen[ item.url ] ) {
					failed.push( item );
				}
			} );
			window.parent.postMessage(
				{
					axleSmoke: 'result',
					data: {
						js_errors: state.errors.slice(),
						failed: failed,
						csp: state.csp.slice(),
						layout: measure( config.selectors || [] ),
						load_ms: Date.now() - state.start,
					},
				},
				origin
			);
		}, Math.max( 0, Number( config.settleMs ) || 0 ) );
	}

	window.addEventListener( 'message', function ( event ) {
		if ( event.origin !== origin || event.source !== window.parent || ! event.data || 'config' !== event.data.axleSmoke ) {
			return;
		}
		config = event.data;
		finish();
	} );

	window.addEventListener( 'load', function () {
		loaded = true;
		window.parent.postMessage( { axleSmoke: 'ready' }, origin );
		finish();
	} );
} )();
