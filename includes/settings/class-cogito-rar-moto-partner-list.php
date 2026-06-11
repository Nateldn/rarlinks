<?php
/**
 * Renders the Moto Partner list within the Bot Filtering settings tab.
 * Lists links currently flagged as homepage native ads, each with a Disable action.
 * Tells the bot-detection waterfall which links legitimately sit on the homepage.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Moto_Partner_List {

    /**
     * Renders the Moto Partner list. Hooked to the Bot Filtering settings tab.
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

        // Heading with live count
        echo '<h3>Moto Partners (<span class="rar-moto-count">' . esc_html( $count ) . '</span>)</h3>';
        echo '<p class="description">Links currently flagged as homepage native ads. A click arriving from the homepage is only treated as a possible human if it lands on one of these links.</p>';

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

        echo '</div>'; // .rar-moto-panel
    }
}