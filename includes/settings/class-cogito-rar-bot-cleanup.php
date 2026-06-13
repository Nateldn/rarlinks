<?php
/**
 * Renders the Bot Cleanup section on the Settings → Reports tab.
 *
 * Shows bot/unknown click rows for review with bulk delete — the
 * verify-before-delete replacement for manual SQL DELETEs. Rendering
 * only: the delete itself is handled in Cogito_RAR_Bot_Cleanup_Actions.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Bot_Cleanup {

    /**
     * Renders the section: heading, result notice, and the review table
     * inside a POST form carrying the bulk delete.
     */
    public static function render() {
        echo '<div class="rar-bot-cleanup">';
        echo '<h3>Bot Cleanup</h3>';
        echo '<p>Bot and unknown clicks only (as classified at log time) — human rows never appear here. ';
        echo 'Review, tick, and delete to keep the report clean. <strong>Deletion is permanent.</strong></p>';

        // Result notice after a bulk action (redirect appends ?deleted=N / ?rescued=N)
        if ( isset( $_GET['deleted'] ) ) {
            $deleted = absint( $_GET['deleted'] );
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html( sprintf( '%d row%s deleted.', $deleted, $deleted === 1 ? '' : 's' ) );
            echo '</p></div>';
        } elseif ( isset( $_GET['rescued'] ) ) {
            $rescued = absint( $_GET['rescued'] );
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html( sprintf( '%d row%s marked as human.', $rescued, $rescued === 1 ? '' : 's' ) );
            echo '</p></div>';
        } elseif ( isset( $_GET['flagged_unknown'] ) ) {
            $unknown = absint( $_GET['flagged_unknown'] );
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html( sprintf( '%d row%s marked as unknown.', $unknown, $unknown === 1 ? '' : 's' ) );
            echo '</p></div>';
        } elseif ( isset( $_GET['flagged_bot'] ) ) {
            $bots = absint( $_GET['flagged_bot'] );
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html( sprintf( '%d row%s flagged as bot.', $bots, $bots === 1 ? '' : 's' ) );
            echo '</p></div>';
        }

        $table = new Cogito_RAR_Bot_Cleanup_Table();
        $table->prepare_items();
        $total = (int) $table->get_pagination_arg( 'total_items' );

        // POST form: deletion is destructive, so it must never travel by GET.
        // Action URL pins the form back to this tab.
        $form_url = add_query_arg( [
            'post_type' => 'rar_redirect',
            'page'      => 'rar_settings',
            'tab'       => 'reports',
        ], admin_url( 'edit.php' ) );

        echo '<div class="rarlinks-table-wrapper">';
        echo '<form method="post" action="' . esc_url( $form_url ) . '" class="rar-bot-cleanup-form">';
        wp_nonce_field( 'rar_bot_cleanup_bulk', 'rar_bot_cleanup_nonce' );

        // "Select all across all pages" banner (Redirection-plugin style).
        // Hidden until the whole page is ticked; JS toggles it and sets the flag.
        echo '<div class="rar-select-all-banner" data-total="' . esc_attr( $total ) . '" hidden>';
        echo '<span class="rar-select-all-msg"></span>';
        echo '<a href="#" class="rar-select-all-link">Select all ' . esc_html( number_format( $total ) ) . ' across all pages</a>';
        echo '<input type="hidden" name="rar_select_all_pages" value="0" class="rar-select-all-flag" />';
        echo '</div>';

        $table->display();
        echo '</form>';
        echo '</div>';

        echo '</div>'; // .rar-bot-cleanup
    }
}
