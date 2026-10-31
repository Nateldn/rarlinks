<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Affiliate_Links {

    const AJAX_ACTION  = 'rar_affiliate_generate';
    const NONCE_ACTION = 'rar_affiliate_generate';

    const REST_NAMESPACE = 'rar/v1';

    const RATE_PREFIX = 'rar_affiliate_rate_';
    const RATE_WINDOW = 5 * MINUTE_IN_SECONDS;
    const RATE_MAX    = 60;

    public static function init() {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ self::class, 'handle_ajax' ] );
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_editor_script' ] );
    }

    public static function generate( $url, $network = '', $advertiser_id = '', $name = '', $sub_id = null ) {
        $url = trim( (string) $url );
        if ( '' === $url || ! wp_http_validate_url( $url ) ) {
            return new WP_Error( 'rar_affiliate_bad_url', 'That does not look like a valid URL.' );
        }

        $configured = Cogito_RAR_Affiliate_Networks::configured();
        if ( empty( $configured ) ) {
            return new WP_Error(
                'rar_affiliate_none_configured',
                'No affiliate network is configured. Add credentials under Settings → Affiliate Networks.'
            );
        }

        $provider        = null;
        $advertiser_name = '';
        $confidence      = 'manual';

        if ( '' !== $network ) {
            $provider = Cogito_RAR_Affiliate_Networks::get( $network );
            if ( null === $provider || ! $provider->is_configured() ) {
                return new WP_Error(
                    'rar_affiliate_unknown_network',
                    sprintf( '"%s" is not a configured affiliate network.', $network )
                );
            }
        }

        if ( null !== $provider && '' === $advertiser_id ) {
            $result = $provider->match_advertiser( $url );
            if ( empty( $result['match'] ) ) {
                return self::no_match_error( $url, $provider, isset( $result['candidates'] ) ? $result['candidates'] : [] );
            }
            $advertiser_id   = $result['match']['id'];
            $advertiser_name = $result['match']['name'];
            $confidence      = isset( $result['confidence'] ) ? $result['confidence'] : '';
        }

        if ( null === $provider ) {
            $matched = Cogito_RAR_Affiliate_Networks::match( $url );

            if ( null === $matched['hit'] ) {
                $host = Cogito_RAR_Rakuten_API::normalise_host( $url );

                if ( ! empty( $matched['candidates'] ) ) {
                    return new WP_Error(
                        'rar_affiliate_ambiguous',
                        sprintf(
                            'Several advertisers could match %s: %s. Choose a network and advertiser ID.',
                            $host,
                            Cogito_RAR_Affiliate_Networks::describe_candidates( $matched['candidates'] )
                        )
                    );
                }

                return new WP_Error(
                    'rar_affiliate_no_match',
                    sprintf(
                        'No advertiser in any configured network matches %s. Search the advertiser list under Settings → Affiliate Networks and supply the network and advertiser ID.',
                        $host
                    )
                );
            }

            $provider        = $matched['hit']['provider'];
            $advertiser_id   = $matched['hit']['advertiser']['id'];
            $advertiser_name = $matched['hit']['advertiser']['name'];
            $confidence      = $matched['hit']['confidence'];
        }

        if ( '' === $advertiser_name && method_exists( $provider, 'get_advertiser_by_id' ) ) {
            $known = $provider->get_advertiser_by_id( $advertiser_id );
            if ( null !== $known ) {
                $advertiser_name = $known['name'];
            }
        }

        $sub_id = ( null === $sub_id || '' === $sub_id )
            ? $provider->default_sub_id()
            : (string) $sub_id;

        $result = $provider->generate( $url, $advertiser_id, $name, $sub_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( empty( $result['advertiser_name'] ) ) {
            $result['advertiser_name'] = $advertiser_name;
        }

        $result['source_url'] = $url;
        $result['network']    = $provider->label();
        $result['network_id'] = $provider->id();
        $result['confidence'] = $confidence;
        $result['sub_id']     = $sub_id;

        return $result;
    }

    private static function no_match_error( $url, $provider, $candidates ) {
        $host = Cogito_RAR_Rakuten_API::normalise_host( $url );

        if ( ! empty( $candidates ) ) {
            $names = [];
            foreach ( $candidates as $candidate ) {
                $names[] = sprintf( '%s (%s, ID %s)', $candidate['name'], $candidate['host'], $candidate['id'] );
            }
            return new WP_Error(
                'rar_affiliate_ambiguous',
                sprintf(
                    'Several %s advertisers could match %s: %s. Supply the advertiser ID you want.',
                    $provider->label(),
                    $host,
                    implode( '; ', $names )
                )
            );
        }

        return new WP_Error(
            'rar_affiliate_no_match',
            sprintf( 'No %s advertiser matches %s. Supply an advertiser ID, or try another network.', $provider->label(), $host )
        );
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

        $result = self::generate(
            isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '',
            isset( $_POST['network'] ) ? sanitize_key( wp_unslash( $_POST['network'] ) ) : '',
            isset( $_POST['advertiser_id'] ) ? sanitize_text_field( wp_unslash( $_POST['advertiser_id'] ) ) : '',
            isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
            isset( $_POST['sub_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_id'] ) ) : null
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    public static function register_routes() {
        register_rest_route( self::REST_NAMESPACE, '/affiliate-link', [
            'methods'             => 'POST',
            'permission_callback' => [ self::class, 'rest_permission' ],
            'callback'            => [ self::class, 'handle_rest' ],
            'args'                => [
                'url'           => [ 'required' => true, 'type' => 'string' ],
                'network'       => [ 'required' => false, 'type' => 'string' ],
                'advertiser_id' => [ 'required' => false, 'type' => 'string' ],
                'name'          => [ 'required' => false, 'type' => 'string' ],
                'sub_id'        => [ 'required' => false, 'type' => 'string' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/affiliate-networks', [
            'methods'             => 'GET',
            'permission_callback' => [ self::class, 'rest_permission' ],
            'callback'            => [ self::class, 'handle_rest_networks' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/rakuten-advertisers', [
            'methods'             => 'GET',
            'permission_callback' => [ self::class, 'rest_permission' ],
            'callback'            => [ self::class, 'handle_rest_advertisers' ],
            'args'                => [
                'search' => [ 'required' => false, 'type' => 'string' ],
            ],
        ] );
    }

    public static function rest_permission() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rar_affiliate_not_logged_in', 'Authentication required.', [ 'status' => 401 ] );
        }
        if ( ! current_user_can( 'create_rar_redirects' ) ) {
            return new WP_Error( 'rar_affiliate_forbidden', 'You do not have permission to generate links.', [ 'status' => 403 ] );
        }
        return true;
    }

    public static function handle_rest( WP_REST_Request $request ) {
        if ( self::rate_limit_exceeded( get_current_user_id() ) ) {
            return new WP_Error( 'rar_affiliate_rate_limited', 'Too many link generations. Try again in a few minutes.', [ 'status' => 429 ] );
        }

        $result = self::generate(
            (string) $request->get_param( 'url' ),
            (string) $request->get_param( 'network' ),
            (string) $request->get_param( 'advertiser_id' ),
            (string) $request->get_param( 'name' ),
            null === $request->get_param( 'sub_id' ) ? null : (string) $request->get_param( 'sub_id' )
        );

        if ( is_wp_error( $result ) ) {
            $result->add_data( [ 'status' => 400 ] );
            return $result;
        }

        return rest_ensure_response( $result );
    }

    public static function handle_rest_networks() {
        $networks = [];

        foreach ( Cogito_RAR_Affiliate_Networks::all() as $provider ) {
            $networks[] = [
                'id'         => $provider->id(),
                'label'      => $provider->label(),
                'configured' => (bool) $provider->is_configured(),
            ];
        }

        return rest_ensure_response( [ 'networks' => $networks ] );
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

        $configured = Cogito_RAR_Affiliate_Networks::configured();
        if ( empty( $configured ) ) {
            return;
        }

        $rel_path = 'assets/js/cogito-rar-affiliate.js';
        $abs_path = dirname( __FILE__, 3 ) . '/' . $rel_path;
        if ( ! file_exists( $abs_path ) ) {
            return;
        }

        wp_enqueue_script(
            'cogito-rar-affiliate',
            plugin_dir_url( dirname( __FILE__, 2 ) ) . $rel_path,
            [],
            filemtime( $abs_path ),
            true
        );

        $networks = [];
        foreach ( $configured as $provider ) {
            $networks[] = [ 'id' => $provider->id(), 'label' => $provider->label() ];
        }

        $first = reset( $configured );

        wp_localize_script( 'cogito-rar-affiliate', 'rarAffiliate', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'action'       => self::AJAX_ACTION,
            'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
            'networks'     => $networks,
            'subIdLabel'   => $first ? $first->sub_id_label() : 'Sub ID',
            'defaultSubId' => $first ? $first->default_sub_id() : '',
        ] );
    }
}
