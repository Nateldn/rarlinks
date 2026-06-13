<?php
/**
 * Handles the Bot Cleanup row + bulk actions (Mark as human / Mark as
 * unknown / Delete).
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

    /** Actions this handler accepts, from any of its three entry points. */
    const ACTIONS = [ 'delete', 'mark_human', 'mark_unknown', 'flag_bot' ];

    /**
     * Registers the handlers. admin_init runs before any page output, so the
     * post-action redirects can still send headers.
     */
    public static function init() {
        add_action( 'admin_init', [ self::class, 'maybe_handle_delete' ] );
        add_action( 'admin_init', [ self::class, 'maybe_handle_row_action' ] );
        add_action( 'wp_ajax_rar_bot_cleanup_row', [ self::class, 'ajax_row_action' ] );
    }

    /**
     * Runs an action against rows matching a WHERE clause.
     *
     * Every query is constrained to bot_or_not IN (1,2) by the caller's WHERE,
     * so human rows can never be touched — even via a tampered request.
     *
     * @param string $action     One of self::ACTIONS.
     * @param string $where      SQL WHERE body (already includes the bot/unknown guard).
     * @param array  $where_args Values for any %d placeholders in $where (empty = none).
     * @return int Rows affected.
     */
    private static function apply_action( $action, $where, $where_args = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'rarlinks_clicks';

        if ( $action === 'delete' ) {
            $sql = "DELETE FROM $table WHERE $where";
        } elseif ( $action === 'mark_unknown' ) {
            $sql = "UPDATE $table SET bot_or_not = 2, bot_name = '' WHERE $where";
        } elseif ( $action === 'flag_bot' ) {
            $sql = "UPDATE $table SET bot_or_not = 1, bot_name = 'Manually flagged' WHERE $where";
        } else { // mark_human
            $sql = "UPDATE $table SET bot_or_not = 0, bot_name = '' WHERE $where";
        }

        if ( ! empty( $where_args ) ) {
            return (int) $wpdb->query( $wpdb->prepare( $sql, $where_args ) );
        }
        return (int) $wpdb->query( $sql );
    }

    /**
     * The redirect query-arg used to report each action's result count.
     */
    private static function result_param( $action ) {
        if ( $action === 'delete' ) {
            return 'deleted';
        }
        if ( $action === 'mark_unknown' ) {
            return 'flagged_unknown';
        }
        if ( $action === 'flag_bot' ) {
            return 'flagged_bot';
        }
        return 'rescued';
    }

    /**
     * AJAX twin of maybe_handle_row_action(): act on a single row without a
     * page reload. Same guards (per-row nonce, capability, bot/unknown only).
     * Returns the remaining bot/unknown count so the JS can resync the
     * select-all banner.
     */
    public static function ajax_row_action() {
        $id     = isset( $_POST['click_id'] ) ? absint( $_POST['click_id'] ) : 0;
        $action = isset( $_POST['row_action'] ) ? sanitize_key( $_POST['row_action'] ) : '';

        if ( ! $id || ! in_array( $action, self::ACTIONS, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid request.' ], 400 );
        }

        // 🔒 Same per-row nonce as the no-JS links, plus capability
        if ( ! check_ajax_referer( 'rar_row_action_' . $id, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        $affected = self::apply_action( $action, 'id = %d AND bot_or_not IN (1, 2)', [ $id ] );

        if ( $affected < 1 ) {
            wp_send_json_error( [ 'message' => 'Row not found or already changed.' ], 404 );
        }

        global $wpdb;
        $table     = $wpdb->prefix . 'rarlinks_clicks';
        $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE bot_or_not IN (1, 2)" );

        wp_send_json_success( [
            'click_id'  => $id,
            'action'    => $action,
            'remaining' => $remaining,
        ] );
    }

    /**
     * Processes a bulk submission. Verifies nonce and capability, applies the
     * chosen action to the selected rows (or every bot/unknown row when
     * "select all across pages" is set), then redirects (PRG) with the count.
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

        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            return; // No bulk action chosen — nothing to do
        }

        // "Select all across all pages" — apply to every bot/unknown row,
        // ignoring the per-page id list (the Redirection-style banner).
        $select_all = isset( $_POST['rar_select_all_pages'] ) && '1' === $_POST['rar_select_all_pages'];

        // 🔢 Collect and validate the selected row IDs (per-page selection)
        $ids = isset( $_POST['bulk-select'] ) && is_array( $_POST['bulk-select'] )
            ? array_filter( array_map( 'absint', $_POST['bulk-select'] ) )
            : [];

        $affected = 0;

        if ( $select_all ) {
            $affected = self::apply_action( $action, 'bot_or_not IN (1, 2)' );
        } elseif ( ! empty( $ids ) ) {
            $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
            $affected     = self::apply_action( $action, "id IN ($placeholders) AND bot_or_not IN (1, 2)", $ids );
        }

        // 🔁 Redirect back to the Reports tab (PRG: a refresh can't re-run)
        $result_param = self::result_param( $action );

        wp_safe_redirect( add_query_arg( [
            'post_type'    => 'rar_redirect',
            'page'         => 'rar_settings',
            'tab'          => 'reports',
            $result_param  => $affected,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }

    /**
     * Handles a single-row action from the per-row links (no-JS fallback for
     * ajax_row_action()). Nonce-protected GET links, WP-core style.
     */
    public static function maybe_handle_row_action() {
        // Cheap bail-out: not one of our row links
        if ( ! isset( $_GET['rar_row_action'] ) ) {
            return;
        }

        $action = sanitize_key( $_GET['rar_row_action'] );
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
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

        $affected     = self::apply_action( $action, 'id = %d AND bot_or_not IN (1, 2)', [ $id ] );
        $result_param = self::result_param( $action );

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
