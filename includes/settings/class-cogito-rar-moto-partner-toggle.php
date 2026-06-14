<?php
/**
 * Handles the AJAX toggle for disabling a RARLink's Moto Partner flag
 * from the dashboard panel, without a page reload.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Moto_Partner_Toggle {

    /**
     * Registers the AJAX handler.
     * Must run on every admin load so the hook exists when the AJAX request arrives.
     */
    public static function init() {
        add_action( 'wp_ajax_rar_disable_moto_partner', [ self::class, 'handle_disable' ] );
    }

    /**
     * AJAX handler: removes the Moto Partner flag from a given RARLink.
     * Verifies nonce and capability before making any change.
     */
    public static function handle_disable() {
        // 🔒 Verify nonce (sent as 'nonce' in the AJAX request)
        if ( ! check_ajax_referer( 'rar_moto_partner_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
        }

        // 🔒 Verify the user is allowed to manage options (dashboard-level capability)
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        // 🔢 Validate the post ID
        $post_id = isset( $_POST['post_id'] ) && is_numeric( $_POST['post_id'] )
            ? intval( $_POST['post_id'] )
            : 0;

        if ( ! $post_id || get_post_type( $post_id ) !== 'rar_redirect' ) {
            wp_send_json_error( [ 'message' => 'Invalid RARLink.' ], 400 );
        }

        // ✅ Un-flag as a partner via the period model, which also closes the
        // open live window (preserving the dates for the re-scan's history).
        Cogito_RAR_Moto_Partner::sync_on_save( $post_id, false, 'archived' );

        // Return the new count of currently-LIVE partners for the heading
        $partners = get_posts( [
            'post_type'      => 'rar_redirect',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_rar_moto_partner',
            'meta_value'     => '1',
        ] );
        $live = 0;
        foreach ( $partners as $pid ) {
            if ( Cogito_RAR_Moto_Partner::is_currently_live( $pid ) ) {
                $live++;
            }
        }

        wp_send_json_success( [
            'post_id'   => $post_id,
            'remaining' => $live,
        ] );
    }
}