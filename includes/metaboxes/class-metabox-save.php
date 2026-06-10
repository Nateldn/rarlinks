<?php
/**
 * Handles saving of RAR Meta Box data and associated admin notices.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Metabox_Save {

    const CPT = 'rar_redirect'; // Ensure this matches your actual CPT slug from Cogito_RAR

    /**
     * Initializes save and notice hooks.
     */
    public static function init() {
        add_action( 'save_post', [ self::class, 'handle' ], 10, 2 );
        add_action( 'admin_notices', [ self::class, 'admin_notice_rotation_error' ] );
    }

    /**
     * Saves meta on post save. This method should be hooked to 'save_post'.
     *
     * @param int     $post_id The post ID.
     * @param WP_Post $post    The post object.
     */
    public static function handle( $post_id, $post ) {
        // Security checks (nonce, autosave, post type, user capability)
        if (
            empty( $_POST['rar_meta_nonce'] ) ||
            ! wp_verify_nonce( $_POST['rar_meta_nonce'], 'rar_save_meta' ) ||
            ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
            $post->post_type !== self::CPT ||
            ! current_user_can( 'edit_post', $post_id )
        ) {
            return;
        }

        // 🧭 Canonical Target URL: used for both saving and syncing rotation
        $canonical_target = esc_url_raw( $_POST['rar_target'] ?? '' );

        // Vanity slug (needs careful handling to avoid infinite loop)
        if ( isset( $_POST['rar_slug'] ) ) {
            $new = sanitize_title( wp_unslash( $_POST['rar_slug'] ) );
            if ( $new && $new !== $post->post_name ) {
                // Temporarily remove this hook to prevent infinite loop on wp_update_post
                remove_action( 'save_post', [ 'Cogito_RAR', 'save_meta' ], 10 ); // Remove old hook if it still exists
                remove_action( 'save_post', [ self::class, 'handle' ], 10 ); // Also remove self hook temporarily

                wp_update_post( [ 'ID' => $post_id, 'post_name' => $new ] );

                // Re-add the hook
                add_action( 'save_post', [ self::class, 'handle' ], 10, 2 );
                // If 'Cogito_RAR' save_meta is fully removed, this line is not needed.
                add_action( 'save_post', [ 'Cogito_RAR', 'save_meta' ], 10, 2 ); 
            }
        }

        // Simple fields
        update_post_meta( $post_id, '_rar_target', $canonical_target );
        update_post_meta( $post_id, '_rar_type',     intval( $_POST['rar_type']         ?? 302 ) );
        update_post_meta( $post_id, '_rar_notes',    sanitize_textarea_field( $_POST['rar_notes'] ?? '' ) );
        update_post_meta( $post_id, '_rar_nofollow', isset( $_POST['rar_nofollow'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_rar_sponsored', isset( $_POST['rar_sponsored'] ) ? '1' : '0' );
        // Save toggle states
        update_post_meta( $post_id, '_rar_geo_enabled', isset( $_POST['rar_geo_enabled'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_rar_rotation_enabled', isset( $_POST['rar_rotation_enabled'] ) ? '1' : '0' );
        // Save active state toggle
        update_post_meta( $post_id, '_rar_active', isset( $_POST['rar_active'] ) ? '1' : '0' );

        // Save Moto Partner (homepage native ad) flag
update_post_meta( $post_id, '_rar_moto_partner', isset( $_POST['rar_moto_partner'] ) ? '1' : '0' );


        $rot = []; // Start with empty array for cleaned entries
        if ( isset( $_POST['rar_rotation_enabled'] ) && $_POST['rar_rotation_enabled'] === '1' ) {
            $raw_rotation = $_POST['rar_rotation'] ?? [];

            if ( is_array( $raw_rotation ) ) {
                $total_weight = 0;

                foreach ( $raw_rotation as $i => $entry ) {
                    // 🔍 Sanitize and validate input
                    $url_raw    = $entry['url'] ?? '';
                    $weight_raw = $entry['weight'] ?? '';

                    $url    = esc_url_raw( $url_raw );
                    $weight = filter_var( $weight_raw, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0, 'max_range' => 100 ] ] );

                    if ( $weight === false || $weight === 0 || ! $url ) {
                        continue; // Skip invalid rows
                    }

                    // 🔁 Force canonical target as the first URL
                    if ( $i === 0 ) {
                        $url = $canonical_target;
                    }

                    $rot[] = [
                        'url'    => $url,
                        'weight' => $weight
                    ];

                    $total_weight += $weight;
                }

                // 📏 Auto-adjust if only one valid entry
                if ( count( $rot ) === 1 ) {
                    $rot[0]['weight'] = 100;
                }
                // ❌ Reject malformed totals
                elseif ( $total_weight !== 100 ) {
                    add_filter( 'redirect_post_location', function( $location ) {
                        return add_query_arg( 'rar_rotation_error', 1, $location );
                    });
                    return; // Don't save bad config
                }
            }
        }
        update_post_meta( $post_id, '_rar_rotation', wp_json_encode( $rot ) );


        // GEO targeting
        $gm = []; // Start with empty array to hold valid geo rules
        if ( ! empty( $_POST['rar_geo'] ) && is_array( $_POST['rar_geo'] ) ) {
            foreach ( $_POST['rar_geo'] as $entry ) {
                $country_raw = $entry['country'] ?? '';
                $url_raw     = $entry['url']     ?? '';

                // ✳️ Basic sanitization
                $country = strtoupper( sanitize_text_field( $country_raw ) );
                $url     = esc_url_raw( $url_raw );

                // ✅ Validate ISO 3166 alpha-2 country codes (2 uppercase letters)
                if ( preg_match( '/^[A-Z]{2}$/', $country ) && ! empty( $url ) ) {
                    $gm[] = [
                        'country' => $country,
                        'url'     => $url
                    ];
                }
            }
        }
        update_post_meta( $post_id, '_rar_geo', wp_json_encode( $gm ) );
    }

    /**
     * Displays admin notice if rotation weight is invalid.
     */
    public static function admin_notice_rotation_error() {
        if ( isset( $_GET['rar_rotation_error'] ) ) {
            echo '<div class="notice notice-error"><p><strong>RAR Error:</strong> Weighted rotation must total exactly 100.</p></div>';
        }
    }
}