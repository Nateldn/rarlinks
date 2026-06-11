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

        if ( $action !== 'delete' ) {
            return; // No bulk action chosen — nothing to do
        }

        // 🔢 Collect and validate the selected row IDs
        $ids = isset( $_POST['bulk-select'] ) && is_array( $_POST['bulk-select'] )
            ? array_filter( array_map( 'absint', $_POST['bulk-select'] ) )
            : [];

        $deleted = 0;

        if ( ! empty( $ids ) ) {
            global $wpdb;
            $table        = $wpdb->prefix . 'rarlinks_clicks';
            $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

            // Belt and braces: even with a tampered ID list, human rows
            // (bot_or_not = 0) can never be deleted from here.
            $deleted = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM $table WHERE id IN ($placeholders) AND bot_or_not IN (1, 2)",
                $ids
            ) );
        }

        // 🔁 Redirect back to the Reports tab (PRG: a refresh can't re-delete)
        wp_safe_redirect( add_query_arg( [
            'post_type' => 'rar_redirect',
            'page'      => 'rar_settings',
            'tab'       => 'reports',
            'deleted'   => $deleted,
        ], admin_url( 'edit.php' ) ) );
        exit;
    }
}
