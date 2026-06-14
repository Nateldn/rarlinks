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
     * Registers the AJAX handler.
     * Must run on every admin load so the hook exists when the AJAX request arrives.
     */
    public static function init() {
        add_action( 'wp_ajax_rar_flag_bot', [ self::class, 'handle_flag' ] );
        add_action( 'wp_ajax_rar_mark_unknown', [ self::class, 'handle_mark_unknown' ] );
    }

    /**
     * AJAX handler: reclassifies a click as Unknown (bot_or_not = 2) from the
     * human-only dashboard, so it drops off the dashboard and into Bot Cleanup.
     */
    public static function handle_mark_unknown() {
        global $wpdb;

        if ( ! check_ajax_referer( 'rar_flag_bot_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        $click_id = isset( $_POST['click_id'] ) ? absint( $_POST['click_id'] ) : 0;
        if ( ! $click_id ) {
            wp_send_json_error( [ 'message' => 'Invalid click ID.' ], 400 );
        }

        $table   = $wpdb->prefix . 'rarlinks_clicks';
        $updated = $wpdb->update( $table, [ 'bot_or_not' => 2, 'bot_name' => '' ], [ 'id' => $click_id ] );

        if ( false === $updated ) {
            wp_send_json_error( [ 'message' => 'Database update failed.' ], 500 );
        }

        wp_send_json_success( [ 'click_id' => $click_id ] );
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

        // 📥 Sanitise the requested signal types; values are always read from
        // the DB row (never the request), and unknown types are discarded by
        // the live bot list class, so a tampered request can only ever
        // blacklist that row's own data.
        $requested = isset( $_POST['signals'] ) && is_array( $_POST['signals'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['signals'] ) )
            : [];

        // 📝 Append each chosen signal's value to the live bot list
        $added = Cogito_RAR_Live_Bot_List::add_signals( $row, $requested );

        wp_send_json_success( [
            'click_id' => $click_id,
            'added'    => $added, // Signal types actually appended (deduped, empties skipped)
        ] );
    }
}
