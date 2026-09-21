<?php
/**
 * Sets Meta's own _fbp/_fbc cookies server-side, mirroring how
 * Cogito_RAR_SetCookie already sets rar_uid. Without this, these cookies
 * only ever get written by client-side Pixel JS (if Nate has one running
 * via GTM) via document.cookie — and Safari's Intelligent Tracking
 * Prevention caps any script-written cookie's lifetime at 7 days,
 * silently resetting a visitor's Meta identity every week regardless of
 * the cookie's own stated expiry. A cookie set via a real HTTP
 * Set-Cookie response header (this class) isn't script-written from
 * Safari's point of view, so it keeps its full lifetime.
 *
 * Only ever fills in a MISSING cookie — never overwrites one that's
 * already there, whether it was set by us on an earlier visit or by
 * Pixel JS earlier in this same page's load. So this is purely
 * additive: nothing changes for a site with no Pixel JS at all, and
 * nothing conflicts with one that already has it.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Meta_Cookies {

    // Matches Meta's own documented default lifetime for both cookies.
    const TTL = 90 * DAY_IN_SECONDS;

    public static function init() {
        add_action( 'init', [ self::class, 'maybe_set_cookies' ], 2 );
    }

    public static function maybe_set_cookies() {
        // These exist purely for Meta CAPI match quality — no point
        // setting them while Conversions itself is off.
        if ( ! class_exists( 'Cogito_RAR_Conversion_Capture' )
            || get_option( Cogito_RAR_Conversion_Capture::OPTION_ENABLED ) !== '1'
        ) {
            return;
        }

        // Same privacy toggle rar_uid already respects — if Nate has
        // opted out of persistent tracking cookies, that intent should
        // cover every cookie this plugin sets, not just the one it
        // happened to ship with first.
        if ( class_exists( 'Cogito_RAR_Settings_Defaults' )
            && get_option( Cogito_RAR_Settings_Defaults::OPTION_DISABLE_VISITOR_COOKIE ) === '1'
        ) {
            return;
        }

        if ( headers_sent() ) {
            return;
        }

        self::maybe_set_fbp();
        self::maybe_set_fbc();
    }

    /**
     * _fbp: a purely synthetic identifier, format fb.{subdomain_index}.
     * {creation_time_ms}.{random_number} per Meta's own spec.
     * subdomain_index is 1 for an apex domain (renchlist.com has no www),
     * matching the existing convention in
     * Cogito_RAR_Conversion_Capture::read_or_build_fbc().
     */
    private static function maybe_set_fbp() {
        if ( ! empty( $_COOKIE['_fbp'] ) ) {
            return;
        }

        $now_ms = (int) round( microtime( true ) * 1000 );
        $random = random_int( 1000000000, 9999999999 );
        self::set_cookie( '_fbp', 'fb.1.' . $now_ms . '.' . $random );
    }

    /**
     * _fbc: only buildable when this request actually carries a
     * ?fbclid= (an ad click landing directly, or on its way through to a
     * RARLink/raw link on the same visit) — nothing to build one from
     * otherwise. Same construction Cogito_RAR_Conversion_Capture already
     * does ephemerally for a signal snapshot; this persists it as an
     * actual cookie instead, so it survives to later requests/visits
     * within the attribution window rather than only existing for the
     * one request that happened to carry the fbclid.
     */
    private static function maybe_set_fbc() {
        if ( ! empty( $_COOKIE['_fbc'] ) ) {
            return;
        }
        if ( empty( $_GET['fbclid'] ) ) {
            return;
        }

        $fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
        $now_ms = (int) round( microtime( true ) * 1000 );
        self::set_cookie( '_fbc', 'fb.1.' . $now_ms . '.' . $fbclid );
    }

    private static function set_cookie( $name, $value ) {
        setcookie(
            $name,
            $value,
            [
                'expires'  => time() + self::TTL,
                'path'     => COOKIEPATH,
                'domain'   => COOKIE_DOMAIN,
                'secure'   => true,
                // Client-side Pixel JS (if present) needs to read this
                // too — same reasoning as rar_uid's own httponly=false.
                'httponly' => false,
                'samesite' => 'None',
            ]
        );

        // Available to the rest of THIS request immediately — same
        // pattern Cogito_RAR_SetCookie uses for rar_uid — so anything
        // reading $_COOKIE later in this same request (Conversion
        // Capture's read_fbp()/read_or_build_fbc()) sees it right away
        // rather than only from the next request onward.
        $_COOKIE[ $name ] = $value;
    }
}
