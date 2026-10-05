/**
 * Reports a click on a track-only link (see Cogito_RAR_Track_Only) as a
 * normal RARLink click. These links have no /go/ redirect at all — their
 * vanity URL IS their real destination, for sites (Amazon Associates
 * being the motivating case) whose own terms prohibit a disguised or
 * shortened affiliate link — so there's no server-side request of ours
 * to log a click from. This listener reports it directly instead, keyed
 * by post ID, as a same-tick, non-blocking beacon that never delays or
 * blocks the click itself.
 *
 * rarTrackOnlyCapture.links is { targetUrl: postId } for every currently
 * published, active, track-only link — an exact match against the
 * clicked link's own resolved .href (the browser normalises this to an
 * absolute URL, matching how the stored target URL already looks, since
 * it's stored as a full absolute URL to begin with).
 */
( function () {
	if ( typeof rarTrackOnlyCapture === 'undefined' || ! navigator.sendBeacon ) {
		return;
	}

	var links = rarTrackOnlyCapture.links || {};
	if ( ! Object.keys( links ).length ) {
		return;
	}

	function findTrackedLink( target ) {
		var node  = target;
		var depth = 0;

		while ( node && node !== document.body && depth < 10 ) {
			if ( node.tagName === 'A' && node.href && Object.prototype.hasOwnProperty.call( links, node.href ) ) {
				return node;
			}
			node = node.parentElement;
			depth++;
		}

		return null;
	}

	document.addEventListener(
		'click',
		function ( e ) {
			var link = findTrackedLink( e.target );
			if ( ! link ) {
				return;
			}

			var payload = JSON.stringify( {
				nonce: rarTrackOnlyCapture.nonce,
				post_id: links[ link.href ]
			} );

			try {
				navigator.sendBeacon( rarTrackOnlyCapture.restUrl, new Blob( [ payload ], { type: 'application/json' } ) );
			} catch ( err ) {
				// A blocked/failed beacon should never affect the click itself.
			}
		},
		true
	);
} )();
