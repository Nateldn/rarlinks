<?php
/**
 * Renders the Defaults tab on the RARLinks settings page: the plugin-wide
 * defaults new RARLinks are created with, plus the global toggles that
 * previously had no admin surface at all (click tracking, bot filtering,
 * the visitor cookie, click-log retention) and a one-click bot-click purge.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Settings_Defaults {

    const OPTION_REDIRECT_TYPE          = 'rar_default_redirect_type';
    const OPTION_ACTIVE                 = 'rar_default_active';
    const OPTION_NOFOLLOW                = 'rar_default_nofollow';
    const OPTION_SPONSORED              = 'rar_default_sponsored';
    const OPTION_FALLBACK_URL           = 'rar_fallback_target_url';
    const OPTION_CLICK_TRACKING_ENABLED = 'rar_click_tracking_enabled';
    const OPTION_DISABLE_VISITOR_COOKIE = 'rar_disable_visitor_cookie';
    const OPTION_BOT_FILTERING_ENABLED  = 'rar_bot_filtering_enabled';

    public static function init() {
        add_action( 'rar_settings_render_tab_defaults', [ self::class, 'render' ] );
        add_action( 'admin_init', [ self::class, 'maybe_handle_save' ] );
        add_action( 'admin_init', [ self::class, 'maybe_handle_delete_bots' ] );
    }

    private static function tab_url() {
        return add_query_arg(
            [ 'post_type' => 'rar_redirect', 'page' => 'rar_settings', 'tab' => 'defaults' ],
            admin_url( 'edit.php' )
        );
    }

    public static function maybe_handle_save() {
        if ( ! isset( $_POST['rar_defaults_settings_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST['rar_defaults_settings_nonce'] ), 'rar_defaults_settings' ) ) {
            wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
        }

        $type = isset( $_POST['rar_default_redirect_type'] ) ? (int) $_POST['rar_default_redirect_type'] : 307;
        update_option( self::OPTION_REDIRECT_TYPE, in_array( $type, [ 301, 302, 307 ], true ) ? $type : 307 );

        update_option( self::OPTION_ACTIVE, isset( $_POST['rar_default_active'] ) ? '1' : '0' );
        update_option( self::OPTION_NOFOLLOW, isset( $_POST['rar_default_nofollow'] ) ? '1' : '0' );
        update_option( self::OPTION_SPONSORED, isset( $_POST['rar_default_sponsored'] ) ? '1' : '0' );

        $fallback = isset( $_POST['rar_fallback_target_url'] ) ? esc_url_raw( wp_unslash( $_POST['rar_fallback_target_url'] ) ) : '';
        update_option( self::OPTION_FALLBACK_URL, $fallback );

        update_option( self::OPTION_CLICK_TRACKING_ENABLED, isset( $_POST['rar_click_tracking_enabled'] ) ? '1' : '0' );
        update_option( self::OPTION_DISABLE_VISITOR_COOKIE, isset( $_POST['rar_disable_visitor_cookie'] ) ? '1' : '0' );
        update_option( self::OPTION_BOT_FILTERING_ENABLED, isset( $_POST['rar_bot_filtering_enabled'] ) ? '1' : '0' );

        if ( class_exists( 'Cogito_RAR_Datacenter_IP_Feed' ) ) {
            update_option( Cogito_RAR_Datacenter_IP_Feed::OPTION_ENABLED, isset( $_POST['rar_datacenter_ip_filtering_enabled'] ) ? '1' : '0' );
        }

        if ( class_exists( 'Cogito_RAR_Retention' ) ) {
            $days = isset( $_POST['rar_clicks_retention_days'] ) ? absint( $_POST['rar_clicks_retention_days'] ) : Cogito_RAR_Retention::DEFAULT_CLICKS_RETENTION_DAYS;
            update_option( Cogito_RAR_Retention::OPTION_CLICKS_RETENTION_DAYS, max( 1, $days ) );

            $ip_days = isset( $_POST['rar_ip_redaction_days'] ) ? absint( $_POST['rar_ip_redaction_days'] ) : Cogito_RAR_Retention::DEFAULT_IP_REDACTION_DAYS;
            update_option( Cogito_RAR_Retention::OPTION_IP_REDACTION_DAYS, max( 1, $ip_days ) );
        }

        wp_safe_redirect( add_query_arg( 'saved', 1, self::tab_url() ) );
        exit;
    }

    /**
     * One-click purge of confirmed-bot click-log rows (bot_or_not = 1).
     * Deliberately excludes "Unknown" (2) rows — those are ambiguous, not
     * confirmed bots, and deleting them would throw away real uncertainty
     * rather than resolve it.
     */
    public static function maybe_handle_delete_bots() {
        if ( ! isset( $_POST['rar_delete_bot_clicks_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST['rar_delete_bot_clicks_nonce'] ), 'rar_delete_bot_clicks' ) ) {
            wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'rarlinks_clicks';
        $deleted = $wpdb->query( "DELETE FROM $table WHERE bot_or_not = 1" );

        wp_safe_redirect( add_query_arg( 'bots_deleted', (int) $deleted, self::tab_url() ) );
        exit;
    }

    public static function render() {
        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }
        if ( isset( $_GET['bots_deleted'] ) ) {
            $count = absint( $_GET['bots_deleted'] );
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html( sprintf( '%d confirmed-bot click%s deleted.', $count, 1 === $count ? '' : 's' ) );
            echo '</p></div>';
        }

        $type              = (int) get_option( self::OPTION_REDIRECT_TYPE, 307 );
        $active            = get_option( self::OPTION_ACTIVE, '1' ) === '1';
        $nofollow          = get_option( self::OPTION_NOFOLLOW, '1' ) === '1';
        $sponsored         = get_option( self::OPTION_SPONSORED, '1' ) === '1';
        $fallback          = (string) get_option( self::OPTION_FALLBACK_URL, '' );
        $click_tracking    = get_option( self::OPTION_CLICK_TRACKING_ENABLED, '1' ) === '1';
        $disable_cookie    = get_option( self::OPTION_DISABLE_VISITOR_COOKIE, '0' ) === '1';
        $bot_filtering     = get_option( self::OPTION_BOT_FILTERING_ENABLED, '1' ) === '1';
        $datacenter_ip     = class_exists( 'Cogito_RAR_Datacenter_IP_Feed' )
            && get_option( Cogito_RAR_Datacenter_IP_Feed::OPTION_ENABLED ) === '1';
        $retention_days    = class_exists( 'Cogito_RAR_Retention' )
            ? (int) get_option( Cogito_RAR_Retention::OPTION_CLICKS_RETENTION_DAYS, Cogito_RAR_Retention::DEFAULT_CLICKS_RETENTION_DAYS )
            : 180;
        $ip_redaction_days = class_exists( 'Cogito_RAR_Retention' )
            ? (int) get_option( Cogito_RAR_Retention::OPTION_IP_REDACTION_DAYS, Cogito_RAR_Retention::DEFAULT_IP_REDACTION_DAYS )
            : 30;

        echo '<div class="rar-conversions">'; // Reuses the card/toggle styling built for the Conversions tab

        echo '<div class="rar-card">';
        echo '<h3>Defaults</h3>';
        echo '<p>Plugin-wide defaults and global toggles. These apply to <strong>new</strong> RARLinks and to overall behaviour — they never change an existing link\'s own saved settings.</p>';
        echo '</div>';

        echo '<div class="rar-card">';
        echo '<h4>New-RARLink defaults</h4>';
        echo '<form method="post" action="' . esc_url( self::tab_url() ) . '">';
        wp_nonce_field( 'rar_defaults_settings', 'rar_defaults_settings_nonce' );
        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row">Default redirect type</th><td>';
        echo '<select name="rar_default_redirect_type">';
        echo '<option value="301"' . selected( $type, 301, false ) . '>301 (Permanent)</option>';
        echo '<option value="302"' . selected( $type, 302, false ) . '>302 (Temporary)</option>';
        echo '<option value="307"' . selected( $type, 307, false ) . '>307 (Preserve Method)</option>';
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Default active state</th><td>';
        echo '<div class="rartoggle"><input type="checkbox" id="rar_default_active" name="rar_default_active" value="1"' . checked( $active, true, false ) . '><label for="rar_default_active"></label><span>New RARLinks start active</span></div>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Default rel attributes</th><td>';
        echo '<label><input type="checkbox" name="rar_default_nofollow" value="1"' . checked( $nofollow, true, false ) . '> <code>rel="nofollow"</code></label><br>';
        echo '<label><input type="checkbox" name="rar_default_sponsored" value="1"' . checked( $sponsored, true, false ) . '> <code>rel="sponsored"</code></label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Fallback target URL</th><td>';
        echo '<input type="url" name="rar_fallback_target_url" value="' . esc_attr( $fallback ) . '" style="width:100%; max-width:500px;" placeholder="https://renchlist.com/">';
        echo '<p class="description">Used only if a RARLink\'s GEO rules, rotation, and its own target URL all fail to resolve to something valid — without this, that visitor would otherwise hit a blank page. Leave blank to disable.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button( 'Save Settings' );
        echo '</form>';
        echo '</div>'; // .rar-card

        echo '<div class="rar-card">';
        echo '<h4>Global behaviour</h4>';
        echo '<form method="post" action="' . esc_url( self::tab_url() ) . '">';
        wp_nonce_field( 'rar_defaults_settings', 'rar_defaults_settings_nonce' );
        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row">Click tracking</th><td>';
        echo '<div class="rartoggle"><input type="checkbox" id="rar_click_tracking_enabled" name="rar_click_tracking_enabled" value="1"' . checked( $click_tracking, true, false ) . '><label for="rar_click_tracking_enabled"></label><span>Log RARLink clicks</span></div>';
        echo '<p class="description">Off stops new rows being written to the Clicks Report entirely — redirects themselves are never affected either way.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Bot filtering</th><td>';
        echo '<div class="rartoggle"><input type="checkbox" id="rar_bot_filtering_enabled" name="rar_bot_filtering_enabled" value="1"' . checked( $bot_filtering, true, false ) . '><label for="rar_bot_filtering_enabled"></label><span>Run the bot-detection waterfall on every click</span></div>';
        echo '<p class="description">Off logs every click as Unknown rather than running detection — bots and humans become indistinguishable in the Clicks Report, and nothing is ever queued for Conversions (that gate requires a click classified as human).</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Datacenter IP filtering</th><td>';
        echo '<div class="rartoggle"><input type="checkbox" id="rar_datacenter_ip_filtering_enabled" name="rar_datacenter_ip_filtering_enabled" value="1"' . checked( $datacenter_ip, true, false ) . '><label for="rar_datacenter_ip_filtering_enabled"></label><span>Flag clicks from known cloud/hosting-provider IP ranges (AWS, Google Cloud, Cloudflare, DigitalOcean, Linode, Vultr, Oracle, Hetzner, Fastly)</span></div>';
        echo '<p class="description"><strong>Off by default.</strong> Unlike the other bot-detection checks, this one carries a real risk of also flagging a genuine visitor on a VPN or corporate proxy hosted on one of these providers — Apple iCloud Private Relay traffic is specifically exempted, but nothing else is. Only enable this if you\'re actively seeing hosting-provider IPs in your Bot Report, and check back there afterwards to confirm it isn\'t catching real visitors.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Visitor cookie</th><td>';
        echo '<div class="rartoggle"><input type="checkbox" id="rar_disable_visitor_cookie" name="rar_disable_visitor_cookie" value="1"' . checked( $disable_cookie, true, false ) . '><label for="rar_disable_visitor_cookie"></label><span>Disable the persistent rar_uid visitor cookie</span></div>';
        echo '<p class="description">A privacy-leaning toggle, not a compliance guarantee — get your own legal advice on what "GDPR-safe" actually requires for this site. Disabling it removes the 6-month cross-session identifier; the referrer/cookie bot-detection check then falls back to treating every visitor as first-time, which will flag more real visitors as Unknown.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Click-log retention</th><td>';
        echo '<input type="number" min="1" name="rar_clicks_retention_days" value="' . esc_attr( $retention_days ) . '" style="width:80px;"> days';
        echo '<p class="description">Click-log rows older than this are purged automatically by a daily cron. Defaults to 180 days, matching the visitor cookie\'s own lifetime.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">IP address redaction</th><td>';
        echo '<input type="number" min="1" name="rar_ip_redaction_days" value="' . esc_attr( $ip_redaction_days ) . '" style="width:80px;"> days';
        echo '<p class="description">A click\'s IP address is shown in Clicks Report/Bot Cleanup for this long (for WHOIS lookups and flagging decisions), then irreversibly hashed in place by the same daily cron — well before the row itself is eventually deleted above. Defaults to 30 days.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button( 'Save Settings' );
        echo '</form>';
        echo '</div>'; // .rar-card

        echo '<div class="rar-card">';
        echo '<h4>Bot cleanup</h4>';
        echo '<p>Permanently deletes every click row already classified as a confirmed bot (not "Unknown"). This cannot be undone.</p>';
        echo '<form method="post" action="' . esc_url( self::tab_url() ) . '" onsubmit="return confirm(\'Permanently delete every confirmed-bot click row? This cannot be undone.\');">';
        wp_nonce_field( 'rar_delete_bot_clicks', 'rar_delete_bot_clicks_nonce' );
        submit_button( 'Delete Bot Clicks', 'secondary', 'submit', false );
        echo '</form>';
        echo '</div>'; // .rar-card

        echo '</div>'; // .rar-conversions
    }
}
