/**
 * Captures the clicked RARLink's text and CSS classes (the browser-side
 * context our server-side redirect can never see) and reports them via a
 * non-blocking beacon, tagged with a one-time token appended to the
 * outgoing URL. See class-cogito-rar-conversion-click-context.php for how
 * that token gets matched back up at send time.
 */
( function () {
	if ( typeof rarClickContext === 'undefined' || ! navigator.sendBeacon ) {
		return;
	}

	var prefix = rarClickContext.prefix || '/go/';

	function findRarLink( el ) {
		while ( el && el.tagName !== 'A' ) {
			el = el.parentElement;
		}
		if ( ! el || ! el.href ) {
			return null;
		}

		var path;
		try {
			path = new URL( el.href, window.location.href ).pathname;
		} catch ( err ) {
			return null;
		}

		return path.indexOf( prefix ) === 0 ? el : null;
	}

	document.addEventListener(
		'click',
		function ( e ) {
			var link = findRarLink( e.target );
			if ( ! link ) {
				return;
			}

			// Same-tick, so the mutated href is what the browser actually
			// navigates to — never delays or blocks the click.
			var token = Date.now().toString( 36 ) + '_' + Math.random().toString( 36 ).slice( 2, 10 );
			var sep   = link.href.indexOf( '?' ) === -1 ? '?' : '&';
			link.href = link.href + sep + '_rct=' + token;

			var payload = JSON.stringify( {
				token: token,
				link_text: ( link.textContent || '' ).trim().slice( 0, 150 ),
				link_classes: link.className || ''
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
