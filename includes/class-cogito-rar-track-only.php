<?php
/**
 * "Track-only" RARLinks: a link whose vanity URL is its own real
 * destination — no /go/ redirect at all — for cases where masking the
 * destination isn't allowed (Amazon Associates' own terms explicitly
 * prohibit disguised/shortened affiliate links) but click tracking is
 * still wanted. Nate still creates it the normal way (Add New RARLink,
 * paste the destination into Target URL); this class only changes what
 * gets output as "the link" and auto-detects when that should happen.
 *
 * Tracking for a track-only link happens client-side instead of via the
 * server redirect — see Cogito_RAR_Track_Only_Capture — but reports back
 * to Cogito_RAR_Click_Logger::log_click() directly, the exact same
 * function a normal /go/ redirect already calls. Clicks Report, Bot
 * Report and Conversions capture all work identically either way; only
 * how the click is detected differs.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Track_Only {

    const META_KEY = '_rar_track_only';

    public static function init() {
        // Priority 20: after Cogito_RAR::enqueue_admin_assets() (default
        // priority 10) has actually enqueued 'rar-admin-metabox-js' —
        // wp_localize_script() has to attach to an already-enqueued handle.
        add_action( 'admin_enqueue_scripts', [ self::class, 'localize_domains_for_admin_js' ], 20 );
    }

    /**
     * Gives the Edit-screen JS the same domain list PHP enforces with
     * server-side, so it can grey out Redirect Type/GEO/Rotation and
     * force-check Track-only the instant a recognised URL is typed —
     * without waiting for a save/reload round trip — using one shared
     * source of truth rather than a second hardcoded list in JS.
     */
    public static function localize_domains_for_admin_js() {
        if ( ! wp_script_is( 'rar-admin-metabox-js', 'enqueued' ) ) {
            return;
        }
        wp_localize_script( 'rar-admin-metabox-js', 'rarTrackOnly', [
            'domains' => self::AUTO_DETECT_DOMAINS,
        ] );
    }

    /**
     * Domains whose own terms are known to prohibit cloaked/shortened
     * affiliate links — Amazon Associates' Operating Agreement being the
     * motivating case. amzn.to is Amazon's own official shortener,
     * included so a link already using it is still recognised.
     */
    const AUTO_DETECT_DOMAINS = [
        'amazon.com',
        'amazon.co.uk',
        'amazon.ca',
        'amazon.de',
        'amazon.fr',
        'amazon.it',
        'amazon.es',
        'amazon.nl',
        'amazon.se',
        'amazon.pl',
        'amazon.com.be',
        'amazon.co.jp',
        'amazon.in',
        'amazon.com.au',
        'amazon.com.br',
        'amazon.com.mx',
        'amzn.to',
    ];

    /**
     * Whether a destination URL's host matches one of the known
     * no-cloaking domains, subdomains included (e.g. www.amazon.com).
     *
     * @param string $url
     * @return bool
     */
    public static function host_requires_track_only( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( '' === $host ) {
            return false;
        }

        foreach ( self::AUTO_DETECT_DOMAINS as $domain ) {
            if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves what _rar_track_only should be saved as. For a recognised
     * no-cloak domain this is non-negotiable: always '1', regardless of
     * what was submitted — "the human forgot to tick the box" is exactly
     * the failure mode this is meant to close, so it can't be a
     * suggestion that's easy to override or miss. For anything else, the
     * submitted value wins when given at all (so a human/API caller can
     * still opt a non-listed domain IN), falling back to '0'.
     *
     * @param string|null $submitted '1'/'0' if explicitly provided, null
     *                                if the field was never touched (e.g.
     *                                a REST request that didn't include it).
     * @param string      $target_url
     * @return string '1' or '0'.
     */
    public static function resolve( $submitted, $target_url ) {
        if ( self::host_requires_track_only( $target_url ) ) {
            return '1';
        }
        return ( null !== $submitted && '1' === (string) $submitted ) ? '1' : '0';
    }

    /**
     * @param int|WP_Post $post
     * @return bool
     */
    public static function is_track_only( $post ) {
        $post_id = is_object( $post ) ? $post->ID : (int) $post;
        return '1' === get_post_meta( $post_id, self::META_KEY, true );
    }
}
