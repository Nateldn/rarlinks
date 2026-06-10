<?php
/**
 * Renders the collapsible Moto Partner panel above the dashboard list table.
 * Lists links currently flagged as homepage native ads, each with a Disable action.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Moto_Partner_Panel {

    /**
     * Renders the panel. Called from the dashboard render method.
     */
    public static function render() {
        // Fetch all links currently flagged as Moto Partners
        $partners = get_posts( [
            'post_type'      => 'rar_redirect',
            'posts_per_page' => -1,
            'meta_key'       => '_rar_moto_partner',
            'meta_value'     => '1',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $count = count( $partners );

        // Nonce for the AJAX disable action (consumed by the toggle handler)
        $nonce = wp_create_nonce( 'rar_moto_partner_nonce' );

        echo '<div class="rar-moto-panel" data-nonce="' . esc_attr( $nonce ) . '">';

        // Toggle header — shows the count, click to expand/collapse
        echo '<button type="button" class="button rar-moto-panel-toggle">';
        echo 'Moto Partners (<span class="rar-moto-count">' . esc_html( $count ) . '</span>)';
        echo '</button>';

        // Collapsible body (hidden by default)
        echo '<div class="rar-moto-panel-body" style="display:none;">';

        if ( $count === 0 ) {
            echo '<p class="rar-moto-empty">No links are currently marked as Moto Partners.</p>';
        } else {
            echo '<ul class="rar-moto-list">';
            foreach ( $partners as $partner ) {
                echo '<li data-post-id="' . esc_attr( $partner->ID ) . '">';
                echo '<span class="rar-moto-title">' . esc_html( $partner->post_title ) . '</span> ';
                echo '<button type="button" class="button-link rar-moto-disable">Disable</button>';
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</div>'; // .rar-moto-panel-body
        echo '</div>'; // .rar-moto-panel
    }
}