<?php
/**
 * Renders the basic fields meta box for RARLinks.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Metabox_Basic_Fields {

    /**
     * Renders the basic redirect details fields.
     *
     * @param WP_Post $post The current post object.
     */
    public static function render( $post ) {
        // Load existing values
        $slug      = $post->post_name;
        $target    = get_post_meta( $post->ID, '_rar_target', true );
        $type      = get_post_meta( $post->ID, '_rar_type', true );
        if ( empty( $type ) ) {
            $type = 307;
        }
        $notes     = get_post_meta( $post->ID, '_rar_notes',  true );
        $nofollow  = get_post_meta( $post->ID, '_rar_nofollow', true );
        $sponsored = get_post_meta( $post->ID, '_rar_sponsored', true );
        $is_active = get_post_meta( $post->ID, '_rar_active', true );
        if ( $is_active === '' ) $is_active = '1'; // Default to active
        $moto_partner = get_post_meta( $post->ID, '_rar_moto_partner', true ); // Homepage Moto Partner native ad flag
        $moto_status  = get_post_meta( $post->ID, '_rar_moto_partner_status', true ) ?: 'live'; // Live | Archived

        // Output the nonce field (important for security)
        wp_nonce_field( 'rar_save_meta', 'rar_meta_nonce' );

        // --- Active Toggle Switch (Enable/Disable Redirect) ---
        echo '<div id="rar-active-toggle" class="rartoggle">';
        echo '<input type="checkbox" id="rar_active" name="rar_active" value="1"' . checked( $is_active, '1', false ) . '>';
        echo '<label for="rar_active"></label>';
        echo '<span>Activate/Deactivate</span>';
        echo '</div>';

        // RARLink status messages
        if ( $is_active !== '1' ) {
            echo '<p class="rar-inactive-note"> <i class="fas fa-ban"></i> Redirect Deactivated: Toggle to activate and save to enable redirect options.</p>';
        } else {
            echo '<p class="rar-active-note"> <i class="fas fa-check"></i> Redirect Active: Toggle to deactivate and disable redirect options.</p>';
        }

        echo '<div id="rar-meta-fields">'; // Container for the rest of the fields

        // --- Target URL (textarea) ---
        echo '<p><label>Target URL:<br>
        <textarea name="rar_target" rows="2" style="width:100%;">' . esc_textarea( $target ) . '</textarea>
        </label></p>';

        // --- Vanity Slug ---
        echo '<p><label>Vanity Link (slug after domain):<br>
        <input type="text" name="rar_slug" value="' . esc_attr( $slug ) . '" style="width:100%;" />
        </label></p>';

        // --- Redirect Type ---
        echo '<p><label>Redirect Type:
        <select name="rar_type">
            <option value="301"' . selected( $type, 301, false ) . '>301 (Permanent)</option>
            <option value="302"' . selected( $type, 302, false ) . '>302 (Temporary)</option>
            <option value="307"' . selected( $type, 307, false ) . '>307 (Preserve Method)</option>
        </select>
        </label></p>';

        // --- rel="nofollow sponsored" header toggles ---
        echo '<p><label><input type="checkbox" name="rar_nofollow" value="1"' . checked( $nofollow !== '0', true, false ) . '> Add <code>rel="nofollow"</code></label></p>';
        echo '<p><label><input type="checkbox" name="rar_sponsored" value="1"' . checked( $sponsored !== '0', true, false ) . '> Add <code>rel="sponsored"</code></label></p>';

// --- Moto Partner (Homepage Native Ad) flag ---
// Marks this RARLink as a homepage native ad, used by bot detection
// to validate clicks arriving with the homepage as referrer.
echo '<p><label><input type="checkbox" name="rar_moto_partner" value="1"' . checked( $moto_partner, '1', false ) . '> Moto Partner (Homepage Native Ad)</label></p>';

// Live / Archived status. "Live" means it is currently on the homepage, so a
// homepage-referrer click can be human. "Archived" means it was a partner but
// is no longer live, so homepage-referrer clicks now are treated as bots — the
// plugin auto-records the dates it was live for the re-scan to honour history.
echo '<div class="rar-moto-status">';
echo '<label><input type="radio" name="rar_moto_partner_status" value="live"' . checked( $moto_status, 'live', false ) . '> Live (currently on the homepage)</label> ';
echo '<label><input type="radio" name="rar_moto_partner_status" value="archived"' . checked( $moto_status, 'archived', false ) . '> Archived (was on the homepage)</label>';

// Show the recorded live windows for reference
$periods = Cogito_RAR_Moto_Partner::get_periods( $post->ID );
if ( ! empty( $periods ) ) {
    echo '<p class="description rar-moto-periods">Recorded live periods: ';
    $parts = [];
    foreach ( $periods as $p ) {
        $from   = isset( $p['from'] ) ? esc_html( $p['from'] ) : '?';
        $to     = empty( $p['to'] ) ? 'now' : esc_html( $p['to'] );
        $parts[] = $from . ' → ' . $to;
    }
    echo implode( ', ', $parts );
    echo '</p>';
}
echo '</div>';

// Fetch OTHER links and count only those currently LIVE (homepage shows ~3)
$existing_partners = get_posts( [
    'post_type'      => 'rar_redirect',
    'posts_per_page' => -1,
    'meta_key'       => '_rar_moto_partner',
    'meta_value'     => '1',
    'exclude'        => [ $post->ID ], // Don't count the current post
] );
$live_partners = array_filter( $existing_partners, static function ( $p ) {
    return Cogito_RAR_Moto_Partner::is_currently_live( $p->ID );
} );

// Soft warning if 3 or more OTHERS are already live (homepage shows max 3)
if ( count( $live_partners ) >= 3 ) {
    echo '<div class="rar-moto-partner-warning">';
    echo '⚠️ ' . count( $live_partners ) . ' other links are already Live Moto Partners. The homepage typically shows only 3:';
    echo '<ul class="rar-moto-partner-list">';
    foreach ( $live_partners as $partner ) {
        $edit_link = get_edit_post_link( $partner->ID );
        echo '<li><a href="' . esc_url( $edit_link ) . '">' . esc_html( $partner->post_title ) . '</a></li>';
    }
    echo '</ul>';
    echo '</div>';
}

        // --- Notes ---
        echo '<p><label>Notes:<br>
        <textarea name="rar_notes" rows="3" style="width:100%;">' . esc_textarea( $notes ) . '</textarea>
        </label></p>';
        // NO CLOSING </div> HERE FOR rar-meta-fields - IT WILL BE CLOSED IN THE MAIN RENDER METHOD.
        // NO SCRIPT HERE.
    }
}