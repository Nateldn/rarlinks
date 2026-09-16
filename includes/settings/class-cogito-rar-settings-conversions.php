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
     * Renders one event's name + identifiers block, laid out inline
     * (name, classes/IDs, and remove all on one row).
     *
     * Field names carry an EXPLICIT, matching index — "rar_conversions_
     * events[$index][name]" and "[$index][identifiers]" — rather than
     * empty "[]" brackets. Empty brackets look like they'd auto-pair a
     * row's fields together, but PHP actually assigns each "[]" occurrence
     * its OWN new top-level index regardless of which subkey follows, so
     * a name input and identifiers textarea declared as two separate "[]"
     * fields land in DIFFERENT array entries — never paired, and both
     * missing their other half, so every row is silently dropped on save.
     * An explicit shared index avoids this entirely. PHP doesn't need
     * these indices to be sequential or even numeric — just unique per
     * row and matching between a row's own name/identifiers pair — so a
     * JS-added row can safely use something like Date.now().
     *
     * @param array      $event Raw stored entry: name, identifiers, and
     *                          optional field_destination_url/field_link_text/
     *                          field_link_classes/field_event_source_url.
     * @param int|string $index
     */
    private static function render_event_row( array $event, $index ) {
        $name            = $event['name'] ?? '';
        $identifiers_raw = $event['identifiers'] ?? '';
        $parsed          = '' !== trim( (string) $identifiers_raw ) ? Cogito_RAR_Conversion_Capture::parse_identifier_groups( $identifiers_raw ) : [];
        $base            = 'rar_conversions_events[' . esc_attr( $index ) . ']';

        echo '<div class="rar-event-row">';
        echo '<div class="rar-event-row-fields">';
        echo '<label class="rar-event-field rar-event-field--name">Event name<br>';
        echo '<input type="text" name="' . $base . '[name]" value="' . esc_attr( $name ) . '" placeholder="e.g. NewsletterClick" style="width:100%; font-family:monospace;"></label>';
        echo '<label class="rar-event-field rar-event-field--identifiers">Tracked classes &amp; IDs<br>';
        echo '<textarea name="' . $base . '[identifiers]" rows="2" style="width:100%; font-family:monospace;" placeholder="' . esc_attr( "affi_btn\nrl_wrap rl_drift" ) . '">' . esc_textarea( $identifiers_raw ) . '</textarea></label>';
        echo '<button type="button" class="button-link rar-remove-event">Remove</button>';
        echo '</div>';
        if ( ! empty( $parsed ) ) {
            echo '<div class="rar-chip-row">';
            foreach ( $parsed as $group ) {
                echo '<code class="rar-chip">' . esc_html( implode( ' + ', $group ) ) . '</code>';
            }
            echo '</div>';
        }

        echo '<details class="rar-event-field-names"><summary>Custom parameter names (optional)</summary>';
        echo '<div class="rar-event-row-fields">';
        foreach ( Cogito_RAR_Conversion_Capture::DEFAULT_FIELD_NAMES as $signal => $default ) {
            $label       = self::FIELD_NAME_LABELS[ $signal ] ?? $signal;
            $placeholder = '' !== $default ? $default : 'not sent unless named';
            echo '<label class="rar-event-field rar-event-field--param">' . esc_html( $label ) . '<br>';
            echo '<input type="text" name="' . $base . '[field_' . esc_attr( $signal ) . ']" value="' . esc_attr( $event[ 'field_' . $signal ] ?? '' ) . '" placeholder="' . esc_attr( $placeholder ) . '" style="width:100%; font-family:monospace;"></label>';
        }
        echo '</div>';
        echo '<p class="description">The custom_data field name(s) this event sends to Meta — matches how a GA4 event tag in GTM lets you name each parameter. Leave any blank to use the default shown as its placeholder; "Page/Referrer URL" is not sent at all unless named (it\'s already sent separately as a required standard field either way).</p>';
        echo '</details>';

        echo '</div>';
    }

    /** Human-readable labels for the DEFAULT_FIELD_NAMES signal keys. */
    const FIELD_NAME_LABELS = [
        'destination_url'  => 'Destination URL',
        'link_text'        => 'Link text',
        'link_classes'     => 'Link classes',
        'event_source_url' => 'Page/Referrer URL',
    ];

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

        $hold = isset( $_POST['rar_conversions_hold_minutes'] ) ? absint( $_POST['rar_conversions_hold_minutes'] ) : 0;
        update_option( Cogito_RAR_Conversion_Capture::OPTION_HOLD_MINUTES, $hold );

        // Self-service event list — add/remove/rename freely, no code
        // change ever needed. Each entry keeps its raw textarea text
        // (sanitised per-line, not per-textarea) so the form shows back
        // exactly what was typed; get_event_definitions() does the real
        // parsing on read. A row missing either a name or any identifiers
        // is dropped rather than saved as a dead/unmatchable entry.
        $events_posted = isset( $_POST['rar_conversions_events'] ) && is_array( $_POST['rar_conversions_events'] )
            ? $_POST['rar_conversions_events']
            : [];

        $events = [];
        foreach ( $events_posted as $entry ) {
            $name        = Cogito_RAR_Conversion_Capture::sanitize_event_name( $entry['name'] ?? '' );
            $identifiers = sanitize_textarea_field( wp_unslash( (string) ( $entry['identifiers'] ?? '' ) ) );

            // Only drop a row that's COMPLETELY empty (an unused blank "add
            // another event" row). A name typed in before its classes/IDs
            // — or vice versa — is real in-progress work; dropping it on
            // save just because it isn't finished yet would look like the
            // row "disappeared" the moment you save mid-edit.
            if ( '' === $name && '' === trim( $identifiers ) ) {
                continue;
            }

            $saved = [ 'name' => $name, 'identifiers' => $identifiers ];
            foreach ( array_keys( Cogito_RAR_Conversion_Capture::DEFAULT_FIELD_NAMES ) as $signal ) {
                $override = trim( (string) ( $entry[ 'field_' . $signal ] ?? '' ) );
                if ( '' !== $override ) {
                    $saved[ 'field_' . $signal ] = Cogito_RAR_Conversion_Capture::sanitize_event_name( $override );
                }
            }
            $events[] = $saved;

            if ( count( $events ) >= Cogito_RAR_Conversion_Capture::MAX_EVENTS ) {
                break;
            }
        }
        update_option( Cogito_RAR_Conversion_Capture::OPTION_EVENT_DEFINITIONS, $events );

        $domains_raw = isset( $_POST['rar_conversions_raw_link_domains'] )
            ? sanitize_textarea_field( wp_unslash( $_POST['rar_conversions_raw_link_domains'] ) )
            : '';
        update_option( Cogito_RAR_Conversion_Raw_Link_Capture::OPTION_ALLOWED_DOMAINS, $domains_raw );

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
        $hold             = (int) get_option( Cogito_RAR_Conversion_Capture::OPTION_HOLD_MINUTES, 0 );
        $events_raw       = Cogito_RAR_Conversion_Capture::get_event_definitions_raw();
        $raw_link_domains = (string) get_option( Cogito_RAR_Conversion_Raw_Link_Capture::OPTION_ALLOWED_DOMAINS, '' );

        echo '<div class="rar-conversions">';

        echo '<div class="rar-card">';
        echo '<h3>Conversions</h3>';
        echo '<p>Server-side conversion events for affiliate clicks, starting with Meta\'s Conversions API. ';
        echo '<strong>Off by default</strong> — nothing is captured or sent until enabled below. Bot clicks are never sent to Meta: every click is checked with the same bot-detection your Clicks Report already uses, and only clicks it marks as human get queued.</p>';
        echo '</div>';

        echo '<div class="rar-card">';
        echo '<h4>Settings</h4>';
        echo '<form method="post" action="' . esc_url( self::tab_url() ) . '">';
        wp_nonce_field( 'rar_conversions_settings', 'rar_conversions_settings_nonce' );
        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row">Enable conversions</th><td>';
        echo '<div class="rartoggle">';
        echo '<input type="checkbox" id="rar_conversions_enabled" name="rar_conversions_enabled" value="1"' . checked( $enabled, true, false ) . '>';
        echo '<label for="rar_conversions_enabled"></label>';
        echo '<span>Capture and send affiliate-click conversion events</span>';
        echo '</div>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Hold before sending</th><td>';
        echo '<input type="number" min="0" name="rar_conversions_hold_minutes" value="' . esc_attr( $hold ) . '" style="width:80px;"> minutes';
        echo '<p class="description">Kept at 0 by default — events go out automatically roughly every minute rather than waiting. Whenever a queued event is actually sent, it\'s re-checked against your current bot-detection data (not just the click-time snapshot) — a free safety net that needs no action from you. Raise this only if you want more of a buffer before that re-check happens.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Tracked events</th><td>';
        echo '<p class="description">Each event you define here is entirely self-service — add a new one, rename one, or remove one any time a landing page needs a new button/ad style tracked. No code change is ever needed.</p>';
        echo '<div id="rar-events-repeater">';
        if ( empty( $events_raw ) ) {
            self::render_event_row( [], 0 );
        } else {
            foreach ( array_values( $events_raw ) as $index => $event ) {
                self::render_event_row( $event, $index );
            }
        }
        echo '</div>';
        echo '<p><button type="button" class="button" id="rar-add-event">+ Add another event</button></p>';
        echo '<p class="description">One class or ID per line, no CSS syntax — just the plain name. Put more than one on a line (space-separated) to require them all together. ';
        echo 'First matching event wins. A RARLink click always counts as AffiliateClick regardless of this list. See the README for the full explanation.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Allowed destination domains</th><td>';
        echo '<textarea name="rar_conversions_raw_link_domains" rows="4" style="width:100%; max-width:500px; font-family:monospace;" placeholder="' . esc_attr( "revzilla.com\nsaltflatsclothing.co.uk" ) . '">' . esc_textarea( $raw_link_domains ) . '</textarea>';
        echo '<p class="description">One domain per line (subdomains match automatically, e.g. <code>revzilla.com</code> also allows <code>imp.revzilla.com</code>). ';
        echo 'Only applies to raw, non-RARLink links — a security check against a forged/malicious request claiming an arbitrary destination. ';
        echo '<strong>Left blank, any HTTPS destination is allowed</strong> — tighten this once you know which merchant/network domains your tracked buttons actually point to.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button( 'Save Settings' );
        echo '</form>';
        echo '</div>'; // .rar-card

        echo '<div class="rar-card">';
        echo '<h4>Providers</h4><ul class="rar-conversions-providers">';
        foreach ( Cogito_RAR_Conversion_Providers::all() as $provider ) {
            $status = $provider->is_enabled()
                ? '<span class="rar-status-on">Configured</span>'
                : '<span class="rar-status-off">Not configured — add its access token constant to wp-config.php</span>';
            echo '<li><strong>' . esc_html( $provider->get_label() ) . ':</strong> ' . $status . '</li>';
        }
        echo '</ul>';

        $counts     = class_exists( 'Cogito_RAR_Conversion_Queue' ) ? Cogito_RAR_Conversion_Queue::get_counts() : [];
        $stat_defs  = [
            'pending'             => [ 'label' => 'Pending', 'tone' => 'neutral' ],
            'sent'                => [ 'label' => 'Sent', 'tone' => 'good' ],
            'failed'              => [ 'label' => 'Failed (will retry)', 'tone' => 'warn' ],
            'permanently_failed'  => [ 'label' => 'Permanently failed', 'tone' => 'bad' ],
        ];
        echo '<h4>Queue</h4>';
        echo '<div class="rar-stat-row">';
        foreach ( $stat_defs as $key => $def ) {
            echo '<div class="rar-stat rar-stat--' . esc_attr( $def['tone'] ) . '">';
            echo '<span class="rar-stat-value">' . esc_html( $counts[ $key ] ?? 0 ) . '</span>';
            echo '<span class="rar-stat-label">' . esc_html( $def['label'] ) . '</span>';
            echo '</div>';
        }
        echo '</div>';

        echo '<p class="description">Events dispatch automatically roughly every minute — this button is only for triggering it immediately (e.g. while testing).</p>';
        echo '<form method="post" action="' . esc_url( self::tab_url() ) . '">';
        wp_nonce_field( 'rar_conversions_flush', 'rar_conversions_flush_nonce' );
        submit_button( 'Flush Now', 'secondary', 'submit', false );
        echo '</form>';
        echo '</div>'; // .rar-card

        $recent = class_exists( 'Cogito_RAR_Conversion_Queue' ) ? Cogito_RAR_Conversion_Queue::get_recent( 20 ) : [];
        if ( ! empty( $recent ) ) {
            $badge_tone = [
                'sent'               => 'good',
                'pending'            => 'neutral',
                'failed'             => 'warn',
                'permanently_failed' => 'bad',
            ];
            echo '<div class="rar-card">';
            echo '<h4>Recent events</h4>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>ID</th><th>Provider</th><th>Event</th><th>Status</th><th>Created</th><th>Error</th>';
            echo '</tr></thead><tbody>';
            foreach ( $recent as $row ) {
                $tone = $badge_tone[ $row->status ] ?? 'neutral';
                echo '<tr>';
                echo '<td>' . esc_html( $row->id ) . '</td>';
                echo '<td>' . esc_html( $row->provider ) . '</td>';
                echo '<td>' . esc_html( $row->event_name ) . '</td>';
                echo '<td><span class="rar-badge rar-badge--' . esc_attr( $tone ) . '">' . esc_html( $row->status ) . '</span></td>';
                echo '<td>' . esc_html( cogito_rar_localise_utc_timestamp( $row->created_at ) ) . '</td>';
                echo '<td>' . esc_html( $row->last_error ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>'; // .rar-card
        }

        echo '</div>'; // .rar-conversions
    }
}
