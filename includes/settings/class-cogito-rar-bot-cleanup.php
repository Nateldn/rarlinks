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

        // Earliest bot/unknown date, for the date-picker min attribute
        global $wpdb;
        $clicks_table = $wpdb->prefix . 'rarlinks_clicks';
        $min_date     = $wpdb->get_var(
            "SELECT MIN(DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London'))) FROM $clicks_table WHERE bot_or_not IN (1, 2)"
        );

        // Filter form (type + date) and the resulting WHERE clauses
        Cogito_RAR_Bot_Cleanup_Filters::render( $min_date );
        $filters = Cogito_RAR_Bot_Cleanup_Filters::get_filters();

        // Headline counts for the selected timeframe. Both bots and unknown are
        // shown regardless of the type filter, so the breakdown is always
        // visible at a glance. Date clauses are built from sanitised params
        // (range fixed, dates regex-validated), so interpolation here is safe.
        $params       = Cogito_RAR_Bot_Cleanup_Filters::get_params();
        $date_clauses = Cogito_RAR_Dashboard_Filters::date_clauses( $params['range'], $params['from'], $params['to'] );
        $date_where   = $date_clauses ? implode( ' AND ', $date_clauses ) . ' AND ' : '';

        $bots_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $clicks_table WHERE {$date_where}bot_or_not = 1" );
        $unknown_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $clicks_table WHERE {$date_where}bot_or_not = 2" );

        echo '<h2 class="rar-bot-cleanup-count">';
        echo esc_html( sprintf(
            'Bot & Unknown Clicks (%s): %s',
            self::timeframe_label( $params ),
            number_format( $bots_count + $unknown_count )
        ) );
        echo ' <span class="rar-bot-cleanup-breakdown">';
        echo esc_html( sprintf( 'Bots: %s · Unknown: %s', number_format( $bots_count ), number_format( $unknown_count ) ) );
        echo '</span></h2>';

        // Spike-spotting line chart (fed by Settings_Page::enqueue_assets with
        // the same filters). Canvas only — Chart.js renders it via rar-charts.js.
        echo '<h4>Bot &amp; Unknown Clicks Over Time</h4>';
        echo '<div class="rar-bot-cleanup-chart" style="max-width:100%; margin-bottom:20px;">';
        echo '<canvas id="rarLineChart" height="80"></canvas>';
        echo '</div>';

        $table = new Cogito_RAR_Bot_Cleanup_Table( $filters );
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

    /**
     * Human-readable label for the active timeframe, for the count heading.
     *
     * @param array $params Bot Cleanup filter params (range/from/to).
     * @return string
     */
    private static function timeframe_label( $params ) {
        $fmt = function ( $d ) {
            $obj = DateTime::createFromFormat( 'Y-m-d', $d );
            return $obj ? $obj->format( 'd M Y' ) : $d;
        };

        if ( ! empty( $params['from'] ) && ! empty( $params['to'] ) ) {
            return $fmt( $params['from'] ) . ' to ' . $fmt( $params['to'] );
        }
        if ( ! empty( $params['from'] ) ) {
            return 'since ' . $fmt( $params['from'] );
        }
        if ( ! empty( $params['to'] ) ) {
            return 'up to ' . $fmt( $params['to'] );
        }

        $labels = [
            'today'     => 'today',
            'yesterday' => 'yesterday',
            '7days'     => 'last 7 days',
            '14days'    => 'last 14 days',
            '30days'    => 'last 30 days',
            'thismonth' => 'this month',
            'lastmonth' => 'last month',
        ];

        return $labels[ $params['range'] ] ?? 'all time';
    }
}
