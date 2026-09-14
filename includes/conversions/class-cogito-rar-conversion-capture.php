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

    const OPTION_ENABLED      = 'rar_conversions_enabled';
    const OPTION_HOLD_MINUTES = 'rar_conversions_hold_minutes';

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

        $hold_minutes = (int) get_option( self::OPTION_HOLD_MINUTES, 60 );

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
            'link_text'        => '', // only the raw-link JS capture path (a later step) can supply these
            'link_classes'     => '',
            'fbp'               => self::read_fbp(),
            'fbc'               => self::read_or_build_fbc(),
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
