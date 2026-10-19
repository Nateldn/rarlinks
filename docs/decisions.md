# Decisions

Code-level rationale that used to live as inline comments. Dated entries,
newest first. Each references the file/line as of the commit that made
the change — line numbers drift as the file changes, so check git blame
on the referenced line if this doc and the code look out of sync.

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
