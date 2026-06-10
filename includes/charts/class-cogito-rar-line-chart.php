<?php
/**
 * Renders the Line Chart for RARLinks Click Data
 */

if ( ! defined( 'ABSPATH' ) ) exit;

    class Cogito_RAR_Line_Chart {
    	public static function get_data( array $filters = [] ) {
    	global $wpdb;
    
    	$table = "{$wpdb->prefix}rarlinks_clicks";
    
    	// 🧱 Build WHERE clause using passed filters (same structure as the main dashboard)
    	$where = '';
    	if ( ! empty( $filters ) ) {
    		$where = 'WHERE ' . implode( ' AND ', $filters );
    	}
    
    	// 📊 Query: count clicks per day
    	$results = $wpdb->get_results( "
    SELECT DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) AS click_date, COUNT(*) AS count
    FROM $table
    $where
    GROUP BY click_date
    ORDER BY click_date ASC
" );

            
    
    	// 🏗️ Prepare chart arrays
        $labels = [];
        $data   = [];
    

        foreach ( $results as $row ) {
            $labels[] = date( 'D, d M', strtotime( $row->click_date ) );// Format as "Mon, 20 May"
            $data[]   = (int) $row->count;
        }

        
            
    
    	// 📦 Return chart data
    	return [
    		'labels' => $labels,
    		'data'   => $data
    	];
    }

}
