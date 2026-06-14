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

            
    
    	// 🏗️ Map the grouped results by date so gaps can be filled
        $counts = [];
        foreach ( $results as $row ) {
            $counts[ $row->click_date ] = (int) $row->count;
        }

        $labels = [];
        $data   = [];

        // Fill every day from the first to the last click with its count (0 on
        // days with none). Without this the chart only plots days that HAVE
        // clicks and joins them with straight lines — making a gap read as a
        // continuous slope and hiding zero days/spikes.
        if ( ! empty( $counts ) ) {
            $dates  = array_keys( $counts );
            $cursor = new DateTime( min( $dates ) );
            $last   = new DateTime( max( $dates ) );

            while ( $cursor <= $last ) {
                $key      = $cursor->format( 'Y-m-d' );
                $labels[] = $cursor->format( 'D, d M' ); // e.g. "Mon, 20 May"
                $data[]   = $counts[ $key ] ?? 0;
                $cursor->modify( '+1 day' );
            }
        }

    	// 📦 Return chart data
    	return [
    		'labels' => $labels,
    		'data'   => $data
    	];
    }

}
