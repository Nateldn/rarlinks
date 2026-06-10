<?php
/**
 * Registers the RARLink Custom Post Type.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_CPT_Registrar {

    const CPT = 'rar_redirect'; // Define CPT slug for this class

    /**
     * Initializes the CPT registration.
     * This method should be hooked to 'init'.
     */
    public static function init() {
        self::register_cpt_static();
        add_filter( 'post_type_link', [ self::class, 'filter_permalink' ], 10, 2 ); // Added filter hook for permalink
    }

    /**
     * Static method to register the Custom Post Type.
     * Can be called directly for activation hooks or normal init.
     */
    public static function register_cpt_static() {
        $labels = [
            'name'               => __( 'RARLinks', 'text_domain' ),
            'singular_name'      => __( 'RARLink', 'text_domain' ),
            'menu_name'          => __( 'RARLinks', 'text_domain' ),
            'name_admin_bar'     => __( 'RARLinks', 'text_domain' ),
            'add_new'            => __( 'Add New', 'text_domain' ),
            'add_new_item'       => __( 'Add New RARLink', 'text_domain' ),
            'edit_item'          => __( 'Edit RARLink', 'text_domain' ),
            'new_item'           => __( 'New RARLink', 'text_domain' ),
            'view_item'          => __( 'View RARLink', 'text_domain' ),
            'all_items'          => __( 'All RARLinks', 'text_domain' ),
            'search_items'       => __( 'Search RARLinks', 'text_domain' ),
            'not_found'          => __( 'No RARLinks found', 'text_domain' ),
            'not_found_in_trash' => __( 'No RARLinks found in Trash', 'text_domain' ),
        ];

        $args = [
            'label'               => __( 'RARLink', 'text_domain' ),
            'description'         => __( 'Manage vanity redirects with rotation and GEO targeting.', 'text_domain' ),
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_admin_bar'   => true,
            'menu_icon'           => 'dashicons-randomize',
            'supports'            => [ 'title' ],
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'capability_type'     => 'post',
            'show_in_rest'        => false,
            'can_export'          => true,
        ];

        register_post_type( self::CPT, $args );
    }

    /**
     * Filters the permalink to display the vanity URL in admin.
     * This method should be hooked to 'post_type_link'.
     *
     * @param string  $link The permalink.
     * @param WP_Post $post The post object.
     * @return string The filtered permalink.
     */
    public static function filter_permalink( $link, $post ) {
        return ( $post->post_type === self::CPT )
            ? home_url( '/' . $post->post_name . '/' )
            : $link;
    }
}