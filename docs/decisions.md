# Decisions

Code-level rationale that used to live as inline comments. Dated entries,
newest first. Each references the file/line as of the commit that made
the change — line numbers drift as the file changes, so check git blame
on the referenced line if this doc and the code look out of sync.

## 2026-10-31 — Advertiser matching across TLDs, plus a manual override

**Files:**
- `includes/networks/class-cogito-rar-rakuten-api.php` — `normalise_host()`,
  `base_name()`, `match_advertisers()`, `get_advertiser_by_id()`
- `includes/networks/class-cogito-rar-rakuten-links.php` — `generate()`
- `includes/settings/class-cogito-rar-settings-networks.php` — advertiser search
- `assets/js/cogito-rar-rakuten.js` — advertiser ID field

**The bug:** matching only did exact host or subdomain-to-parent. Rakuten
stores Belstaff as `belstaff.co.uk` and Bellroy as a regional domain, but
the product URLs being pasted were `.com`. Different host, no match, and
the tool refused to generate — for advertisers Nate is actually partnered
with.

**Fix:** after exact and subdomain matching both miss, compare the
registrable brand name with the TLD stripped, so `belstaff.com` matches
`belstaff.co.uk`. Multi-part suffixes (`co.uk`, `com.au`, …) are handled by
a hardcoded list rather than a public-suffix library, which would mean a
bundled dependency for one string operation.

A brand-name match is reported back as `confidence: 'name'` and labelled in
the UI as "matched by brand name, different domain", because it is a weaker
claim than a domain match and the operator should see that.

**Ambiguity is never guessed.** If two advertisers share a brand name
across TLDs, the match fails and the error names every candidate with its
domain and ID, for the operator to choose. Silently picking one would
attach a link to the wrong advertiser and lose the commission.

**Manual override.** The Edit screen now has an optional Advertiser ID
field beside the button, and the REST route already accepted
`advertiser_id`. Supplying one skips matching entirely. Matching should
never be the thing that blocks a link — Rakuten is the authority on whether
a URL is acceptable for an advertiser, and it returns a specific error when
it is not.

**Advertiser search.** The settings table rendered all 1736 rows unfiltered
and now has a search box, capped at 100 rows. The absence of one is part of
why the mismatch wasn't obvious: there was no practical way to look up what
had actually been stored for a given brand.

### A bug the test harness caught

`base_name()` was passing an already-bare host back through
`normalise_host()`, which used `wp_parse_url( $url, PHP_URL_HOST )` — that
returns nothing for a string with no scheme, so every brand-name comparison
silently compared `''` against `''`. The first fix therefore did nothing,
and looked like it worked because the `base_name()` tests all passed full
URLs.

`normalise_host()` now falls back to treating a bare host as a host, while
still returning `''` for input that isn't one. Both suites pass clean under
`E_ALL` on 8.3: 17 checks on parsing and title derivation, 14 on matching.

---

## 2026-10-30 — Rakuten link generation: Edit screen, bulk tool, REST (phase 2)

**Files:**
- `includes/networks/class-cogito-rar-rakuten-links.php` (new) — shared
  generation, AJAX handler, REST routes, editor script enqueue
- `includes/networks/class-cogito-rar-rakuten-bulk.php` (new) — the bulk
  screen
- `assets/js/cogito-rar-rakuten.js` (new) — Edit-screen button
- `cogito-rar.php` (line 146)

All three entry points (Edit screen, bulk, REST) call
`Cogito_RAR_Rakuten_Links::generate()`, so advertiser resolution and error
handling behave identically regardless of how a link was requested.

### REST: two routes, both gated on `create_rar_redirects`

- `POST /wp-json/rar/v1/rakuten-link` — body `url`, optional
  `advertiser_id` and `name`; returns the deep link, advertiser ID and
  name.
- `GET /wp-json/rar/v1/rakuten-advertisers?search=` — capped at 50
  results, for when an assistant needs to pick an advertiser by hand.

`create_rar_redirects` means the RARLinks Assistant role can use both.
Deliberately *not* public: unlike the three click-capture beacon routes,
these spend Rakuten's rate limit and create entries in the publisher
dashboard, so they require a logged-in account with that capability.

Credentials are never returned by either route. The assistant sends a URL
and receives a link; it never sees the client ID, secret, account ID or
access token, and never contacts `api.linksynergy.com` itself. That is the
point of the design — Rakuten bars third-party API access, so every call
originates from renchlist.com with Nate's own credentials.

Rate limited to 60 generations per user per 5 minutes, shared between the
REST routes and the AJAX handler, using the same transient-counter
approach as the beacon routes.

### Link creation is a separate step from RARLink creation, over REST

The REST route returns a deep link; it does not create a RARLink. An
assistant calls `rakuten-link` first, then posts the result to
`/wp/v2/rar_redirect` as `meta._rar_target`. Two explicit calls rather than
one with hidden behaviour.

### Edit screen replaces the Target URL in place

The button sits under Target URL. It takes whatever is in that field,
generates the deep link, and overwrites the field with it. The original
merchant URL is not stored separately — it survives inside the deep link
as the `murl=` parameter, so nothing is lost.

On success it also sets Network to "Rakuten Advertising" and fills Brand
with the advertiser name **only if Brand is empty**, so a manually chosen
brand is never overwritten. The post title is sent as Rakuten's `name`
parameter, which is what identifies the link on their Deep Links page.

Nothing is saved until the post is saved — the button only changes form
fields.

### Bulk tool is two steps, and preview makes no API calls

Paste → Preview resolves each line against the synced advertiser list
locally, with no Rakuten calls at all. Only on confirm are deep links
generated.

This matters because generated links persist in Rakuten's Publisher
Dashboard. Generating during preview would litter that dashboard with
links for batches that were reconsidered and abandoned.

Input accepts `URL` or `URL | Title` per line.

### Bulk limits: 25 rows, 700ms apart

The Deep Links API is one link per request at 100 calls/minute, so a batch
is a sequential loop. 25 rows at 700ms is roughly 17 seconds worst case,
which stays inside typical `max_execution_time` on shared hosting. Anything
beyond 25 lines is previewed but not processed, with a note to run a second
batch.

A background queue would lift that cap, but adds failure modes that are not
worth it until batches routinely exceed 25.

### The bulk tool sets meta the other paths get for free

`wp_insert_post()` bypasses both the classic Edit-screen save handler
(which needs `$_POST`) and the REST hooks in
`Cogito_RAR_Rest_Access`, so none of the usual defaults get applied. The
bulk tool therefore writes them explicitly: `_rar_type`, `_rar_active`,
`_rar_nofollow` and `_rar_sponsored` from the site defaults, and
`_rar_track_only`, `_rar_geo_enabled`, `_rar_rotation_enabled`, `_rar_geo`
and `_rar_rotation` to their off/empty values.

`_rar_active` is the one that actually matters: without it,
`Cogito_RAR_Redirect_Engine` reads an empty string, fails its
`!== '1'` check and the link never redirects even once published.

Everything is created as a draft. Publishing stays manual.

### Title derivation

A line with no supplied title gets
`{Advertiser} – {Last URL segment, title-cased} [Rakuten Advertising]`,
with any file extension stripped. Titles are editable afterwards; this just
avoids a batch of indistinguishable drafts.

### Verified

The pure logic — host normalisation, advertiser matching including
subdomain-to-parent, input parsing and title derivation — is covered by 17
checks that pass clean under `E_ALL` on PHP 8.3. The harness lives in the
session scratchpad, not the repo. The live API paths are not covered by
those checks and need testing against Rakuten.

### Still open

- `u1` sub-tracking (could carry the RARLink post ID) is supported by the
  API client but unused. May require "Signature" on the account.
- Generated links are ordinary cloaked `/go/` redirects. Whether Rakuten's
  agreement permits cloaking is unconfirmed; if not, add their tracking
  domain to `Cogito_RAR_Track_Only::AUTO_DETECT_DOMAINS`.
- The advertiser list cannot be filtered to actual partnerships —
  `/v2/advertisers` has no such parameter and `can_partner` only appears
  when fetching one advertiser by ID. A URL can therefore match an
  advertiser Nate has no partnership with; that surfaces as
  `ACCESS_DENIED` at generation time, translated to plain English.

---

## 2026-10-30 — Rakuten Advertising API client and Affiliate Networks settings tab (phase 1)

**Files:**
- `includes/networks/class-cogito-rar-rakuten-api.php` (new)
- `includes/settings/class-cogito-rar-settings-networks.php` (new)
- `includes/settings/class-cogito-rar-settings-page.php`, `get_tabs()` (line 77)
- `cogito-rar.php` (line 146)

**What this is:** the first half of generating Rakuten affiliate links from
inside RARLinks — the API client, credential storage, token handling,
connection test and advertiser sync. The Edit-screen button, bulk tool and
REST endpoint come in phase 2, deliberately after the connection has been
verified against the live API rather than built on top of untested code.

**Why it exists at all:** Rakuten prohibits third parties accessing their
API, which rules out Claude or the MCP assistants calling Rakuten with
Nate's token. A server-side integration sidesteps that: the plugin holds
the credentials and makes the call from renchlist.com itself, so Rakuten
only ever sees a request from Nate's own server. The credentials never
leave the server and are never exposed through REST.

### Auth: no refresh tokens, by choice

Rakuten's `/token` endpoint returns both an access token (3600s) and a
refresh token, but their docs state that using the refresh token
*immediately expires the current access token* regardless of remaining
life. Under concurrent requests that is a race: two refreshes invalidate
each other.

The token endpoint allows 100 calls/minute and tokens last an hour, so
minting a fresh token on expiry costs roughly one call per hour. The
refresh flow buys nothing and introduces a failure mode, so it is not
implemented. `get_access_token()` caches in a transient with a 120-second
safety margin; `request()` retries once on a 401 after clearing the cache.

The transient is fine to lose under Docket Cache — a cache flush just
means minting a new token.

### Auth: an ambiguity in Rakuten's own docs

Rakuten's guide says the token request uses `grant_type=password` with the
scope in the body, but their own curl example sends only `scope`. The
client follows the curl example. If the live call returns a 400, adding
`grant_type=password` to the body in `get_access_token()` is the first
thing to try.

### Advertiser sync stores four fields, not the full payload

`/v2/advertisers` returns contact details per advertiser — name, job
title, email, phone — plus policies and feature flags. The sync keeps only
`id`, `name`, `url` and a normalised `host`, and discards the rest.

Link generation needs the ID, and domain matching needs the host. Nothing
in the flow needs advertiser contact details, and phase 2 exposes advertiser
data over REST to the assistant, so storing them would mean handing
commercial contact data to a third party for no reason.

The sync requests `deep_links=true`, so only advertisers that actually
support deep linking are stored.

### Domain matching

`resolve_advertiser_for_url()` matches the pasted URL's host against the
synced advertiser hosts: exact match first, then a suffix match so a
subdomain resolves to its parent advertiser. `www.` is stripped from both
sides. This is what makes "paste a URL, get the right advertiser"
automatic, and it only works because `/v2/advertisers` returns each
advertiser's own `url`.

A URL with no match returns null, and phase 2 should prompt for the
advertiser rather than guessing.

### Error messages

Rakuten's documented error codes (`ACCESS_DENIED`, `DEEP_LINK_DENIED`,
`CANNOT_RESOLVE_ADVERTISER`, `DEEP_LINKING_NOT_ENABLED`,
`URL_TEMPLATE_MISMATCH`, `UNEXPECTED_ERROR`, `ENTITY_DECODE_ERROR`) are
translated to plain English in `describe_error()`, since these surface
directly to whoever is pasting URLs. Anything unrecognised falls through
with the raw message and HTTP status attached.

### Known limits carried forward to phase 2

- The Deep Links API is **one link per request, no batch**, at 100
  calls/minute. The bulk tool must loop and throttle.
- `u1` (sub-tracking, could carry the RARLink post ID) is supported by
  `create_deep_link()` but not yet used. Rakuten's docs say it may require
  "Signature" enabled on the account — confirm before relying on it.
- Rakuten is not in `Cogito_RAR_Track_Only::AUTO_DETECT_DOMAINS`, so
  generated links will be ordinary cloaked `/go/` redirects. Unconfirmed
  whether Rakuten's agreement permits cloaking.

### Secret handling

The client secret renders as `********` once stored, and a save only
overwrites it when the field contains something other than the mask — so
saving the form without retyping the secret keeps it. All three options are
stored with `autoload = false`, as is the synced advertiser list, which can
run to hundreds of rows.

---

## 2026-10-19 — Removed the WP Rocket delay/defer exclusion for the three click-capture scripts

**Files:**
- `includes/conversions/class-cogito-rar-conversion-click-context.php`, `init()` (line 37)
- `includes/conversions/class-cogito-rar-conversion-raw-link-capture.php`, `init()` (line 40)
- `includes/class-cogito-rar-track-only-capture.php`, `init()` (line 30)

**What changed:** each of these three scripts used to force itself out of
WP Rocket's "Delay JavaScript Execution" and "Defer JS" — via the
`rocket_delay_js_exclusions` / `rocket_exclude_defer_js` filters, plus a
`data-no-optimize`/`data-no-defer`/`data-no-delay` attribute forced onto
the `<script>` tag itself (`script_loader_tag`) — regardless of what was
configured in WP Rocket's own admin UI. All three were removed.

**Why it was there:** each script attaches a click listener for
something the server can't otherwise see (a track-only link's click, an
affiliate button's click, link text/classes for a conversion event). The
reasoning on record was that WP Rocket's delay waits for a "user
interaction" to load scripts at all, and treats the very click being
tracked as that interaction — too late, since the listener wouldn't
exist yet when the click fires. This was noted as "confirmed live" —
i.e. observed breaking in production, not theoretical — though the
specific mechanism was never pinned down with a debugger.

**Why it was removed:** WP Rocket's own documentation
(https://docs.wp-rocket.me/article/1349-delay-javascript-execution) lists
the full set of trigger events: `keydown`, `keyup`, `mousedown`,
`mouseup`, `mousemove`, `mouseover`, `mouseout`, `touchmove`,
`touchstart`, `touchend`, `touchcancel`, `wheel`, `click`, `dblclick`,
`input`, and several `on*` variants. `mousedown` fires before `click` in
a normal click sequence, so the delayed script gets a trigger — and a
chance to attach its listener — before the `click` event that needs
catching actually fires. That undercuts the original "too late" claim.
The docs also note scripts are preloaded while delayed, so execution
once triggered doesn't wait on a network fetch.

**Status:** untested theory, not confirmed. Nate is testing on the live
site (click-testing a few links, including one clicked immediately on
page load with no prior scroll) before deciding whether to keep this or
revert. If click/conversion tracking starts silently under-counting
after this, this is the first thing to check — re-add the three
`add_filter` calls and the `exclude_from_wp_rocket_delay()` /
`tag_as_unoptimized()` methods removed from each file's `init()` (see
git history for this commit to restore them verbatim).
