<?php
/**
 * Captures browser-side click context (the clicked link's text and CSS
 * classes) for a RARLink click, and merges it into that click's queued
 * conversion event before it's sent. Needed because a RARLink click is a
 * server-side redirect — server code only ever sees the request that
 * results from navigating away, never the DOM the click happened in.
 *
 * A tiny front-end script (assets/js/cogito-rar-click-context.js)
 * intercepts the click, appends a one-time token to the outgoing /go/
 * URL, and reports the link's text/classes to the REST route below, keyed
 * by that same token — a fire-and-forget beacon that never delays or
 * blocks the redirect itself. The token travels through in the queued
 * row's own signals (see Cogito_RAR_Conversion_Capture::build_meta_click_signals())
 * and is resolved back to this context in enrich() at DISPATCH time, not
 * capture time — dispatch happens roughly a minute later (see the
 * dispatcher's cron), comfortably after the beacon (a same-tick browser
 * request) has already landed.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Conversion_Click_Context {

    const TRANSIENT_PREFIX = 'rar_click_ctx_';
    const TTL              = 5 * MINUTE_IN_SECONDS;
    const MAX_LINK_TEXT    = 150;
    const MAX_CLASSES      = 10;

    /** The route path as WordPress records it in query_vars['rest_route']. */
    const ROUTE = '/rar/v1/click-context';

    public static function init() {
        add_action( 'rest_api_init', [ self::class, 'register_route' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );

        // navigator.sendBeacon() cannot send custom headers, so it can
        // never carry the X-WP-Nonce WordPress's core REST auth demands
        // from any request that happens to carry valid login cookies —
        // which every request from an already-logged-in browser does,
        // regardless of this endpoint's own permission_callback. Without
        // this, the beacon gets a silent 403 from ANY logged-in browser
        // (exactly what was happening while testing as an admin), while
        // a real logged-out visitor was never affected in the first
        // place. Priority 101 to run after core's own check (100) and
        // override its verdict, only for this one public, non-sensitive,
        // non-destructive route.
        add_filter( 'rest_authentication_errors', [ self::class, 'bypass_cookie_nonce_for_route' ], 101 );

        // This listener has to be attached before the FIRST click on the
        // page, not deferred until "user interaction" — which is exactly
        // what WP Rocket's "Delay JavaScript Execution" does to every
        // script by default (it treats a click as the trigger to finally
        // load deferred scripts, which is too late for a script whose job
        // is to catch that same click). Excluded explicitly rather than
        // relying on Nate to remember to configure this in WP Rocket.
        add_filter( 'rocket_delay_js_exclusions', [ self::class, 'exclude_from_wp_rocket_delay' ] );
        add_filter( 'rocket_exclude_defer_js', [ self::class, 'exclude_from_wp_rocket_delay' ] );
        // Belt-and-braces: data-no-optimize is a convention several
        // optimization plugins (Perfmatters included) honour directly on
        // the <script> tag itself, independent of any filter name.
        add_filter( 'script_loader_tag', [ self::class, 'tag_as_unoptimized' ], 10, 2 );
    }

    public static function exclude_from_wp_rocket_delay( $exclusions ) {
        $exclusions[] = 'cogito-rar-click-context';
        return $exclusions;
    }

    public static function tag_as_unoptimized( $tag, $handle ) {
        if ( 'cogito-rar-click-context' !== $handle ) {
            return $tag;
        }
        return str_replace( ' src=', ' data-no-optimize="1" data-cfasync="false" data-no-defer="1" data-no-delay="1" src=', $tag );
    }

    /**
     * Clears WordPress core's "logged in but no nonce" REST error, but
     * only for this specific route — every other endpoint keeps the
     * normal protection.
     */
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
     * Loads the click listener on the front end only while Conversions is
     * actually enabled — no point tracking context nothing will ever use.
     */
    public static function enqueue() {
        if ( get_option( Cogito_RAR_Conversion_Capture::OPTION_ENABLED ) !== '1' ) {
            return;
        }

        $rel_path = 'assets/js/cogito-rar-click-context.js';
        $abs_path = dirname( __FILE__, 3 ) . '/' . $rel_path;
        if ( ! file_exists( $abs_path ) ) {
            return;
        }

        wp_enqueue_script(
            'cogito-rar-click-context',
            plugin_dir_url( dirname( __FILE__, 2 ) ) . $rel_path,
            [],
            filemtime( $abs_path ),
            true
        );

        wp_localize_script( 'cogito-rar-click-context', 'rarClickContext', [
            'restUrl' => esc_url_raw( rest_url( 'rar/v1/click-context' ) ),
            'prefix'  => '/' . ( class_exists( 'Cogito_RAR_Redirect_Engine' ) ? Cogito_RAR_Redirect_Engine::PREFIX : 'go' ) . '/',
        ] );
    }

    public static function register_route() {
        register_rest_route( 'rar/v1', '/click-context', [
            'methods'             => 'POST',
            // No nonce/auth: this is the same non-sensitive, non-PII data a
            // Google Analytics event beacon would carry (link text/classes,
            // nothing identifying), scoped to a short-lived, single-use
            // token — not worth gating behind login state.
            'permission_callback' => '__return_true',
            'callback'            => [ self::class, 'handle_request' ],
        ] );
    }

    /**
     * Stores one click's browser-side context, keyed by its token.
     */
    public static function handle_request( WP_REST_Request $request ) {
        if ( get_option( Cogito_RAR_Conversion_Capture::OPTION_ENABLED ) !== '1' ) {
            return new WP_REST_Response( null, 204 );
        }

        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            $params = $request->get_params();
        }

        $token = isset( $params['token'] ) ? sanitize_key( $params['token'] ) : '';
        if ( '' === $token || strlen( $token ) > 64 ) {
            return new WP_REST_Response( null, 204 );
        }

        $link_text = isset( $params['link_text'] ) ? sanitize_text_field( (string) $params['link_text'] ) : '';
        $link_text = mb_substr( $link_text, 0, self::MAX_LINK_TEXT );

        $classes_raw = isset( $params['link_classes'] ) ? (string) $params['link_classes'] : '';
        $classes     = array_slice(
            array_values( array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( $classes_raw ) ) ) ) ),
            0,
            self::MAX_CLASSES
        );

        // window.location.href from the click itself — more reliable than
        // the eventual redirect request's $_SERVER['HTTP_REFERER'], which
        // a strict Referrer-Policy can reduce to just the origin (no path).
        // Restricted to our own host: this is meant to replace a same-site
        // referrer value, not accept an arbitrary client-supplied URL.
        $page_url      = isset( $params['page_url'] ) ? esc_url_raw( (string) $params['page_url'] ) : '';
        $own_host      = wp_parse_url( home_url(), PHP_URL_HOST );
        $page_url_host = $page_url ? wp_parse_url( $page_url, PHP_URL_HOST ) : '';
        if ( '' === $page_url || ! $own_host || strcasecmp( $page_url_host, $own_host ) !== 0 ) {
            $page_url = '';
        }

        set_transient( self::TRANSIENT_PREFIX . $token, [
            'link_text'    => $link_text,
            'link_classes' => implode( ' ', $classes ),
            'page_url'     => $page_url,
        ], self::TTL );

        return new WP_REST_Response( null, 204 );
    }

    /**
     * Merges captured context into a queued event's signals, keyed by the
     * click_token Cogito_RAR_Conversion_Capture stashed in them. No-op if
     * no token was captured, or the beacon never arrived (blocked, or the
     * visitor has JS disabled) — degrades to the same blank fields as
     * before this feature existed.
     *
     * @param array $signals
     * @return array
     */
    public static function enrich( array $signals ) {
        $token = $signals['click_token'] ?? '';
        if ( '' === $token ) {
            return $signals;
        }

        $context = get_transient( self::TRANSIENT_PREFIX . $token );
        if ( ! is_array( $context ) ) {
            return $signals;
        }

        if ( ! empty( $context['link_text'] ) ) {
            $signals['link_text'] = $context['link_text'];
        }
        if ( ! empty( $context['link_classes'] ) ) {
            $signals['link_classes'] = $context['link_classes'];
        }
        if ( ! empty( $context['page_url'] ) ) {
            // Overrides the server-captured HTTP_REFERER — see the note in
            // handle_request() on why the JS-sourced value is trusted more.
            $signals['event_source_url'] = $context['page_url'];
        }

        return $signals;
    }
}
