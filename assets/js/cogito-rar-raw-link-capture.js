/**
 * Reports a click on a raw (non-RARLink) affiliate button or link as an
 * AffiliateClick conversion event. Unlike the RARLink click listener, there
 * is no server-side redirect of ours involved here — the browser navigates
 * straight to the merchant's own URL — so this reports the FULL event in
 * one beacon rather than just enriching one already being created.
 *
 * A click only counts if it matches one of Nate's own tracked class
 * names/IDs (rarRawLinkCapture.identifiers, curated on the Conversions
 * settings tab) walking up through parent elements, and resolves to an
 * external https:// link — internal navigation and RARLinks (already
 * handled by cogito-rar-click-context.js) are ignored.
 */
( function () {
	if ( typeof rarRawLinkCapture === 'undefined' || ! navigator.sendBeacon ) {
		return;
	}

	var identifiers = rarRawLinkCapture.identifiers || [];
	if ( ! identifiers.length ) {
		return;
	}

	var idSet = {};
	for ( var i = 0; i < identifiers.length; i++ ) {
		idSet[ identifiers[ i ] ] = true;
	}

	var goPrefix = rarRawLinkCapture.goPrefix || '/go/';
	var homeHost = rarRawLinkCapture.homeHost || window.location.host;

	function matchesTracked( el ) {
		if ( ! el ) {
			return false;
		}
		if ( el.classList ) {
			for ( var i = 0; i < el.classList.length; i++ ) {
				if ( idSet[ el.classList[ i ] ] ) {
					return true;
				}
			}
		}
		return !! ( el.id && idSet[ el.id ] );
	}

	// Walks up from the clicked element (up to 10 levels) looking for BOTH
	// an anchor and a tracked-identifier match anywhere along the way —
	// covers a class directly on the link itself, or on a wrapping
	// container (e.g. a "affi_btn_wrap" div around the actual <a>).
	function findRawLink( target ) {
		var anchor  = null;
		var matched = false;
		var node    = target;
		var depth   = 0;

		while ( node && node !== document.body && depth < 10 ) {
			if ( ! anchor && node.tagName === 'A' && node.href ) {
				anchor = node;
			}
			if ( matchesTracked( node ) ) {
				matched = true;
			}
			node = node.parentElement;
			depth++;
		}

		if ( ! anchor || ! matched ) {
			return null;
		}

		var url;
		try {
			url = new URL( anchor.href, window.location.href );
		} catch ( err ) {
			return null;
		}

		if ( 'https:' !== url.protocol ) {
			return null;
		}
		if ( url.host === homeHost ) {
			return null; // Internal link, not an affiliate destination.
		}

		return { anchor: anchor, url: url };
	}

	document.addEventListener(
		'click',
		function ( e ) {
			var found = findRawLink( e.target );
			if ( ! found ) {
				return;
			}

			var payload = JSON.stringify( {
				nonce: rarRawLinkCapture.nonce,
				destination_url: found.url.href,
				link_text: ( found.anchor.textContent || '' ).trim().slice( 0, 150 ),
				link_classes: found.anchor.className || ''
			} );

			try {
				navigator.sendBeacon( rarRawLinkCapture.restUrl, new Blob( [ payload ], { type: 'application/json' } ) );
			} catch ( err ) {
				// A blocked/failed beacon should never affect the click itself.
			}
		},
		true
	);
} )();
