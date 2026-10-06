<?php
/**
 * Central class for initializing all RARLinks Meta Box functionalities.
 * Handles registration of the add_meta_boxes hook.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Metaboxes { // Consistent with 'class-metabox-{name}.php' convention

    const CPT = 'rar_redirect'; // Define CPT slug for this class if needed

    /**
     * Initializes all meta box-related hooks.
     * This method should be called once from the plugin bootstrap.
     */
    public static function init() {
        // 2 accepted args: $post_type (to bail early on every other post
        // type's edit screen) and $post (to check track-only status for
        // the title below).
        add_action( 'add_meta_boxes', [ self::class, 'add_meta_box' ], 10, 2 );

        // Note: The 'save_post' and 'admin_notices' hooks are registered
        // directly by Cogito_RAR_Metabox_Save::init() from the bootstrap.
        // The 'post_type_link' filter is registered by Cogito_RAR_CPT_Registrar::init().
        // This class focuses on the 'add_meta_boxes' display hook.
    }

    /**
     * Adds the main redirect details meta box to the CPT screen.
     * This method calls the main render_meta_box method from Cogito_RAR.
     * Note: Cogito_RAR::render_meta_box will eventually be moved here.
     *
     * @param string       $post_type
     * @param WP_Post|null $post
     */
    public static function add_meta_box( $post_type, $post = null ) {
        if ( self::CPT !== $post_type ) {
            return;
        }

        // ✅ Only allow users who can edit RARLinks to see the meta box —
        // RARLinks has its own capability namespace (see
        // Cogito_RAR_CPT_Registrar), so this can't rely on the generic,
        // regular-Posts-specific 'edit_posts' check any more.
        if ( current_user_can( 'edit_rar_redirects' ) ) {
            // "Redirect Details" doesn't fit a link that has no redirect —
            // see Cogito_RAR_Track_Only.
            $track_only = is_object( $post ) && class_exists( 'Cogito_RAR_Track_Only' ) && Cogito_RAR_Track_Only::is_track_only( $post );

            add_meta_box(
                'rar_details',
                $track_only ? 'Track-Only Details' : 'Redirect Details',
                [ self::class, 'render_meta_box' ],
                self::CPT,
                'normal',
                'high'
            );
        }
    }

    /*── Render Metaboxes ─────────────────────────────────*/
    /**
     * Renders the content of the meta box.
     * This method orchestrates calls to individual rendering classes.
     *
     * @param WP_Post $post The post object.
     */
	public static function render_meta_box( $post ) {
	// 🚫 Prevent unauthorized users from accessing meta box content
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		echo '<p><em>You do not have permission to edit this redirect.</em></p>';
		return;
	}

	    // Render basic fields using the new class
        Cogito_RAR_Metabox_Basic_Fields::render( $post );
        
        // ─── Weighted Rotation ─────────────────────────
        Cogito_RAR_Metabox_Rotation::render( $post );
        
        // ─── GEO Targeting UI ─────────────────────────
        Cogito_RAR_Metabox_Geo::render( $post );

		// Full Vanity Preview ─────────────────────────
		Cogito_RAR_Metabox_Preview::render( $post );
        echo '</div>'; // Close rar-meta-fields container
        
        // Note: All inline JS is in assets/js/rar-admin-metabox.js

    }
                            
}