/**
 * Reports a click on a raw (non-RARLink) affiliate button, text link, or
 * native ad unit as a conversion event. Unlike the RARLink click listener,
 * there is no server-side redirect of ours involved here — the browser
 * navigates straight to the destination — so this reports the FULL event
 * in one beacon rather than just enriching one already being created.
 *
 * A click only counts if it matches one of Nate's own tracked class
 * names/IDs (curated on the Conversions settings tab) and resolves to an
 * external https:// link — internal navigation and RARLinks (already
 * handled by cogito-rar-click-context.js) are ignored.
 *
 * Matching is against TWO separate lists: rarRawLinkCapture.adIdentifiers
 * (native ad units → reported as AdvertisementClick) and .identifiers
 * (affiliate buttons/links → AffiliateClick). Each list is an array of
 * "groups" — a group is itself an array of 1+ class/id tokens that must
 * ALL be found somewhere in the clicked element's own classes/id or its
 * ancestors' (a "chained" match, not necessarily all on the same element);
 * a group with just one token behaves like a plain identifier always has.
 * Matching ANY one group in a list is enough for that list.
 */
( function () {
	if ( typeof rarRawLinkCapture === 'undefined' || ! navigator.sendBeacon ) {
		return;
	}

	var adGroups        = rarRawLinkCapture.adIdentifiers || [];
	var affiliateGroups = rarRawLinkCapture.identifiers || [];
	if ( ! adGroups.length && ! affiliateGroups.length ) {
		return;
	}

	var goPrefix = rarRawLinkCapture.goPrefix || '/go/';
	var homeHost = rarRawLinkCapture.homeHost || window.location.host;

	// Walks up from the clicked element (up to 10 levels), collecting every
	// class/id encountered along the way into one set, and separately
	// noting the nearest enclosing <a>. One pass serves every group in
	// both lists, rather than re-walking the DOM per group.
	function collectAncestorTokens( target ) {
		var tokens = {};
		var anchor = null;
		var node   = target;
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

		return { anchor: anchor, tokens: tokens };
	}

	function groupsMatch( groups, tokens ) {
		for ( var i = 0; i < groups.length; i++ ) {
			var group    = groups[ i ];
			var allFound = true;
			for ( var j = 0; j < group.length; j++ ) {
				if ( ! tokens[ group[ j ] ] ) {
					allFound = false;
					break;
				}
			}
			if ( allFound ) {
				return true;
			}
		}
		return false;
	}

	function findRawLink( target ) {
		var collected = collectAncestorTokens( target );
		if ( ! collected.anchor ) {
			return null;
		}

		// Ad checked first — the two lists shouldn't overlap in practice,
		// but if a class somehow appears in both, treat it as an ad.
		var eventName = null;
		if ( groupsMatch( adGroups, collected.tokens ) ) {
			eventName = 'AdvertisementClick';
		} else if ( groupsMatch( affiliateGroups, collected.tokens ) ) {
			eventName = 'AffiliateClick';
		}
		if ( ! eventName ) {
			return null;
		}

		var url;
		try {
			url = new URL( collected.anchor.href, window.location.href );
		} catch ( err ) {
			return null;
		}

		if ( 'https:' !== url.protocol ) {
			return null;
		}
		if ( url.host === homeHost ) {
			return null; // Internal link, not an affiliate/ad destination.
		}

		return { anchor: collected.anchor, url: url, eventName: eventName };
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
				event_name: found.eventName,
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
