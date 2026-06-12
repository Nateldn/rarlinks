<?php
/**
 * Handles bulk re-classification from the clicks report table.
 *
 * "Flag as bot" sets bot_or_not = 1 on every selected row; "Mark as human"
 * sets bot_or_not = 0 (rescuing false positives). Bulk flagging does NOT
 * touch the live bot list — choosing which of a row's signals to blacklist
 * is inherently a per-row decision, which stays with the row action panel.
 *
 * Server logic only — the bulk dropdown is rendered by the list table.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Bulk_Classify {

    /**
     * Registers the handler. admin_init runs before any page output, so the
     * post-update redirect can still send headers.
     */
    public static function init() {
        add_action( 'admin_init', [ self::class, 'maybe_handle' ] );
    }

    /**
     * Processes a bulk classification if this request is one.
     *
     * The dashboard list table submits by GET (the WP-core list table
     * pattern — filters and pagination ride the same form), so the nonce
     * is what authenticates the state change.
     */
    public static function maybe_handle() {
        // Cheap bail-out: not our form
        if ( ! isset( $_GET['rar_bulk_classify_nonce'] ) ) {
            return;
        }

        // Only act on the clicks dashboard page
        if ( ( $_GET['page'] ?? '' ) !== 'rar_dashboard' ) {
            return;
        }

        // The bulk action arrives as 'action' (top dropdown) or 'action2' (bottom)
        $action = '';
        foreach ( [ 'action', 'action2' ] as $key ) {
            $candidate = isset( $_GET[ $key ] ) ? sanitize_key( $_GET[ $key ] ) : '';
            if ( $candidate && $candidate !== '-1' ) {
                $action = $candidate;
                break;
            }
        }

        if ( ! in_array( $action, [ 'flag_bot', 'mark_human' ], true ) ) {
            return; // No bulk action chosen — an ordinary filter submission
        }

        // 🔒 Verify nonce
        if ( ! wp_verify_nonce( sanitize_key( $_GET['rar_bulk_classify_nonce'] ), 'rar_bulk_classify' ) ) {
            wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
        }

        // 🔒 Verify capability
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
        }

        // 🔢 Collect and validate the selected row IDs
        $ids = isset( $_GET['bulk-select'] ) && is_array( $_GET['bulk-select'] )
            ? array_filter( array_map( 'absint', $_GET['bulk-select'] ) )
            : [];

        $updated = 0;

        if ( ! empty( $ids ) ) {
            global $wpdb;
            $table        = $wpdb->prefix . 'rarlinks_clicks';
            $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

            if ( $action === 'flag_bot' ) {
                // Preserve an existing bot_name on rows detection already
                // named; everything else records the manual flag.
                $updated = (int) $wpdb->query( $wpdb->prepare(
                    "UPDATE $table
                     SET bot_name = IF( bot_or_not = 1 AND bot_name <> '', bot_name, 'Manually flagged' ),
                         bot_or_not = 1
                     WHERE id IN ($placeholders)",
                    $ids
                ) );
            } else {
                // mark_human: clear the classification and any bot name
                $updated = (int) $wpdb->query( $wpdb->prepare(
                    "UPDATE $table SET bot_or_not = 0, bot_name = '' WHERE id IN ($placeholders)",
                    $ids
                ) );
            }
        }

        // 🔁 Redirect back to the dashboard, preserving the active filters
        // but stripping the action params so a refresh can't re-apply.
        $redirect = remove_query_arg(
            [ 'action', 'action2', 'bulk-select', 'rar_bulk_classify_nonce', '_wp_http_referer' ]
        );
        $redirect = add_query_arg( [
            'classified'      => $updated,
            'classify_action' => $action,
        ], $redirect );

        wp_safe_redirect( $redirect );
        exit;
    }
}
