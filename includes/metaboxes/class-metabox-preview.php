<?php
/**
 * Renders the Vanity URL Preview meta box for RARLinks.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Metabox_Preview {

    /**
     * Renders the vanity URL preview.
     *
     * @param WP_Post $post The current post object.
     */
    public static function render( $post ) {
        $slug   = $post->post_name;
        $vanity = Cogito_RAR_Redirect_Engine::vanity_url( $slug );

        echo '<p><strong>Full Vanity URL:</strong> <code>' . esc_html( $vanity ) . '</code></p>';
    }
}