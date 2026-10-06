<?php
/**
 * Renders the GEO Targeting meta box for RARLinks (PHP portion).
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Metabox_Geo {

    /**
     * Renders the GEO targeting fields (PHP only, no inline JS).
     *
     * @param WP_Post $post The current post object.
     */
    public static function render( $post ) {
        // GEO targeting only has anything to act on at the moment of a
        // server-side redirect, which a track-only link never has (see
        // Cogito_RAR_Track_Only) — shown greyed-out and non-interactive
        // rather than hidden, so it's clear WHAT'S disabled and why,
        // instead of a note with nothing visible for it to refer to.
        $track_only = class_exists( 'Cogito_RAR_Track_Only' ) && Cogito_RAR_Track_Only::is_track_only( $post );

        // Load existing values
        $geo_enabled = get_post_meta( $post->ID, '_rar_geo_enabled', true );
        $geo         = json_decode( get_post_meta( $post->ID, '_rar_geo', true ) ?: '[]', true );

        // Load countries list from JSON helper
        $json_file = plugin_dir_path( __FILE__ ) . '../countries-list.json'; // Adjust path as needed
        $countries = [];

        if ( file_exists( $json_file ) ) {
            $json = file_get_contents( $json_file );
            $countries = json_decode( $json, true );
        }

        // Output datalist for countries
        echo '<datalist id="rar-country-list">';
        foreach ( $countries as $code => $name ) {
            echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $name ) . '</option>';
        }
        echo '</datalist>';

        if ( $track_only ) {
            echo '<p class="description"><strong>GEO Disabled</strong> for track-only links.</p>';
        }

        // pointer-events (not just disabled attributes) also catches the
        // Remove links below, which are plain <a> tags a <fieldset> alone
        // wouldn't grey out.
        echo '<div' . ( $track_only ? ' style="opacity:0.5; pointer-events:none;"' : '' ) . '>';

        // --- Enable GEO Targeting Toggle ---
        echo '<div class="rartoggle">
            <input type="checkbox" id="rar_geo_enabled" name="rar_geo_enabled" value="1"' . checked( $geo_enabled, '1', false ) . ( $track_only ? ' disabled' : '' ) . ' />
            <label for="rar_geo_enabled"></label>
         Enable GEO Targeting
        </div>';

        // --- GEO Targeting UI ---
        echo '<div id="rar-geo">';
        echo '<h4>GEO Targeting</h4>';

        if ( empty( $geo ) ) {
            $geo = array( array( 'country' => '', 'url' => '' ) );
        }
        foreach ( $geo as $i => $g ) {
            echo '<div class="rar-geo-row" data-index="' . $i . '">
                <label>Country:<input list="rar-country-list" name="rar_geo[' . $i . '][country]" value="' . esc_attr( $g['country'] ) . '" style="width:25%;"' . ( $track_only ? ' disabled' : '' ) . ' /></label>
                <label>URL:<input type="url" name="rar_geo[' . $i . '][url]" value="' . esc_attr( $g['url'] ) . '" style="width:60%;"' . ( $track_only ? ' disabled' : '' ) . ' /></label>';
            echo ' <a href="#" class="remove-geo"' . ( $i === 0 ? ' style="display:inline;"' : '' ) . '>Remove</a>';
            echo '</div>';
        }
        echo '<p><button type="button" class="button" id="add-geo"' . ( $track_only ? ' disabled' : '' ) . '>+ Add GEO Rule</button></p>';
        echo '</div>'; // Close #rar-geo
        echo '</div>'; // Close the greyed-out wrapper
    }
}