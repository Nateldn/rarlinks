<?php
/**
 * Captures clicks on track-only links (see Cogito_RAR_Track_Only) — ones
 * whose vanity URL is their own real destination, so there's no /go/
 * redirect request for the server to log a click from. A site-wide,
 * non-blocking client-side listener (assets/js/cogito-rar-track-only-capture.js)
 * reports the click instead, identified by post ID; this class turns that
 * report into exactly the same Cogito_RAR_Click_Logger::log_click() call
 * a normal /go/ redirect would already have made — so Clicks Report, Bot
 * Report and Conversions capture all behave identically either way, with
 * nothing provider-specific added here.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Track_Only_Capture {

    const ROUTE        = '/rar/v1/track-only-click';
    const NONCE_ACTION = 'rar_track_only_capture';

    const RATE_PREFIX          = 'rar_toc_rate_';
    const RATE_WINDOW          = 5 * MINUTE_IN_SECONDS;
    const RATE_MAX_PER_VISITOR = 20;
    const RATE_MAX_PER_IP      = 60;

    public static function init() {
        add_action( 'rest_api_init', [ self::class, 'register_route' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );

        // Same sendBeacon/auth-bypass reasoning as the other beacon routes
        // (click-context, raw-link-click): sendBeacon can't carry the
        // X-WP-Nonce header WP's cookie-auth would otherwise demand, and a
        // security/hardening plugin has separately been confirmed blocking
        // anonymous REST access broadly on this site — this route's own
        // security (nonce in the body, origin/referer, rate limiting, and
        // re-validating the post server-side) already runs inside
        // handle_request() regardless.
        add_filter( 'rest_authentication_errors', [ self::class, 'bypass_cookie_nonce_for_route' ], PHP_INT_MAX );
        add_filter( 'rocket_delay_js_exclusions', [ self::class, 'exclude_from_wp_rocket_delay' ] );
        add_filter( 'rocket_exclude_defer_js', [ self::class, 'exclude_from_wp_rocket_delay' ] );
        add_filter( 'script_loader_tag', [ self::class, 'tag_as_unoptimized' ], 10, 2 );
    }

    public static function exclude_from_wp_rocket_delay( $exclusions ) {
        $exclusions[] = 'cogito-rar-track-only-capture';
        return $exclusions;
    }

    public static function tag_as_unoptimized( $tag, $handle ) {
        if ( 'cogito-rar-track-only-capture' !== $handle ) {
            return $tag;
        }
        return str_replace( ' src=', ' data-no-optimize="1" data-cfasync="false" data-no-defer="1" data-no-delay="1" src=', $tag );
    }

    public static function bypass_cookie_nonce_for_route( $result ) {
        if ( ! is_wp_error( $result ) ) {
            return $result;
        }

        $route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
        if ( 0 === strpos( $route, self::ROUTE ) ) {
            return null;
        }

        return $result;
    }

    /**
     * Loads the listener site-wide (a track-only link can appear on any
     * page), only while there's at least one published, active, track-only
     * link to actually match against.
     */
    public static function enqueue() {
        $links = self::get_live_track_only_links();
        if ( empty( $links ) ) {
            return;
        }

        $rel_path = 'assets/js/cogito-rar-track-only-capture.js';
        $abs_path = dirname( __FILE__, 2 ) . '/' . $rel_path;
        if ( ! file_exists( $abs_path ) ) {
            return;
        }

        wp_enqueue_script(
            'cogito-rar-track-only-capture',
            plugin_dir_url( dirname( __FILE__ ) ) . $rel_path,
            [],
            filemtime( $abs_path ),
            true
        );

        wp_localize_script( 'cogito-rar-track-only-capture', 'rarTrackOnlyCapture', [
            'restUrl' => esc_url_raw( rest_url( 'rar/v1/track-only-click' ) ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            // { targetUrl: postId } — exact-match lookup client-side
            // against each clicked link's own resolved .href.
            'links'   => $links,
        ] );
    }

    /**
     * @return array [ target_url => post_id ] for every published, active,
     *               track-only link.
     */
    private static function get_live_track_only_links() {
        $ids = get_posts( [
            'post_type'      => 'rar_redirect',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [ 'key' => Cogito_RAR_Track_Only::META_KEY, 'value' => '1' ],
                [ 'key' => '_rar_active', 'value' => '1' ],
            ],
        ] );

        $links = [];
        foreach ( $ids as $id ) {
            $target = (string) get_post_meta( $id, '_rar_target', true );
            if ( '' !== $target ) {
                $links[ $target ] = $id;
            }
        }
        return $links;
    }

    public static function register_route() {
        register_rest_route( 'rar/v1', '/track-only-click', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [ self::class, 'handle_request' ],
        ] );
    }

    public static function handle_request( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            $params = $request->get_params();
        }

        $reject = function ( $reason ) {
            error_log( '[RAR track-only-capture] rejected: ' . $reason );
            return new WP_REST_Response( null, 204 ); // Fail silently to the visitor either way.
        };

        if ( ! wp_verify_nonce( (string) ( $params['nonce'] ?? '' ), self::NONCE_ACTION ) ) {
            return $reject( 'invalid nonce' );
        }

        if ( ! self::origin_is_own_site( $request ) ) {
            return $reject( 'origin/referer mismatch' );
        }

        $post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
        if ( ! $post_id ) {
            return $reject( 'missing post_id' );
        }

        // Never trust the client's own idea of which post this was for —
        // re-validate server-side that it's still a genuinely live
        // track-only link, exactly the same gate the redirect engine
        // itself applies before logging any other click.
        $post = get_post( $post_id );
        if ( ! $post || 'rar_redirect' !== $post->post_type || 'publish' !== $post->post_status ) {
            return $reject( 'post not found or not published' );
        }
        if ( get_post_meta( $post_id, '_rar_active', true ) !== '1' ) {
            return $reject( 'link not active' );
        }
        if ( ! class_exists( 'Cogito_RAR_Track_Only' ) || ! Cogito_RAR_Track_Only::is_track_only( $post_id ) ) {
            return $reject( 'not a track-only link' );
        }

        $visitor_id = class_exists( 'Cogito_RAR_SetCookie' ) ? Cogito_RAR_SetCookie::get() : '';
        $ip_address = filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP ) ?: '';

        if ( self::rate_limited( self::RATE_PREFIX . 'v_' . ( $visitor_id ?: 'none' ), self::RATE_MAX_PER_VISITOR )
            || self::rate_limited( self::RATE_PREFIX . 'ip_' . ( $ip_address ?: 'none' ), self::RATE_MAX_PER_IP )
        ) {
            return $reject( 'rate limit exceeded' );
        }

        $had_cookie = class_exists( 'Cogito_RAR_SetCookie' ) ? Cogito_RAR_SetCookie::was_present() : true;

        // The post's OWN stored destination — not whatever the client
        // claims it is — same reasoning as the post-ID re-validation above.
        $destination_url = (string) get_post_meta( $post_id, '_rar_target', true );

        Cogito_RAR_Click_Logger::log_click( $post_id, $visitor_id, $had_cookie, $destination_url );

        return new WP_REST_Response( null, 204 );
    }

    private static function origin_is_own_site( WP_REST_Request $request ) {
        $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

        $origin = $request->get_header( 'origin' );
        if ( $origin ) {
            return strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === $home_host;
        }

        $referer = $request->get_header( 'referer' );
        if ( $referer ) {
            return strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) ) === $home_host;
        }

        return false;
    }

    private static function rate_limited( $key, $limit ) {
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return true;
        }
        set_transient( $key, $count + 1, self::RATE_WINDOW );
        return false;
    }
}
