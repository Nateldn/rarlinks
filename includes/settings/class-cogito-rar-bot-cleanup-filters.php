<?php
/**
 * Filter logic + UI for the Bot Cleanup report: a type filter (all bots and
 * unknowns / bots only / unknowns only) plus the same date controls as the
 * dashboard. Reads its own bc_* query params so it never collides with the
 * dashboard's filters.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Bot_Cleanup_Filters {

    /**
     * Sanitised filter params from the request.
     *
     * @return array
     */
    public static function get_params() {
        $type = isset( $_GET['bc_type'] ) ? sanitize_key( $_GET['bc_type'] ) : 'all';
        if ( ! in_array( $type, [ 'all', 'bots', 'unknown' ], true ) ) {
            $type = 'all';
        }

        return [
            'range' => sanitize_text_field( $_GET['bc_range'] ?? '' ),
            'from'  => Cogito_RAR_Dashboard_Filters::sanitize_date( $_GET['bc_from'] ?? '' ),
            'to'    => Cogito_RAR_Dashboard_Filters::sanitize_date( $_GET['bc_to'] ?? '' ),
            'type'  => $type,
        ];
    }

    /**
     * SQL WHERE clauses: date (no default — all time) plus the bot/unknown
     * type constraint. Human rows can never appear.
     *
     * @return array
     */
    public static function get_filters() {
        $params  = self::get_params();
        $filters = Cogito_RAR_Dashboard_Filters::date_clauses( $params['range'], $params['from'], $params['to'] );

        if ( $params['type'] === 'bots' ) {
            $filters[] = 'bot_or_not = 1';
        } elseif ( $params['type'] === 'unknown' ) {
            $filters[] = 'bot_or_not = 2';
        } else {
            $filters[] = 'bot_or_not IN (1, 2)';
        }

        return $filters;
    }

    /**
     * Renders the filter form (GET) above the Bot Cleanup table.
     *
     * @param string $min_date Earliest click date, for the date picker min.
     */
    public static function render( $min_date = '' ) {
        $params      = self::get_params();
        $today       = date( 'Y-m-d' );
        $show_custom = ( ! empty( $params['from'] ) || ! empty( $params['to'] ) );

        echo '<form method="get" class="rar-bot-cleanup-filter-form">';
        echo '<input type="hidden" name="post_type" value="rar_redirect">';
        echo '<input type="hidden" name="page" value="rar_settings">';
        echo '<input type="hidden" name="tab" value="reports">';

        echo '<div style="display:flex; flex-wrap:wrap; gap:15px; align-items:center; margin:10px 0;">';

        // Type filter
        echo '<div><label style="margin-right:8px;">Show:</label>';
        foreach ( [ 'all' => 'Bots + Unknown', 'bots' => 'Bots only', 'unknown' => 'Unknown only' ] as $val => $label ) {
            echo '<label style="margin-right:8px;"><input type="radio" name="bc_type" value="' . esc_attr( $val ) . '" ' . checked( $params['type'], $val, false ) . '> ' . esc_html( $label ) . '</label>';
        }
        echo '</div>';

        // Quick range
        echo '<div><label for="bc_range">Quick Range: </label>';
        echo '<select name="bc_range" id="bc_range">';
        $ranges = [
            ''          => '-- All Time --',
            'today'     => 'Today',
            'yesterday' => 'Yesterday',
            '7days'     => 'Last 7 Days',
            '14days'    => 'Last 14 Days',
            '30days'    => 'Last 30 Days',
            'thismonth' => 'This Month',
            'lastmonth' => 'Last Month',
        ];
        foreach ( $ranges as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '" ' . selected( $params['range'], $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div>';

        // Custom range toggle + inputs
        echo '<div class="rartoggle">';
        echo '<input type="checkbox" id="bcCustomRangeToggle" ' . checked( $show_custom, true, false ) . '>';
        echo '<label for="bcCustomRangeToggle"></label><span>Custom Range</span>';
        echo '</div>';

        echo '<div id="bcCustomRangeFields" style="display:' . ( $show_custom ? 'inline-block' : 'none' ) . ';">';
        echo 'From <input type="date" name="bc_from" id="bc_from" min="' . esc_attr( $min_date ) . '" max="' . esc_attr( $today ) . '" value="' . esc_attr( $params['from'] ) . '"> ';
        echo 'To <input type="date" name="bc_to" id="bc_to" min="' . esc_attr( $min_date ) . '" max="' . esc_attr( $today ) . '" value="' . esc_attr( $params['to'] ) . '"> ';
        echo '</div>';

        echo '</div>';

        echo '<input type="submit" class="button" value="Apply Filters">';
        echo '</form>';
    }
}
