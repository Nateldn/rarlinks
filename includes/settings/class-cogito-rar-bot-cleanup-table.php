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
            'delete'     => 'Delete',
            'mark_human' => 'Mark as human (not a bot)',
        ];
    }

    /**
     * No flag-as-bot row action here — every row is already a bot/unknown,
     * and the flag-as-bot script isn't enqueued on the settings page.
     */
    protected function render_flag_bot_action( $item ) {
        return '';
    }
}

endif; // End class_exists( 'Cogito_RAR_Clicks_List_Table' ) check.
