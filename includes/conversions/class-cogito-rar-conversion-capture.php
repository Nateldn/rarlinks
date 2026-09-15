<?php
/**
 * Listens for logged RARLink clicks and queues a conversion event for every
 * enabled provider. Server logic only — no rendering, no provider-specific
 * knowledge (that lives entirely in each provider's map_payload()).
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Conversion_Capture {

    const OPTION_ENABLED             = 'rar_conversions_enabled';
    const OPTION_HOLD_MINUTES         = 'rar_conversions_hold_minutes';
    const MAX_TRACKED_IDENTIFIERS    = 50;

    /**
     * Admin-defined list of raw-link events: [ [ 'name' => 'AffiliateClick',
     * 'identifiers' => "affi_btn\naffi_group" ], ... ]. Fully self-service —
     * adding a new event (its own name + its own tracked classes/IDs) never
     * requires a code change.
     */
    const OPTION_EVENT_DEFINITIONS = 'rar_conversions_event_definitions';
    const MAX_EVENTS               = 20;

    /**
     * One-time-migrated-away-from fields (kept only so the migration in
     * get_event_definitions_raw() has somewhere to read Nate's already-
     * curated lists from). Never written to again after that migration.
     */
    const OPTION_TRACKED_IDENTIFIERS_LEGACY    = 'rar_conversions_tracked_identifiers';
    const OPTION_TRACKED_AD_IDENTIFIERS_LEGACY = 'rar_conversions_tracked_ad_identifiers';

    /**
     * Default custom_data field names — used whenever an event doesn't
     * override them (i.e. every event before this feature existed keeps
     * behaving exactly as it already did). event_source_url defaults to
     * blank, meaning "don't duplicate it into custom_data at all" — it's
     * already sent as a required top-level Meta field, so nothing is lost
     * by leaving this blank; a name here just ALSO copies that same value
     * into custom_data under an admin-chosen key (matching a GTM GA4 tag's
     * own "Page URL" parameter, for anyone who wants that side-by-side).
     */
    const DEFAULT_FIELD_NAMES = [
        'destination_url'  => 'destination_url',
        'link_text'        => 'link_text',
        'link_classes'     => 'link_classes',
        'event_source_url' => '',
    ];

    public static function init() {
        add_action( 'rar_click_logged', [ self::class, 'maybe_capture' ], 10, 4 );
    }

    /**
     * @param int    $post_id         The RARLink post.
     * @param array  $result          The classify() result: bot_or_not, bot_name.
     * @param array  $raw_signals     ip_address, hostname, org, user_agent, referrer, visitor_id.
     * @param string $destination_url The resolved redirect target actually used for this click.
     */
    public static function maybe_capture( $post_id, $result, $raw_signals, $destination_url ) {
        // Master toggle — off by default, nothing is captured until enabled.
        if ( get_option( self::OPTION_ENABLED ) !== '1' ) {
            return;
        }

        // Bot filtering is non-negotiable: only ever queue clicks the shared
        // detection waterfall classified as human at log time. This reuses
        // the SAME $result already computed for the click log row — no
        // second, possibly-drifting bot check.
        if ( ! isset( $result['bot_or_not'] ) || (int) $result['bot_or_not'] !== 0 ) {
            return;
        }

        $hold_minutes = (int) get_option( self::OPTION_HOLD_MINUTES, 0 );

        $event_signals = array_merge(
            self::build_meta_click_signals( $destination_url ),
            [
                // Kept alongside the outbound-payload fields so the
                // dispatcher's later safety-net re-classification (at send
                // time, not just click time) can run the FULL detection
                // waterfall — not a degraded version missing PTR/org checks.
                'post_id'  => (int) $post_id,
                'hostname' => $raw_signals['hostname'] ?? '',
                'org'      => $raw_signals['org'] ?? '',
                'click_date' => current_time( 'Y-m-d' ),
            ]
        );

        foreach ( self::enabled_providers() as $provider ) {
            Cogito_RAR_Conversion_Queue::enqueue(
                $provider->get_key(),
                $event_signals['event_name'],
                $event_signals,
                $post_id,
                'rarlink',
                $hold_minutes
            );
        }
    }

    /**
     * Builds the provider-agnostic signal set for an AffiliateClick event
     * from a RARLink redirect. Named for the event it produces, but the
     * shape itself has nothing Meta-specific in it — every provider's
     * map_payload() reads from the same fields.
     */
    private static function build_meta_click_signals( $destination_url ) {
        return [
            'event_name'       => 'AffiliateClick',
            'event_id'         => wp_generate_uuid4(),
            'click_time'       => time(), // seconds — see the Meta provider's own note on units
            'ip'               => filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP ) ?: '',
            'user_agent'       => substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
            // The page the RARLink was clicked FROM — not applicable to
            // fill from a RARLink redirect's own URL (there is no DOM here).
            'event_source_url' => sanitize_text_field( $_SERVER['HTTP_REFERER'] ?? '' ),
            'destination_url'  => esc_url_raw( $destination_url ),
            // Blank until Cogito_RAR_Conversion_Click_Context::enrich() fills
            // them in at dispatch time, IF the browser-side click listener's
            // beacon landed for this click (see click_token below).
            'link_text'        => '',
            'link_classes'     => '',
            'fbp'               => self::read_fbp(),
            'fbc'               => self::read_or_build_fbc(),
            // The redirect and the click listener's beacon are two separate
            // requests racing each other — this token is how the beacon's
            // data (captured client-side, where the DOM is visible) gets
            // matched back up to this specific click at send time.
            'click_token'      => isset( $_GET['_rct'] ) ? sanitize_key( wp_unslash( $_GET['_rct'] ) ) : '',
            // Snapshotted at capture time (not re-resolved at send time)
            // so a later rename of the event's field names doesn't retroactively
            // change what an already-queued-but-unsent row reports as.
            'field_names'      => self::get_field_names_for_event( 'AffiliateClick' ),
        ];
    }

    private static function read_fbp() {
        return isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '';
    }

    /**
     * Reads the _fbc cookie, or constructs it from a ?fbclid= query param
     * on THIS request if the cookie is absent (an ad linking straight to a
     * RARLink rather than to an article first). Format verified against
     * Meta's docs: fb.{subdomain_index}.{creation_time_ms}.{fbclid} —
     * subdomain_index is 1 for an apex domain; renchlist.com has no www.
     */
    private static function read_or_build_fbc() {
        if ( ! empty( $_COOKIE['_fbc'] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        }

        if ( empty( $_GET['fbclid'] ) ) {
            return '';
        }

        $fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) ); // case-sensitive, no transformation beyond WP's own sanitisation
        $now_ms = (int) round( microtime( true ) * 1000 );

        return 'fb.1.' . $now_ms . '.' . $fbclid;
    }

    /**
     * The admin-defined raw-link events, parsed and ready for matching.
     *
     * @return array [ [ 'name' => 'AffiliateClick', 'groups' => string[][] ], ... ]
     */
    public static function get_event_definitions() {
        $events = [];
        foreach ( self::get_event_definitions_raw() as $event ) {
            $groups = self::parse_identifier_groups( (string) ( $event['identifiers'] ?? '' ) );
            if ( '' !== ( $event['name'] ?? '' ) && ! empty( $groups ) ) {
                $events[] = [ 'name' => $event['name'], 'groups' => $groups ];
            }
        }
        return $events;
    }

    /**
     * The raw stored event list — name plus the identifiers textarea's raw
     * text, unparsed — for the settings form to round-trip exactly what
     * was typed. Migrates the old two-field (AffiliateClick/
     * AdvertisementClick-only) setup to this generic list, ONCE, the first
     * time this is ever called after upgrading — so Nate's already-curated
     * lists carry forward instead of starting blank.
     *
     * @return array [ [ 'name' => string, 'identifiers' => string ], ... ]
     */
    public static function get_event_definitions_raw() {
        $stored = get_option( self::OPTION_EVENT_DEFINITIONS, false );
        if ( false !== $stored ) {
            return is_array( $stored ) ? $stored : [];
        }

        $migrated  = [];
        $affiliate = (string) get_option( self::OPTION_TRACKED_IDENTIFIERS_LEGACY, '' );
        $ad        = (string) get_option( self::OPTION_TRACKED_AD_IDENTIFIERS_LEGACY, '' );

        if ( '' !== trim( $affiliate ) ) {
            $migrated[] = [ 'name' => 'AffiliateClick', 'identifiers' => $affiliate ];
        }
        if ( '' !== trim( $ad ) ) {
            $migrated[] = [ 'name' => 'AdvertisementClick', 'identifiers' => $ad ];
        }

        update_option( self::OPTION_EVENT_DEFINITIONS, $migrated );
        return $migrated;
    }

    /**
     * Sanitises a raw-link event name: Meta event names are conventionally
     * alphanumeric (CamelCase or snake_case), so anything else is stripped
     * rather than escaped — this string is used both as an HTML attribute
     * value and sent literally as the Conversions API event_name.
     *
     * @param string $name
     * @return string Empty if nothing valid remains.
     */
    public static function sanitize_event_name( $name ) {
        $name = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $name );
        return mb_substr( $name, 0, 40 );
    }

    /**
     * The custom_data field NAMES a given event should use — e.g. an
     * AffiliateClick event Nate wants sent as "affiliate_url"/"call_to_
     * action"/"type_of_click" to mirror an existing GA4 tag's own
     * parameter names exactly, rather than this plugin's generic
     * destination_url/link_text/link_classes. Falls back to
     * DEFAULT_FIELD_NAMES for any override left blank, and for an event
     * name with no matching definition at all (e.g. a RARLink click, which
     * always reports as "AffiliateClick" regardless of whether that name
     * still exists in the admin-defined list) — so this is always safe to
     * call, never returns something incomplete.
     *
     * @param string $event_name Sanitised event name (see sanitize_event_name()).
     * @return array Same shape as DEFAULT_FIELD_NAMES.
     */
    public static function get_field_names_for_event( $event_name ) {
        foreach ( self::get_event_definitions_raw() as $event ) {
            if ( self::sanitize_event_name( $event['name'] ?? '' ) !== $event_name ) {
                continue;
            }

            $resolved = self::DEFAULT_FIELD_NAMES;
            foreach ( self::DEFAULT_FIELD_NAMES as $signal => $default ) {
                $override = trim( (string) ( $event[ 'field_' . $signal ] ?? '' ) );
                if ( '' !== $override ) {
                    $resolved[ $signal ] = self::sanitize_event_name( $override );
                }
            }
            return $resolved;
        }

        return self::DEFAULT_FIELD_NAMES;
    }

    /**
     * Parses a tracked-identifiers textarea into "groups": each LINE is one
     * group, and a group's space-separated tokens are ALL required
     * ("chained" — an AND across the clicked element's own class/id plus
     * every ancestor's, not just any single class/id anywhere) for that
     * line to match. A plain single-token line behaves exactly as a bare
     * identifier always has. Different lines are OR'd — matching any ONE
     * line's full group is enough.
     *
     * Example: a line "rl_wrap rl_drift" only matches a click where BOTH
     * rl_wrap and rl_drift are found somewhere in the clicked element's own
     * classes/id or its ancestors' — not necessarily on the same element.
     *
     * Each token is checked against the clicked element's class list AND
     * its id attribute, walking up through ancestors (so a container class
     * still matches a click on a link inside it, replicating what a GTM
     * "Click Element contains" trigger did). NOT CSS selector syntax — a
     * leading '.' or '#' is simply stripped if present (tolerates old
     * CSS-selector habits) rather than treated specially; blank lines are
     * skipped since no real identifier is ever empty.
     *
     * Stored as the raw textarea text so the settings form round-trips
     * exactly what was typed; parsed here into groups for anything that
     * actually needs to USE the list (the settings UI's own preview, and
     * the raw-link REST route + JS listener, via wp_localize_script()).
     *
     * @param string $raw
     * @return string[][] Array of groups; each group an array of 1+ sanitised tokens.
     */
    public static function parse_identifier_groups( $raw ) {
        $lines  = preg_split( '/\r\n|\r|\n/', $raw );
        $groups = [];

        foreach ( $lines as $line ) {
            $tokens = array_filter( preg_split( '/\s+/', trim( $line ) ) );
            if ( empty( $tokens ) ) {
                continue;
            }

            $group = [];
            foreach ( $tokens as $token ) {
                $token = ltrim( trim( $token ), '.#' ); // tolerate a leading .foo or #foo, not treated as syntax
                if ( '' === $token ) {
                    continue;
                }
                // Safe for both a class name and an id attribute value; matches
                // this plugin's existing convention for sanitising class-like
                // tokens (see the nofollow/sponsored rel building in the
                // redirect engine).
                $group[] = sanitize_html_class( $token );
            }

            if ( ! empty( $group ) ) {
                $groups[] = $group;
            }
        }

        // A runaway list would make the click listener slow to evaluate on
        // every click, and there's no legitimate reason to need more than a
        // few dozen distinct button/link styles at once.
        return array_slice( $groups, 0, self::MAX_TRACKED_IDENTIFIERS );
    }

    /**
     * Registered providers that are ALSO configured (their is_enabled()
     * check, e.g. a wp-config access-token constant). Queuing for a
     * disabled provider would just accumulate rows nothing can ever send.
     *
     * @return Cogito_RAR_Conversion_Provider[]
     */
    private static function enabled_providers() {
        return array_filter( Cogito_RAR_Conversion_Providers::all(), function ( $provider ) {
            return $provider->is_enabled();
        } );
    }
}
