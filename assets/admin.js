/**
 * Axle Smoke Test: the in-place confirm steps and the spoken notices on
 * Tools > Smoke Test.
 *
 * A confirm step is a details element, so it opens without this script. The
 * script moves focus into the question when it opens, back to its button when
 * it closes, and lets Escape close it.
 */
( function ( wp ) {
	'use strict';

	function confirms() {
		document.querySelectorAll( '.ast-confirm' ).forEach( function ( details ) {
			var summary = details.querySelector( 'summary' );
			var cancel = details.querySelector( '.ast-cancel' );

			function close() {
				details.open = false;
				if ( summary ) {
					summary.focus();
				}
			}

			details.addEventListener( 'toggle', function () {
				if ( details.open ) {
					var target = details.querySelector( '.axle-confirm button' );
					if ( target ) {
						target.focus();
					}
				}
			} );
			details.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key && details.open ) {
					event.preventDefault();
					close();
				}
			} );
			if ( cancel ) {
				cancel.addEventListener( 'click', close );
			}
		} );
	}

	function speakNotices() {
		if ( ! wp || ! wp.a11y ) {
			return;
		}
		document.querySelectorAll( '.ast-notice[data-speak]' ).forEach( function ( notice ) {
			wp.a11y.speak( notice.getAttribute( 'data-speak' ), 'polite' );
		} );
	}

	function ready() {
		confirms();
		speakNotices();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )( window.wp );
