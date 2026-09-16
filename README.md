# RARLinks

Custom redirect management for renchlist.com — vanity URLs, GEO targeting, link rotation, bot detection, and server-side conversion tracking.

## Conversions: Tracked Events

This is how RARLinks decides which button/link clicks on the site count as a conversion, and what to call each one when it's sent to Meta.

### What's an "event"?

An event is just a name (like `AffiliateClick`) plus a list of button/link classes or IDs that should count as that event. You manage these yourself on the Conversions settings tab — add a new event, rename one, or delete one, any time. No code changes needed.

RARLinks (the `/go/...` redirect links) are separate from this — a RARLink click always counts as an `AffiliateClick`, no matter what classes it has. The tracked-events list is only for plain buttons and links that don't go through a RARLink redirect.

### Event name

Whatever you type here is sent to Meta literally as the event's name. Letters, numbers, and underscores only — no spaces or symbols (e.g. `AffiliateClick`, `AdvertisementClick`, `NewsletterClick`).

### Tracked classes & IDs

One class name or element ID per line. Don't use CSS syntax — no dots, no hashes, just the plain name:

```
affi_btn
myButtonId
```

Each line is checked against the class list and ID of whatever was clicked, and everything wrapping it (its parent elements too) — so a class on a container div still counts if someone clicks a link or image inside it.

### Requiring more than one class at once (chaining)

Put more than one name on the same line, separated by a space, if you want to require them **all** to be present before it counts:

```
rl_wrap rl_drift
```

This line only matches when **both** `rl_wrap` and `rl_drift` show up somewhere in the click — they don't have to be on the exact same element, just somewhere in the chain from the clicked element up through its parents. Use this when a single class alone is too broad or ambiguous on its own.

### If a click matches more than one event

Events are checked top to bottom, in the order they're listed on the settings page. Whichever one matches first wins — so if you have overlapping classes between two events, put the more specific one higher up the list.

### Custom parameter names

By default, every event sends its data to Meta under the same generic field names (`destination_url`, `link_text`, `link_classes`). If you'd rather match the parameter names from an existing GA4/GTM tag (e.g. `affiliate_url`, `call_to_action`, `type_of_click`), each event has an optional "Custom parameter names" section — fill in whichever ones you want renamed, leave the rest blank to keep the defaults.
