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
     * Renders the vanity URL preview with a one-click copy button.
     *
     * @param WP_Post $post The current post object.
     */
    public static function render( $post ) {
        $slug   = $post->post_name;
        $vanity = Cogito_RAR_Redirect_Engine::vanity_url( $slug );

        echo '<p><strong>Full Vanity URL:</strong></p>';
        // Reuses the copy button markup/classes from the RARLinks list table
        // (class-cogito-rar-admin-columns.php) — its click handler is bound
        // admin-wide, so it picks this button up with no extra JS.
        echo '<div class="rar-copy-slug-wrap">';
        echo '<input type="text" class="rar-copy-input" value="' . esc_url( $vanity ) . '" readonly>';
        echo '<button type="button" class="rar-copy-btn button" data-copy="' . esc_url( $vanity ) . '" aria-label="Copy Vanity URL">';
        echo '<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>';
        echo '</button>';
        echo '<span class="rar-copy-feedback" style="display:none; margin-left:8px;">Copied!</span>';
        echo '</div>';
    }
}