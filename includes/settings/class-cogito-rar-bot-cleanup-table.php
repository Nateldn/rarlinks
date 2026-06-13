<?php
/**
 * Bot Cleanup list table: the clicks table filtered to bots and unknowns.
 *
 * Mirrors Cogito_RAR_Clicks_List_Table but is hard-filtered to
 * bot_or_not IN (1, 2) — human rows (0) can never appear here, and the
 * filter reads the logged flag rather than re-running any pattern matching
 * (detection at log time is canon). Adds the Delete bulk action consumed
 * by Cogito_RAR_Bot_Cleanup_Actions.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Parent class is conditionally defined, so guard the subclass the same way.
if ( class_exists( 'Cogito_RAR_Clicks_List_Table' ) ) :

class Cogito_RAR_Bot_Cleanup_Table extends Cogito_RAR_Clicks_List_Table {

    public function __construct() {
        // Bots (1) and unknowns (2) only — never humans (0)
        parent::__construct( [ 'bot_or_not IN (1, 2)' ] );
    }

    /**
     * Delete, plus Mark as human for rescuing false positives spotted
     * during review (the row leaves this table and reverts to human in
     * the report). WP_List_Table renders the dropdown and the per-row
     * checkboxes (column_cb in the parent) automatically.
     */
    public function get_bulk_actions() {
        return [
            'delete'       => 'Delete',
            'mark_human'   => 'Mark as human (not a bot)',
            'mark_unknown' => 'Mark as unknown',
            'flag_bot'     => 'Flag as bot',
        ];
    }

    /**
     * Keep this screen's page-size separate from the View Clicks table.
     */
    protected function get_per_page_option() {
        return 'rar_bot_cleanup_per_page';
    }

    /**
     * Per-row actions: Mark as human (rescue a false positive) and Delete.
     * Both are nonce-protected GET links handled by
     * Cogito_RAR_Bot_Cleanup_Actions::maybe_handle_row_action(). No
     * flag-as-bot panel here — every row is already a bot/unknown.
     *
     * @param object $item The current click row.
     * @return string Row-actions HTML.
     */
    protected function render_row_actions( $item ) {
        $id   = absint( $item->id );
        $base = add_query_arg( [
            'post_type' => 'rar_redirect',
            'page'      => 'rar_settings',
            'tab'       => 'reports',
        ], admin_url( 'edit.php' ) );

        // One nonce action per row id covers both the no-JS links and the AJAX path
        $nonce       = wp_create_nonce( 'rar_row_action_' . $id );
        $human_url   = wp_nonce_url( add_query_arg( [ 'rar_row_action' => 'mark_human', 'click_id' => $id ], $base ), 'rar_row_action_' . $id );
        $bot_url     = wp_nonce_url( add_query_arg( [ 'rar_row_action' => 'flag_bot', 'click_id' => $id ], $base ), 'rar_row_action_' . $id );
        $unknown_url = wp_nonce_url( add_query_arg( [ 'rar_row_action' => 'mark_unknown', 'click_id' => $id ], $base ), 'rar_row_action_' . $id );
        $delete_url  = wp_nonce_url( add_query_arg( [ 'rar_row_action' => 'delete', 'click_id' => $id ], $base ), 'rar_row_action_' . $id );

        // The up/down actions are mutually exclusive by state: a Bot row offers
        // "Mark as unknown" (downgrade); an Unknown row offers "Flag as bot"
        // (upgrade). Both are rendered; CSS shows only the one matching
        // data-state, and JS flips data-state after an in-place change.
        $state = ( (int) $item->bot_or_not === 1 ) ? 'bot' : 'unknown';

        // href is the no-JS fallback; data-* attributes drive the AJAX upgrade
        $html  = '<div class="row-actions" data-state="' . esc_attr( $state ) . '">';
        $html .= '<span class="rar-rescue"><a href="' . esc_url( $human_url ) . '" class="rar-row-human" data-click-id="' . $id . '" data-action="mark_human" data-nonce="' . esc_attr( $nonce ) . '">Mark as human</a> | </span>';
        $html .= '<span class="rar-state-bot"><a href="' . esc_url( $unknown_url ) . '" class="rar-row-unknown" data-click-id="' . $id . '" data-action="mark_unknown" data-nonce="' . esc_attr( $nonce ) . '">Mark as unknown</a> | </span>';
        $html .= '<span class="rar-state-unknown"><a href="' . esc_url( $bot_url ) . '" class="rar-row-bot" data-click-id="' . $id . '" data-action="flag_bot" data-nonce="' . esc_attr( $nonce ) . '">Flag as bot</a> | </span>';
        $html .= '<span class="trash"><a href="' . esc_url( $delete_url ) . '" class="rar-row-delete" data-click-id="' . $id . '" data-action="delete" data-nonce="' . esc_attr( $nonce ) . '">Delete</a></span>';
        $html .= '</div>';

        return $html;
    }
}

endif; // End class_exists( 'Cogito_RAR_Clicks_List_Table' ) check.
