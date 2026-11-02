<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Slug_Check {

    const CPT = 'rar_redirect';

    const AJAX_ACTION  = 'rar_check_slug';
    const NONCE_ACTION = 'rar_check_slug';

    public static function init() {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ self::class, 'handle_ajax' ] );
        add_action( 'rest_api_init', [ self::class, 'register_route' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_script' ] );
    }

    public static function check( $slug, $exclude_post_id = 0 ) {
        $slug = sanitize_title( (string) $slug );

        $result = [
            'slug'             => $slug,
            'available'        => true,
            'conflict'         => null,
            'content_conflict' => null,
        ];

        if ( '' === $slug ) {
            $result['available'] = false;
            return $result;
        }

        $existing = get_posts( [
            'post_type'        => self::CPT,
            'name'             => $slug,
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'exclude'          => $exclude_post_id ? [ (int) $exclude_post_id ] : [],
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ] );

        if ( ! empty( $existing ) ) {
            $id                  = (int) $existing[0];
            $result['available'] = false;
            $result['conflict']  = [
                'id'        => $id,
                'title'     => get_the_title( $id ),
                'status'    => get_post_status( $id ),
                'edit_link' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
            ];
            return $result;
        }

        $content = get_page_by_path( $slug, OBJECT, [ 'post', 'page' ] );
        if ( $content && (int) $content->ID !== (int) $exclude_post_id ) {
            $result['content_conflict'] = [
                'id'        => (int) $content->ID,
                'title'     => get_the_title( $content->ID ),
                'post_type' => $content->post_type,
                'url'       => get_permalink( $content->ID ),
            ];
        }

        return $result;
    }

    public static function is_taken( $slug, $exclude_post_id = 0 ) {
        $result = self::check( $slug, $exclude_post_id );
        return ! $result['available'];
    }

    public static function handle_ajax() {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }
        if ( ! current_user_can( 'edit_rar_redirects' ) ) {
            wp_send_json_error( [ 'message' => 'You do not have permission to do that.' ], 403 );
        }

        $slug    = isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '';
        $exclude = isset( $_POST['exclude_id'] ) ? absint( $_POST['exclude_id'] ) : 0;

        wp_send_json_success( self::check( $slug, $exclude ) );
    }

    public static function register_route() {
        register_rest_route( 'rar/v1', '/slug-available', [
            'methods'             => 'GET',
            'permission_callback' => [ self::class, 'rest_permission' ],
            'callback'            => [ self::class, 'handle_rest' ],
            'args'                => [
                'slug'       => [ 'required' => true, 'type' => 'string' ],
                'exclude_id' => [ 'required' => false, 'type' => 'integer' ],
            ],
        ] );
    }

    public static function rest_permission() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rar_slug_not_logged_in', 'Authentication required.', [ 'status' => 401 ] );
        }
        if ( ! current_user_can( 'edit_rar_redirects' ) ) {
            return new WP_Error( 'rar_slug_forbidden', 'You do not have permission to do that.', [ 'status' => 403 ] );
        }
        return true;
    }

    public static function handle_rest( WP_REST_Request $request ) {
        return rest_ensure_response(
            self::check(
                (string) $request->get_param( 'slug' ),
                (int) $request->get_param( 'exclude_id' )
            )
        );
    }

    public static function enqueue_script( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || self::CPT !== $screen->post_type ) {
            return;
        }

        $rel_path = 'assets/js/cogito-rar-slug-check.js';
        $abs_path = dirname( __FILE__, 2 ) . '/' . $rel_path;
        if ( ! file_exists( $abs_path ) ) {
            return;
        }

        wp_enqueue_script(
            'cogito-rar-slug-check',
            plugin_dir_url( dirname( __FILE__ ) ) . $rel_path,
            [],
            filemtime( $abs_path ),
            true
        );

        wp_localize_script( 'cogito-rar-slug-check', 'rarSlugCheck', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'action'    => self::AJAX_ACTION,
            'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
            'excludeId' => get_the_ID() ? (int) get_the_ID() : 0,
        ] );
    }
}
