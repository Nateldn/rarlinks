<?php
/**
 * Renders the Conversions tab on the RARLinks settings page: master
 * toggle, provider status, queue visibility, and a manual flush trigger.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Settings_Conversions {

    public static function init() {
        add_action( 'rar_settings_render_tab_conversions', [ self::class, 'render' ] );
        add_action( 'admin_init', [ self::class, 'maybe_handle_save' ] );
        add_action( 'admin_init', [ self::class, 'maybe_handle_flush' ] );
    }

    private static function tab_url() {
        return add_query_arg(
            [ 'post_type' => 'rar_redirect', 'page' => 'rar_settings', 'tab' => 'conversions' ],
            admin_url( 'edit.php' )
        );
    }

    /**
     * Saves the master toggle + hold-window setting.
     */
    public static function maybe_handle_save() {
        if ( ! isset( $_POST['rar_conversions_settings_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST['rar_conversions_settings_nonce'] ), 'rar_conversions_settings' ) ) {
            wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
        }

        update_option( Cogito_RAR_Conversion_Capture::OPTION_ENABLED, isset( $_POST['rar_conversions_enabled'] ) ? '1' : '0' );

        $hold = isset( $_POST['rar_conversions_hold_minutes'] ) ? absint( $_POST['rar_conversions_hold_minutes'] ) : 60;
        update_option( Cogito_RAR_Conversion_Capture::OPTION_HOLD_MINUTES, $hold );

        // Stored as raw text (sanitised per-line, not per-textarea) so the
        // form shows back exactly what was typed, including blank spacer
        // lines — get_tracked_selectors() does the real cleanup on read.
        $selectors_raw = isset( $_POST['rar_conversions_tracked_selectors'] )
            ? sanitize_textarea_field( wp_unslash( $_POST['rar_conversions_tracked_selectors'] ) )
            : '';
        update_option( Cogito_RAR_Conversion_Capture::OPTION_TRACKED_SELECTORS, $selectors_raw );

        wp_safe_redirect( add_query_arg( 'saved', 1, self::tab_url() ) );
        exit;
    }

    /**
     * Manual flush trigger — lets a provider be tested the moment its
     * wp-config token is added, without needing a working cron job first.
     */
    public static function maybe_handle_flush() {
        if ( ! isset( $_POST['rar_conversions_flush_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST['rar_conversions_flush_nonce'] ), 'rar_conversions_flush' ) ) {
            wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
        }

        $summary = class_exists( 'Cogito_RAR_Conversion_Dispatcher' )
            ? Cogito_RAR_Conversion_Dispatcher::flush_all()
            : [];

        wp_safe_redirect( add_query_arg( 'flushed', rawurlencode( wp_json_encode( $summary ) ), self::tab_url() ) );
        exit;
    }

    public static function render() {
        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }
        if ( isset( $_GET['flushed'] ) ) {
            $summary = json_decode( wp_unslash( $_GET['flushed'] ), true );
            echo '<div class="notice notice-success is-dismissible"><p>Flush complete: ';
            echo empty( $summary )
                ? 'no providers are currently configured.'
                : esc_html( wp_json_encode( $summary ) );
            echo '</p></div>';
        }

        $enabled          = get_option( Cogito_RAR_Conversion_Capture::OPTION_ENABLED ) === '1';
        $hold             = (int) get_option( Cogito_RAR_Conversion_Capture::OPTION_HOLD_MINUTES, 60 );
        $selectors_raw    = (string) get_option( Cogito_RAR_Conversion_Capture::OPTION_TRACKED_SELECTORS, '' );
        $selectors_parsed = Cogito_RAR_Conversion_Capture::get_tracked_selectors();

        echo '<div class="rar-conversions">';
        echo '<h3>Conversions</h3>';
        echo '<p>Server-side conversion events for affiliate clicks, starting with Meta\'s Conversions API. ';
        echo '<strong>Off by default</strong> — nothing is captured or sent until enabled below, and only RARLink clicks the bot-detection waterfall classifies as human are ever queued.</p>';

        echo '<form method="post" action="' . esc_url( self::tab_url() ) . '">';
        wp_nonce_field( 'rar_conversions_settings', 'rar_conversions_settings_nonce' );
        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row">Enable conversions</th><td>';
        echo '<label><input type="checkbox" name="rar_conversions_enabled" value="1" ' . checked( $enabled, true, false ) . '> ';
        echo 'Capture and send affiliate-click conversion events</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Hold before sending</th><td>';
        echo '<input type="number" min="0" name="rar_conversions_hold_minutes" value="' . esc_attr( $hold ) . '" style="width:80px;"> minutes';
        echo '<p class="description">Before a queued event is actually sent, it\'s re-checked against your current bot-detection data (not just the click-time snapshot) — a free safety net that needs no action from you.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Tracked buttons &amp; links</th><td>';
        echo '<textarea name="rar_conversions_tracked_selectors" rows="6" style="width:100%; max-width:500px; font-family:monospace;" placeholder="' . esc_attr( ".affi_btn\n.affi_group\n.lr-button" ) . '">' . esc_textarea( $selectors_raw ) . '</textarea>';
        echo '<p class="description">One CSS selector per line — a class (<code>.affi_btn</code>), an element+class (<code>a.lr-button</code>), or a container (<code>div.affi_btn_wrap a</code>). ';
        echo 'Add a new line whenever you create a new button/link style you want tracked; lines starting with <code>#</code> are ignored as comments. ';
        echo 'This is the list the click-listener script (raw, non-RARLink affiliate links — not built yet) will match against; nothing consumes it yet, but it\'s safe to start curating now.</p>';
        if ( ! empty( $selectors_parsed ) ) {
            echo '<p class="description">Currently parsed as: <code>' . esc_html( implode( ', ', $selectors_parsed ) ) . '</code></p>';
        }
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button( 'Save Settings' );
        echo '</form>';

        echo '<h4>Providers</h4><ul class="rar-conversions-providers">';
        foreach ( Cogito_RAR_Conversion_Providers::all() as $provider ) {
            $status = $provider->is_enabled()
                ? '<span class="rar-status-on">Configured</span>'
                : '<span class="rar-status-off">Not configured — add its access token constant to wp-config.php</span>';
            echo '<li><strong>' . esc_html( $provider->get_label() ) . ':</strong> ' . $status . '</li>';
        }
        echo '</ul>';

        $counts = class_exists( 'Cogito_RAR_Conversion_Queue' ) ? Cogito_RAR_Conversion_Queue::get_counts() : [];
        echo '<h4>Queue</h4>';
        echo '<p>';
        echo 'Pending: <strong>' . esc_html( $counts['pending'] ?? 0 ) . '</strong> &nbsp; ';
        echo 'Sent: <strong>' . esc_html( $counts['sent'] ?? 0 ) . '</strong> &nbsp; ';
        echo 'Failed (will retry): <strong>' . esc_html( $counts['failed'] ?? 0 ) . '</strong> &nbsp; ';
        echo 'Permanently failed: <strong>' . esc_html( $counts['permanently_failed'] ?? 0 ) . '</strong>';
        echo '</p>';

        echo '<form method="post" action="' . esc_url( self::tab_url() ) . '">';
        wp_nonce_field( 'rar_conversions_flush', 'rar_conversions_flush_nonce' );
        submit_button( 'Flush Now', 'secondary', 'submit', false );
        echo '</form>';

        $recent = class_exists( 'Cogito_RAR_Conversion_Queue' ) ? Cogito_RAR_Conversion_Queue::get_recent( 20 ) : [];
        if ( ! empty( $recent ) ) {
            echo '<h4>Recent events</h4>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>ID</th><th>Provider</th><th>Event</th><th>Status</th><th>Created</th><th>Error</th>';
            echo '</tr></thead><tbody>';
            foreach ( $recent as $row ) {
                echo '<tr>';
                echo '<td>' . esc_html( $row->id ) . '</td>';
                echo '<td>' . esc_html( $row->provider ) . '</td>';
                echo '<td>' . esc_html( $row->event_name ) . '</td>';
                echo '<td>' . esc_html( $row->status ) . '</td>';
                echo '<td>' . esc_html( $row->created_at ) . '</td>';
                echo '<td>' . esc_html( $row->last_error ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>'; // .rar-conversions
    }
}
