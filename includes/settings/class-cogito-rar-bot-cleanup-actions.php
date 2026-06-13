<?php
/**
 * Handles the Bot Cleanup bulk delete POST.
 *
 * Server logic only — the review table and form live in
 * Cogito_RAR_Bot_Cleanup / Cogito_RAR_Bot_Cleanup_Table.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Bot_Cleanup_Actions {

    /**
     * Registers the handler. admin_init runs before any page output, so the
     * post-delete redirect can still send headers.
     */
    public static function init() {
        add_action( 'admin_init', [ self::class, 'maybe_handle_delete' ] );
        add_action( 'admin_init', [ self::class, 'maybe_handle_row_action' ] );
        add_action( 'wp_ajax_rar_bot_cleanup_row', [ self::class, 'ajax_row_action' ] );
    }

    /**
     * AJAX twin of maybe_handle_row_action(): Mark as human / Delete a single
     * row without a page reload. Same guards (per-row nonce, capability, query
     * constrained to bot/unknown). Returns the remaining bot/unknown count so
     * the JS can resync the select-all banner.
     */
    public static function ajax_row_action() {
        $id     = isset( $_POST['click_id'] ) ? absint( $_POST['click_id'] ) : 0;
        $action = isset( $_POST['row_action'] ) ? sanitize_key( $_POST['row_action'] ) : '';

        if ( ! $id || ! in_array( $action, [ 'delete', 'mark_human' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ], 400 );
        }

        // 🔒 Same per-row nonce as the no-JS links, plus capability
        if ( ! check_ajax_referer( 'rar_row_action_' . $id, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rarlinks_clicks';

        // Constrained to bot/unknown: a human row can never be hit here
        if ( $action === 'delete' ) {
            $affected = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id = %d AND bot_or_not IN (1, 2)", $id ) );
        } else {
            $affected = (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET bot_or_not = 0, bot_name = '' WHERE id = %d AND bot_or_not IN (1, 2)", $id ) );
        }

        if ( $affected < 1 ) {
            wp_send_json_error( [ 'message' => 'Row not found or already changed.' ], 404 );
        }

        // Remaining bot/unknown rows, for the select-all banner total
        $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE bot_or_not IN (1, 2)" );

        wp_send_json_success( [
            'click_id'  => $id,
            'action'    => $action,
            'remaining' => $remaining,
        ] );
    }

    /**
     * Processes the bulk delete if this request is a Bot Cleanup submission.
     * Verifies nonce and capability before touching the database, deletes
     * only rows still classified bot/unknown, then redirects (PRG pattern)
     * back to the Reports tab with the deleted count.
     */
    public static function maybe_handle_delete() {
        // Cheap bail-out: not our form
        if ( ! isset( $_POST['rar_bot_cleanup_nonce'] ) ) {
            return;
        }

        // 🔒 Verify nonce
        if ( ! wp_verify_nonce( sanitize_key( $_POST['rar_bot_cleanup_nonce'] ), 'rar_bot_cleanup_bulk' ) ) {
            wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
        }

        // 🔒 Verify capability
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
        }

        // The bulk action arrives as 'action' (top dropdown) or 'action2' (bottom)
        $action = '';
        foreach ( [ 'action', 'action2' ] as $key ) {
            $candidate = isset( $_POST[ $key ] ) ? sanitize_key( $_POST[ $key ] ) : '';
            if ( $candidate && $candidate !== '-1' ) {
                $action = $candidate;
                break;
            }
        }

        if ( ! in_array( $action, [ 'delete', 'mark_human' ], true ) ) {
            return; // No bulk action chosen — nothing to do
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rarlinks_clicks';

        // "Select all across all pages" — apply to every bot/unknown row,
        // ignoring the per-page id list (the Redirection-style banner).
        $select_all = isset( $_POST['rar_select_all_pages'] ) && '1' === $_POST['rar_select_all_pages'];

        // 🔢 Collect and validate the selected row IDs (per-page selection)
        $ids = isset( $_POST['bulk-select'] ) && is_array( $_POST['bulk-select'] )
            ? array_filter( array_map( 'absint', $_POST['bulk-select'] ) )
            : [];

        $affected = 0;

        // Every query is constrained to bot_or_not IN (1,2), so human rows
        // can never be touched here — even with a tampered request.
        if ( $select_all ) {
            if ( $action === 'delete' ) {
                $affected = (int) $wpdb->query( "DELETE FROM $table WHERE bot_or_not IN (1, 2)" );
            } else {
                $affected = (int) $wpdb->query( "UPDATE $table SET bot_or_not = 0, bot_name = '' WHERE bot_or_not IN (1, 2)" );
            }
        } elseif ( ! empty( $ids ) ) {
            $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

            if ( $action === 'delete' ) {
                $affected = (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM $table WHERE id IN ($placeholders) AND bot_or_not IN (1, 2)",
                    $ids
                ) );
            } else {
                $affected = (int) $wpdb->query( $wpdb->prepare(
                    "UPDATE $table SET bot_or_not = 0, bot_name = '' WHERE id IN ($placeholders) AND bot_or_not IN (1, 2)",
                    $ids
                ) );
            }
        }

        // 🔁 Redirect back to the Reports tab (PRG: a refresh can't re-run)
        $result_param = ( $action === 'delete' ) ? 'deleted' : 'rescued';

        wp_safe_redirect( add_query_arg( [
            'post_type'    => 'rar_redirect',
            'page'         => 'rar_settings',
            'tab'          => 'reports',
            $result_param  => $affected,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Handles a single-row action (Mark as human / Delete) from the per-row
     * links in the Bot Cleanup table. Nonce-protected GET links, WP-core style.
     */
    public static function maybe_handle_row_action() {
        // Cheap bail-out: not one of our row links
        if ( ! isset( $_GET['rar_row_action'] ) ) {
            return;
        }

        $action = sanitize_key( $_GET['rar_row_action'] );
        if ( ! in_array( $action, [ 'delete', 'mark_human' ], true ) ) {
            return;
        }

        $id = isset( $_GET['click_id'] ) ? absint( $_GET['click_id'] ) : 0;
        if ( ! $id ) {
            return;
        }

        // 🔒 Per-row nonce + capability
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'rar_row_action_' . $id ) ) {
            wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rarlinks_clicks';

        // Constrained to bot/unknown: a human row can never be hit here
        if ( $action === 'delete' ) {
            $affected     = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id = %d AND bot_or_not IN (1, 2)", $id ) );
            $result_param = 'deleted';
        } else {
            $affected     = (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET bot_or_not = 0, bot_name = '' WHERE id = %d AND bot_or_not IN (1, 2)", $id ) );
            $result_param = 'rescued';
        }

        // 🔁 PRG redirect back to the Reports tab
        wp_safe_redirect( add_query_arg( [
            'post_type'   => 'rar_redirect',
            'page'        => 'rar_settings',
            'tab'         => 'reports',
            $result_param => $affected,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }
}
