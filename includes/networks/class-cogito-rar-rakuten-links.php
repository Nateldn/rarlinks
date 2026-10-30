<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Rakuten_Links {

    const NETWORK_LABEL = 'Rakuten Advertising';

    const AJAX_ACTION  = 'rar_rakuten_generate';
    const NONCE_ACTION = 'rar_rakuten_generate';

    const REST_NAMESPACE = 'rar/v1';
    const REST_ROUTE     = '/rakuten-link';

    const RATE_PREFIX = 'rar_rakuten_rate_';
    const RATE_WINDOW = 5 * MINUTE_IN_SECONDS;
    const RATE_MAX    = 60;

    public static function init() {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ self::class, 'handle_ajax' ] );
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_editor_script' ] );
    }

    public static function generate( $url, $advertiser_id = '', $name = '' ) {
        $url = trim( (string) $url );
        if ( '' === $url || ! wp_http_validate_url( $url ) ) {
            return new WP_Error( 'rar_rakuten_bad_url', 'That does not look like a valid URL.' );
        }

        $advertiser_name = '';

        if ( '' === $advertiser_id ) {
            $match = Cogito_RAR_Rakuten_API::resolve_advertiser_for_url( $url );
            if ( null === $match ) {
                return new WP_Error(
                    'rar_rakuten_no_match',
                    sprintf(
                        'No synced Rakuten advertiser matches %s. Sync advertisers again, or supply an advertiser ID.',
                        esc_html( Cogito_RAR_Rakuten_API::normalise_host( $url ) )
                    )
                );
            }
            $advertiser_id   = $match['id'];
            $advertiser_name = $match['name'];
        }

        $result = Cogito_RAR_Rakuten_API::create_deep_link( $url, $advertiser_id, $name );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( '' === $result['advertiser_name'] ) {
            $result['advertiser_name'] = $advertiser_name;
        }

        $result['source_url'] = $url;
        $result['network']    = self::NETWORK_LABEL;

        return $result;
    }

    private static function rate_limit_exceeded( $user_id ) {
        $key   = self::RATE_PREFIX . (int) $user_id;
        $count = (int) get_transient( $key );

        if ( $count >= self::RATE_MAX ) {
            return true;
        }

        set_transient( $key, $count + 1, self::RATE_WINDOW );
        return false;
    }

    public static function handle_ajax() {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }

        if ( ! current_user_can( 'edit_rar_redirects' ) ) {
            wp_send_json_error( [ 'message' => 'You do not have permission to do that.' ], 403 );
        }

        if ( self::rate_limit_exceeded( get_current_user_id() ) ) {
            wp_send_json_error( [ 'message' => 'Too many link generations. Try again in a few minutes.' ], 429 );
        }

        $url  = isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '';
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

        $result = self::generate( $url, '', $name );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    public static function register_routes() {
        register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods'             => 'POST',
            'permission_callback' => [ self::class, 'rest_permission' ],
            'callback'            => [ self::class, 'handle_rest' ],
            'args'                => [
                'url' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'advertiser_id' => [
                    'required' => false,
                    'type'     => 'string',
                ],
                'name' => [
                    'required' => false,
                    'type'     => 'string',
                ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/rakuten-advertisers', [
            'methods'             => 'GET',
            'permission_callback' => [ self::class, 'rest_permission' ],
            'callback'            => [ self::class, 'handle_rest_advertisers' ],
            'args'                => [
                'search' => [
                    'required' => false,
                    'type'     => 'string',
                ],
            ],
        ] );
    }

    public static function rest_permission() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rar_rakuten_not_logged_in', 'Authentication required.', [ 'status' => 401 ] );
        }
        if ( ! current_user_can( 'create_rar_redirects' ) ) {
            return new WP_Error( 'rar_rakuten_forbidden', 'You do not have permission to generate links.', [ 'status' => 403 ] );
        }
        return true;
    }

    public static function handle_rest( WP_REST_Request $request ) {
        if ( self::rate_limit_exceeded( get_current_user_id() ) ) {
            return new WP_Error( 'rar_rakuten_rate_limited', 'Too many link generations. Try again in a few minutes.', [ 'status' => 429 ] );
        }

        $result = self::generate(
            (string) $request->get_param( 'url' ),
            (string) $request->get_param( 'advertiser_id' ),
            (string) $request->get_param( 'name' )
        );

        if ( is_wp_error( $result ) ) {
            $result->add_data( [ 'status' => 400 ] );
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public static function handle_rest_advertisers( WP_REST_Request $request ) {
        $search      = strtolower( trim( (string) $request->get_param( 'search' ) ) );
        $advertisers = Cogito_RAR_Rakuten_API::get_advertisers();

        if ( '' !== $search ) {
            $advertisers = array_values( array_filter( $advertisers, static function ( $advertiser ) use ( $search ) {
                return false !== stripos( $advertiser['name'], $search )
                    || false !== stripos( $advertiser['host'], $search );
            } ) );
        }

        return rest_ensure_response( [
            'count'       => count( $advertisers ),
            'advertisers' => array_slice( $advertisers, 0, 50 ),
        ] );
    }

    public static function enqueue_editor_script( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'rar_redirect' !== $screen->post_type ) {
            return;
        }

        if ( ! Cogito_RAR_Rakuten_API::has_credentials() ) {
            return;
        }

        $rel_path = 'assets/js/cogito-rar-rakuten.js';
        $abs_path = dirname( __FILE__, 3 ) . '/' . $rel_path;
        if ( ! file_exists( $abs_path ) ) {
            return;
        }

        wp_enqueue_script(
            'cogito-rar-rakuten',
            plugin_dir_url( dirname( __FILE__, 2 ) ) . $rel_path,
            [],
            filemtime( $abs_path ),
            true
        );

        wp_localize_script( 'cogito-rar-rakuten', 'rarRakuten', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'action'  => self::AJAX_ACTION,
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            'network' => self::NETWORK_LABEL,
        ] );
    }
}
