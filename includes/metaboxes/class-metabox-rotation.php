<?php
/**
 * Renders the Weighted Rotation meta box for RARLinks (PHP portion).
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Metabox_Rotation {

    /**
     * Renders the weighted rotation fields (PHP only, no inline JS).
     *
     * @param WP_Post $post The current post object.
     */
    public static function render( $post ) {
        // Rotation only has anything to act on at the moment of a
        // server-side redirect, which a track-only link never has (see
        // Cogito_RAR_Track_Only) — shown greyed-out and non-interactive
        // rather than hidden, so it's clear WHAT'S disabled and why,
        // instead of a note with nothing visible for it to refer to.
        $track_only = class_exists( 'Cogito_RAR_Track_Only' ) && Cogito_RAR_Track_Only::is_track_only( $post );

        // Load existing values
        $rotation = json_decode( get_post_meta( $post->ID, '_rar_rotation', true ) ?: '[]', true );
        $rot_enabled = get_post_meta( $post->ID, '_rar_rotation_enabled', true );
        $target_url_for_rotation_default = get_post_meta( $post->ID, '_rar_target', true ); // Used for default first item in rotation if empty

        // Default row if empty
        if ( empty( $rotation ) ) {
            $rotation = [ [ 'url' => $target_url_for_rotation_default, 'weight' => 100 ] ];
        }

        if ( $track_only ) {
            echo '<p class="description"><strong>Rotation Disabled</strong> for track-only links.</p>';
        }

        // pointer-events (not just disabled attributes) also catches the
        // Remove links below, which are plain <a> tags a <fieldset> alone
        // wouldn't grey out.
        echo '<div' . ( $track_only ? ' style="opacity:0.5; pointer-events:none;"' : '' ) . '>';

        // --- Enable Weighted Rotation Toggle ---
        echo '<div class="rartoggle">
            <input type="checkbox" id="rar_rotation_enabled" name="rar_rotation_enabled" value="1"' . checked( $rot_enabled, '1', false ) . ( $track_only ? ' disabled' : '' ) . ' />
            <label for="rar_rotation_enabled"></label>
            <strong>Enable Weighted Rotation</strong>
        </div>';

        // --- Weighted Rotation UI ---

        echo '<div id="rar-rotation">';
        echo '<h4>Weighted Rotation</h4>';

        foreach ( $rotation as $i => $r ) {
            $url    = esc_attr( $r['url'] );
            $weight = intval( $r['weight'] );
            $disabled = $track_only ? ' disabled' : '';

            echo '<div class="rar-rotation-row" data-index="'. $i .'">';

            // URL input
            echo '<label>URL:
                <input type="url" name="rar_rotation['. $i .'][url]" value="'. $url .'" style="width:60%;"'. ( $i === 0 ? ' readonly' : '' ) . $disabled .'>
            </label>';

            // Weight slider + number input
            echo '<label>Weight:
                <input type="range" class="weight-slider" min="0" max="100" step="1" value="'. $weight .'" data-index="'. $i .'"' . $disabled . '>
                <input type="number" class="weight-input" name="rar_rotation['. $i .'][weight]" value="'. $weight .'" min="0" max="100" style="width:60px;"' . $disabled . '>
            </label>';

            // Remove button (except for first row)
            if ( $i > 0 ) {
                echo '<a href="#" class="remove-rotation" style="margin-left:10px;">Remove</a>';
            }

            echo '</div>';
        }
        echo '<p><button type="button" class="button" id="add-rotation"' . ( $track_only ? ' disabled' : '' ) . '>+ Add Rotation URL</button></p>';
        echo '</div>'; // Close #rar-rotation
        echo '</div>'; // Close the greyed-out wrapper

    }
}