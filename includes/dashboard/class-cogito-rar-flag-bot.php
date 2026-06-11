<?php
/**
 * Handles the "Flag as bot" AJAX action from the clicks list table.
 *
 * Marks a single click row as a bot (bot_or_not = 1) and optionally appends
 * the row's chosen signals (IP / hostname / org / user agent) to the live
 * bot list option, which the click logger will use to auto-flag future
 * matching clicks.
 *
 * Server logic only — the panel markup lives in the clicks list table class.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Flag_Bot {

    /**
     * The wp_options key holding the user-grown live bot list.
     * Structure: [ 'ip' => [...], 'hostname' => [...], 'org' => [...], 'ua' => [...] ]
     */
    const OPTION_KEY = 'rar_live_bot_list';

    /**
     * Maps the signal keys accepted from the client to their DB columns.
     * Signal VALUES are always read from the DB row, never from the request,
     * so a tampered request can only ever blacklist that row's own data.
     */
    const SIGNAL_COLUMNS = [
        'ip'       => 'ip_address',
        'hostname' => 'hostname',
        'org'      => 'org',
        'ua'       => 'user_agent',
    ];

    /**
     * Registers the AJAX handler.
     * Must run on every admin load so the hook exists when the AJAX request arrives.
     */
    public static function init() {
        add_action( 'wp_ajax_rar_flag_bot', [ self::class, 'handle_flag' ] );
    }

    /**
     * AJAX handler: flags a click row as a bot and stores any chosen signals.
     * Verifies nonce and capability before making any change.
     */
    public static function handle_flag() {
        global $wpdb;

        // 🔒 Verify nonce (sent as 'nonce' in the AJAX request)
        if ( ! check_ajax_referer( 'rar_flag_bot_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
        }

        // 🔒 Verify the user is allowed to manage options (dashboard-level capability)
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        // 🔢 Validate the click row ID
        $click_id = isset( $_POST['click_id'] ) ? absint( $_POST['click_id'] ) : 0;
        if ( ! $click_id ) {
            wp_send_json_error( [ 'message' => 'Invalid click ID.' ], 400 );
        }

        // 📄 Fetch the row — it is the single source of truth for signal values
        $table = $wpdb->prefix . 'rarlinks_clicks';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $click_id ) );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'Click row not found.' ], 404 );
        }

        // 🚩 Flag the row as a bot. Preserve an existing bot_name (e.g. an
        // auto-detected bot Nate is adding signals from); otherwise record
        // that this was a manual call so the report shows how it was flagged.
        $update = [ 'bot_or_not' => 1 ];
        if ( (int) $row->bot_or_not !== 1 || empty( $row->bot_name ) ) {
            $update['bot_name'] = 'Manually flagged';
        }
        $updated = $wpdb->update( $table, $update, [ 'id' => $click_id ] );

        if ( false === $updated ) {
            wp_send_json_error( [ 'message' => 'Database update failed.' ], 500 );
        }

        // 📥 Whitelist the requested signal types (anything else is discarded)
        $requested = isset( $_POST['signals'] ) && is_array( $_POST['signals'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['signals'] ) )
            : [];
        $requested = array_intersect( $requested, array_keys( self::SIGNAL_COLUMNS ) );

        // 📝 Append each chosen signal's value (from the DB row) to the live list
        $added = self::add_signals_to_live_list( $row, $requested );

        wp_send_json_success( [
            'click_id' => $click_id,
            'added'    => $added, // Signal types actually appended (deduped, empties skipped)
        ] );
    }

    /**
     * Appends the given signal types' values from a click row to the live bot list.
     * Skips empty values and exact duplicates.
     *
     * @param object $row            The click row from wp_rarlinks_clicks.
     * @param array  $signal_types   Whitelisted signal keys to add ('ip', 'hostname', 'org', 'ua').
     * @return array Signal types that were actually appended.
     */
    private static function add_signals_to_live_list( $row, $signal_types ) {
        if ( empty( $signal_types ) ) {
            return [];
        }

        // Load the existing list, guaranteeing every signal bucket exists
        $defaults = [ 'ip' => [], 'hostname' => [], 'org' => [], 'ua' => [] ];
        $list     = wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), $defaults );

        $added = [];
        foreach ( $signal_types as $type ) {
            $column = self::SIGNAL_COLUMNS[ $type ];
            $value  = trim( (string) $row->$column );

            // An empty value would match everything at detection time — never store it
            if ( '' === $value || in_array( $value, $list[ $type ], true ) ) {
                continue;
            }

            $list[ $type ][] = $value;
            $added[]         = $type;
        }

        if ( ! empty( $added ) ) {
            update_option( self::OPTION_KEY, $list );
        }

        return $added;
    }
}
