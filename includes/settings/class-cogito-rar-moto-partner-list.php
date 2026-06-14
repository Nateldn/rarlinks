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
        // Fetch all links flagged as Moto Partners (live + archived)
        $partners = get_posts( [
            'post_type'      => 'rar_redirect',
            'posts_per_page' => -1,
            'meta_key'       => '_rar_moto_partner',
            'meta_value'     => '1',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        // Only currently-live partners count toward the homepage's ~3 slots
        $live_count = 0;
        foreach ( $partners as $partner ) {
            if ( Cogito_RAR_Moto_Partner::is_currently_live( $partner->ID ) ) {
                $live_count++;
            }
        }

        // Nonce for the AJAX disable action (consumed by the toggle handler)
        $nonce = wp_create_nonce( 'rar_moto_partner_nonce' );

        echo '<div class="rar-moto-panel" data-nonce="' . esc_attr( $nonce ) . '">';

        // Heading with live count
        echo '<h3>Moto Partners (<span class="rar-moto-count">' . esc_html( $live_count ) . '</span> live)</h3>';
        echo '<p class="description">Links flagged as homepage native ads. A homepage-referrer click counts as possible-human only while the link is <strong>Live</strong>; Archived partners keep their recorded live dates so the re-scan still credits clicks from when they were live.</p>';

        if ( empty( $partners ) ) {
            echo '<p class="rar-moto-empty">No links are marked as Moto Partners.</p>';
        } else {
            echo '<ul class="rar-moto-list">';
            foreach ( $partners as $partner ) {
                $is_live = Cogito_RAR_Moto_Partner::is_currently_live( $partner->ID );
                $since   = Cogito_RAR_Moto_Partner::live_since( $partner->ID );

                echo '<li data-post-id="' . esc_attr( $partner->ID ) . '">';
                echo '<span class="rar-moto-title">' . esc_html( $partner->post_title ) . '</span> ';
                echo '<span class="rar-moto-badge rar-moto-badge-' . ( $is_live ? 'live' : 'archived' ) . '">' . ( $is_live ? 'Live' : 'Archived' ) . '</span> ';
                if ( $since ) {
                    echo '<span class="rar-moto-since">since ' . esc_html( $since ) . '</span> ';
                }
                echo '<button type="button" class="button-link rar-moto-disable">Remove</button>';
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</div>'; // .rar-moto-panel
    }
}