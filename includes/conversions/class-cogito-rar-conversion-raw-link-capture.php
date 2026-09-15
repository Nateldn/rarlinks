<?php
/**
 * Captures AffiliateClick conversion events for raw affiliate links — bare
 * merchant URLs and affiliate buttons NOT routed through a RARLink redirect.
 * Unlike a RARLink click (a server-side redirect our own code controls),
 * these clicks navigate straight to the merchant's own URL, so the ENTIRE
 * event has to be captured client-side and reported in one shot: there is
 * no server-side request of ours to hang enrichment off of.
 *
 * Because this endpoint, unlike click-context's, actually CREATES a queued
 * conversion event from browser-supplied data (not just enriches one our
 * own code already decided to queue), it carries the full security layer
 * called for in the original brief: a per-page-load nonce, origin/referer
 * validation, a destination-domain allowlist, and rate limiting — every
 * browser-supplied field is treated as untrusted and re-validated here.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Conversion_Raw_Link_Capture {

    const ROUTE         = '/rar/v1/raw-link-click';
    const NONCE_ACTION  = 'rar_raw_link_capture';
    const MAX_LINK_TEXT = 150;
    const MAX_CLASSES   = 10;

    /** One hostname (or bare domain, matching subdomains too) per line; empty = allow any HTTPS destination. */
    const OPTION_ALLOWED_DOMAINS = 'rar_conversions_raw_link_domains';

    const RATE_PREFIX          = 'rar_rlc_rate_';
    const RATE_WINDOW          = 5 * MINUTE_IN_SECONDS;
    const RATE_MAX_PER_VISITOR = 20;
    const RATE_MAX_PER_IP      = 60;

    public static function init() {
        add_action( 'rest_api_init', [ self::class, 'register_route' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );

        // Same sendBeacon/cookie-nonce and JS-delay considerations as the
        // click-context listener (see that class for the full reasoning) —
        // this listener equally has to be attached before the first click.
        add_filter( 'rest_authentication_errors', [ self::class, 'bypass_cookie_nonce_for_route' ], 101 );
        add_filter( 'rocket_delay_js_exclusions', [ self::class, 'exclude_from_wp_rocket_delay' ] );
        add_filter( 'rocket_exclude_defer_js', [ self::class, 'exclude_from_wp_rocket_delay' ] );
        add_filter( 'script_loader_tag', [ self::class, 'tag_as_unoptimized' ], 10, 2 );
    }

    public static function exclude_from_wp_rocket_delay( $exclusions ) {
        $exclusions[] = 'cogito-rar-raw-link-capture';
        return $exclusions;
    }

    public static function tag_as_unoptimized( $tag, $handle ) {
        if ( 'cogito-rar-raw-link-capture' !== $handle ) {
            return $tag;
        }
        return str_replace( ' src=', ' data-no-optimize="1" data-cfasync="false" data-no-defer="1" data-no-delay="1" src=', $tag );
    }

    public static function bypass_cookie_nonce_for_route( $result ) {
        if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
            return $result;
        }

        $route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
        if ( 0 === strpos( $route, self::ROUTE ) ) {
            return null;
        }

        return $result;
    }

    /**
     * Loads the listener site-wide (not just single posts) — raw affiliate
     * links and bare merchant URLs can appear on any page — only while
     * Conversions is enabled and at least one tracked identifier exists
     * (no point loading a script with nothing to ever match).
     */
    public static function enqueue() {
        if ( get_option( Cogito_RAR_Conversion_Capture::OPTION_ENABLED ) !== '1' ) {
            return;
        }

        $identifiers = Cogito_RAR_Conversion_Capture::get_tracked_identifiers();
        if ( empty( $identifiers ) ) {
            return;
        }

        $rel_path = 'assets/js/cogito-rar-raw-link-capture.js';
        $abs_path = dirname( __FILE__, 3 ) . '/' . $rel_path;
        if ( ! file_exists( $abs_path ) ) {
            return;
        }

        wp_enqueue_script(
            'cogito-rar-raw-link-capture',
            plugin_dir_url( dirname( __FILE__, 2 ) ) . $rel_path,
            [],
            filemtime( $abs_path ),
            true
        );

        wp_localize_script( 'cogito-rar-raw-link-capture', 'rarRawLinkCapture', [
            'restUrl'     => esc_url_raw( rest_url( 'rar/v1/raw-link-click' ) ),
            'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
            'identifiers' => $identifiers,
            'goPrefix'    => '/' . ( class_exists( 'Cogito_RAR_Redirect_Engine' ) ? Cogito_RAR_Redirect_Engine::PREFIX : 'go' ) . '/',
            'homeHost'    => wp_parse_url( home_url(), PHP_URL_HOST ),
        ] );
    }

    public static function register_route() {
        register_rest_route( 'rar/v1', '/raw-link-click', [
            'methods'             => 'POST',
            // Public for the same reason as click-context: sendBeacon can't
            // carry the X-WP-Nonce header WP's cookie-auth would demand.
            // Real verification happens explicitly inside handle_request()
            // instead — nonce (in the body), origin/referer, an allowlist,
            // and rate limiting.
            'permission_callback' => '__return_true',
            'callback'            => [ self::class, 'handle_request' ],
        ] );
    }

    public static function handle_request( WP_REST_Request $request ) {
        if ( get_option( Cogito_RAR_Conversion_Capture::OPTION_ENABLED ) !== '1' ) {
            return new WP_REST_Response( null, 204 );
        }

        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            $params = $request->get_params();
        }

        $reject = function ( $reason ) {
            error_log( '[RAR raw-link-capture] rejected: ' . $reason );
            return new WP_REST_Response( null, 204 ); // Fail silently to the visitor either way.
        };

        if ( ! wp_verify_nonce( (string) ( $params['nonce'] ?? '' ), self::NONCE_ACTION ) ) {
            return $reject( 'invalid nonce' );
        }

        if ( ! self::origin_is_own_site( $request ) ) {
            return $reject( 'origin/referer mismatch' );
        }

        $destination_url = isset( $params['destination_url'] ) ? esc_url_raw( (string) $params['destination_url'] ) : '';
        if ( '' === $destination_url || 'https' !== wp_parse_url( $destination_url, PHP_URL_SCHEME ) ) {
            return $reject( 'missing/non-https destination_url' );
        }
        if ( ! self::destination_is_allowed( $destination_url ) ) {
            return $reject( 'destination domain not on the allowlist' );
        }

        $visitor_id = class_exists( 'Cogito_RAR_SetCookie' ) ? Cogito_RAR_SetCookie::get() : '';
        $ip_address = filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP ) ?: '';

        if ( self::rate_limited( self::RATE_PREFIX . 'v_' . ( $visitor_id ?: 'none' ), self::RATE_MAX_PER_VISITOR )
            || self::rate_limited( self::RATE_PREFIX . 'ip_' . ( $ip_address ?: 'none' ), self::RATE_MAX_PER_IP )
        ) {
            return $reject( 'rate limit exceeded' );
        }

        // Every browser-supplied field beyond destination_url/link_text/
        // link_classes is ignored — IP, UA, referrer and cookies are all
        // read directly from THIS request instead, the same way a RARLink
        // click's signals are gathered server-side.
        $hostname = $ip_address ? gethostbyaddr( $ip_address ) : '';
        if ( is_string( $hostname ) && false !== stripos( $hostname, (string) ( self::home_host() ) ) ) {
            $hostname = $ip_address;
        }

        $org         = '';
        $current_asn = null;
        if ( class_exists( 'ASNResolver' ) ) {
            $org         = ASNResolver::get_organization( $ip_address ) ?? '';
            $current_asn = ASNResolver::get_asn_number( $ip_address );
        }

        $referrer   = sanitize_text_field( $_SERVER['HTTP_REFERER'] ?? '' );
        $user_agent = substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 );
        $had_cookie = class_exists( 'Cogito_RAR_SetCookie' ) ? Cogito_RAR_SetCookie::was_present() : true;

        $result = class_exists( 'Cogito_RAR_Click_Logger' )
            ? Cogito_RAR_Click_Logger::classify( [
                'ip_address'        => $ip_address,
                'hostname'          => $hostname,
                'org'               => $org,
                'user_agent'        => $user_agent,
                'referrer'          => $referrer,
                'current_asn'       => $current_asn,
                'had_cookie'        => $had_cookie,
                'post_id'           => 0,
                'click_date'        => current_time( 'Y-m-d' ),
                'spamhaus_asn_data' => Cogito_RAR_Click_Logger::load_spamhaus_asn_data(),
                'spamhaus_drop_data' => class_exists( 'Cogito_RAR_Spamhaus_Drop' ) ? Cogito_RAR_Spamhaus_Drop::load() : [],
            ] )
            : [ 'bot_or_not' => 2 ];

        if ( (int) ( $result['bot_or_not'] ?? 2 ) !== 0 ) {
            // Not a rejection — a legitimate click that just isn't human.
            // Same rule as RARLink clicks: only ever queue clicks classified
            // as human, silently, with nothing logged as an error.
            return new WP_REST_Response( null, 204 );
        }

        $link_text = isset( $params['link_text'] ) ? mb_substr( sanitize_text_field( (string) $params['link_text'] ), 0, self::MAX_LINK_TEXT ) : '';

        $classes_raw = isset( $params['link_classes'] ) ? (string) $params['link_classes'] : '';
        $classes     = array_slice(
            array_values( array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $classes_raw ) ) ) ) ),
            0,
            self::MAX_CLASSES
        );

        $event_signals = [
            'event_name'       => 'AffiliateClick',
            'event_id'         => wp_generate_uuid4(),
            'click_time'       => time(),
            'ip'               => $ip_address,
            'user_agent'       => $user_agent,
            'event_source_url' => $referrer,
            'destination_url'  => $destination_url,
            'link_text'        => $link_text,
            'link_classes'     => implode( ' ', $classes ),
            'fbp'              => isset( $_COOKIE['_fbp'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) : '',
            'fbc'              => self::read_or_build_fbc(),
            'hostname'         => $hostname,
            'org'              => $org,
            'click_date'       => current_time( 'Y-m-d' ),
        ];

        $hold_minutes = (int) get_option( Cogito_RAR_Conversion_Capture::OPTION_HOLD_MINUTES, 0 );

        foreach ( Cogito_RAR_Conversion_Providers::all() as $provider ) {
            if ( ! $provider->is_enabled() ) {
                continue;
            }
            Cogito_RAR_Conversion_Queue::enqueue(
                $provider->get_key(),
                $event_signals['event_name'],
                $event_signals,
                null,
                'raw_link',
                $hold_minutes
            );
        }

        return new WP_REST_Response( null, 204 );
    }

    private static function home_host() {
        return (string) wp_parse_url( home_url(), PHP_URL_HOST );
    }

    /**
     * Requires the request's Origin (preferred) or Referer header to name
     * our own host — a basic, browser-enforced check against a third-party
     * page forging a beacon call (not bulletproof against a non-browser
     * client, which is what the nonce/rate-limit/allowlist layers are for).
     */
    private static function origin_is_own_site( WP_REST_Request $request ) {
        $home_host = strtolower( self::home_host() );

        $origin = $request->get_header( 'origin' );
        if ( $origin ) {
            return strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === $home_host;
        }

        $referer = $request->get_header( 'referer' );
        if ( $referer ) {
            return strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) ) === $home_host;
        }

        // Neither header present — most real browser requests send at least
        // one; treat their total absence as suspicious rather than assume.
        return false;
    }

    /**
     * Empty allowlist = allow any HTTPS destination (matches this plugin's
     * "safe to start curating, not required up front" pattern elsewhere).
     * Configured, a destination must match one of the listed hostnames
     * exactly or be a subdomain of one (e.g. "revzilla.com" also allows
     * "imp.revzilla.com").
     */
    private static function destination_is_allowed( $destination_url ) {
        $allowed_raw = (string) get_option( self::OPTION_ALLOWED_DOMAINS, '' );
        $allowed     = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $allowed_raw ) ) );

        if ( empty( $allowed ) ) {
            return true;
        }

        $host = strtolower( (string) wp_parse_url( $destination_url, PHP_URL_HOST ) );
        if ( '' === $host ) {
            return false;
        }

        foreach ( $allowed as $domain ) {
            $domain = strtolower( ltrim( $domain, '.' ) );
            if ( '' === $domain ) {
                continue;
            }
            if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simple fixed-window rate limiter backed by a transient counter.
     *
     * @param string $key   Cache key, already scoped (visitor/IP prefix).
     * @param int    $limit Max requests allowed within the window.
     * @return bool True if this request should be rejected.
     */
    private static function rate_limited( $key, $limit ) {
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return true;
        }
        set_transient( $key, $count + 1, self::RATE_WINDOW );
        return false;
    }

    /**
     * Same fbc logic as the RARLink capture path (see
     * Cogito_RAR_Conversion_Capture::read_or_build_fbc()) — duplicated
     * rather than shared since the two classes read from different
     * super-globals ($_GET here is the current page's, not the redirect's).
     */
    private static function read_or_build_fbc() {
        if ( ! empty( $_COOKIE['_fbc'] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
        }
        if ( empty( $_GET['fbclid'] ) ) {
            return '';
        }
        $fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
        $now_ms = (int) round( microtime( true ) * 1000 );
        return 'fb.1.' . $now_ms . '.' . $fbclid;
    }
}
