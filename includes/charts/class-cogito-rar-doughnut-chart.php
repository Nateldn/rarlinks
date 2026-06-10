<?php
/**
 * Renders the Doughnut Chart for RARLinks Click Distribution
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Cogito_RAR_Doughnut_Chart {
    public static function get_data( array $filters = [] ) {
        global $wpdb;

        $table = "{$wpdb->prefix}rarlinks_clicks";
        $where = '';

        // ⏰ Adjust any date-based filter to use Europe/London timezone
        foreach ( $filters as &$filter ) {
            $filter = preg_replace_callback( '/\btimestamp\b/', function ( $m ) {
                return "CONVERT_TZ(timestamp, '+00:00', 'Europe/London')";
            }, $filter );
        }
        unset($filter); // Clean up reference

        if ( ! empty( $filters ) ) {
            $where = 'WHERE ' . implode( ' AND ', $filters );
        }

        // ✅ Query top clicked post_ids (not titles)
        $results = $wpdb->get_results( "
            SELECT post_id, COUNT(*) as clicks
            FROM $table
            $where
            GROUP BY post_id
            ORDER BY clicks DESC
            LIMIT 10
        " );

        $labels = [];
        $data   = [];

        foreach ( $results as $row ) {
            // 🧼 Clean and decode title for proper display (no HTML entities or tags)
            $title_raw = get_the_title( $row->post_id );
            $title_clean = wp_strip_all_tags( html_entity_decode( $title_raw ) );
            $labels[] = $title_clean ?: 'Untitled';
            $data[]   = (int) $row->clicks;
        }

        return [
            'labels' => $labels,
            'data'   => $data
        ];
    }
}

