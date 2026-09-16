/**
 * Captures the clicked RARLink's text and CSS classes (the browser-side
 * context our server-side redirect can never see) and reports them via a
 * non-blocking beacon, tagged with a one-time token appended to the
 * outgoing URL. See class-cogito-rar-conversion-click-context.php for how
 * that token gets matched back up at send time.
 *
 * A RARLink click is ALWAYS captured, regardless of class — that's not
 * what match_tokens is for. It's the FULL set of classes/IDs found
 * walking up from the clicked element (not just the anchor's own class,
 * which is all link_classes below reports) so the server can decide
 * WHICH event to report it as, exactly like a raw link — Nate puts the
 * same ad-unit classes directly on RARLink anchors too (e.g. the
 * homepage "Moto Partners" cards), and those should count the same way.
 */
( function () {
	if ( typeof rarClickContext === 'undefined' || ! navigator.sendBeacon ) {
		return;
	}

	var prefix = rarClickContext.prefix || '/go/';

	function findRarLink( el ) {
		var anchor = null;
		var tokens = {};
		var node   = el;
		var depth  = 0;

		while ( node && node !== document.body && depth < 10 ) {
			if ( ! anchor && node.tagName === 'A' && node.href ) {
				anchor = node;
			}
			if ( node.classList ) {
				for ( var i = 0; i < node.classList.length; i++ ) {
					tokens[ node.classList[ i ] ] = true;
				}
			}
			if ( node.id ) {
				tokens[ node.id ] = true;
			}
			node = node.parentElement;
			depth++;
		}

		if ( ! anchor ) {
			return null;
		}

		var path;
		try {
			path = new URL( anchor.href, window.location.href ).pathname;
		} catch ( err ) {
			return null;
		}

		return path.indexOf( prefix ) === 0 ? { anchor: anchor, tokens: tokens } : null;
	}

	document.addEventListener(
		'click',
		function ( e ) {
			var found = findRarLink( e.target );
			if ( ! found ) {
				return;
			}
			var link = found.anchor;

			// Same-tick, so the mutated href is what the browser actually
			// navigates to — never delays or blocks the click.
			var token = Date.now().toString( 36 ) + '_' + Math.random().toString( 36 ).slice( 2, 10 );
			var sep   = link.href.indexOf( '?' ) === -1 ? '?' : '&';
			link.href = link.href + sep + '_rct=' + token;

			var payload = JSON.stringify( {
				token: token,
				link_text: ( link.textContent || '' ).trim().slice( 0, 150 ),
				link_classes: link.className || '',
				match_tokens: Object.keys( found.tokens ).join( ' ' ),
				// The server's own $_SERVER['HTTP_REFERER'] capture (for the
				// eventual /go/ redirect request) can come back reduced to
				// just the origin (e.g. "https://renchlist.com/" with no
				// path) under a strict Referrer-Policy — window.location.href
				// is what JS itself sees right now, unaffected by that policy.
				page_url: window.location.href
			} );

			try {
				navigator.sendBeacon( rarClickContext.restUrl, new Blob( [ payload ], { type: 'application/json' } ) );
			} catch ( err ) {
				// A blocked/failed beacon should never affect the click itself.
			}
		},
		true
	);
} )();
