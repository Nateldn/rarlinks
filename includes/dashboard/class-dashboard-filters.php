<?php
/**
 * Handles all filtering logic and UI for the RARLinks Dashboard.
 *
 * This class consolidates input reading, SQL generation, and form rendering
 * to ensure consistent behavior across the dashboard.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cogito_RAR_Dashboard_Filters {

	/**
	 * Retrieval and Sanitization of URL Parameters.
	 * @return array Sanitized parameters.
	 */
	public static function get_params() {
		return [
			'range'        => sanitize_text_field( $_GET['range'] ?? '' ),
			'from'         => self::sanitize_date( $_GET['from'] ?? '' ),
			'to'           => self::sanitize_date( $_GET['to'] ?? '' ),
			'traffic_type' => sanitize_text_field( $_GET['traffic_type'] ?? 'all' ),
			'post_id'      => isset( $_GET['post_id'] ) && is_numeric( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : null,
		];
	}

	/**
	 * Strictly validate a date string as YYYY-MM-DD.
	 * Returns '' for anything that doesn't match, which prevents these
	 * values from being used to break out of the SQL string literals
	 * they are later interpolated into.
	 */
	private static function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Builds the SQL filter clauses based on user selections.
	 *
	 * @return array An array of SQL filter clauses.
	 */
	public static function get_filters() {
		$filters = [];
		$params  = self::get_params();

		$range        = $params['range'];
		$from         = $params['from'];
		$to           = $params['to'];
		$traffic_type = $params['traffic_type'];
		$post_id      = $params['post_id'];
		$today        = date( 'Y-m-d' );

		// 1. Date Range Logic — default to last 30 days if no filter is set
if ( empty( $range ) && empty( $from ) && empty( $to ) ) {
    $range = '30days';
}

if ( ! empty( $range ) && $range !== 'all' ) {
			switch ( $range ) {
				case 'today':
					$filters[] = "DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) = CURDATE()";
					break;
				case 'yesterday':
					$filters[] = "DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) = CURDATE() - INTERVAL 1 DAY";
					break;
				case '7days':
					$filters[] = "DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) >= CURDATE() - INTERVAL 6 DAY";
					break;
				case '14days':
					$filters[] = "DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) >= CURDATE() - INTERVAL 13 DAY";
					break;
				case '30days':
					$filters[] = "DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) >= CURDATE() - INTERVAL 29 DAY";
					break;
				case 'thismonth':
					$filters[] = "MONTH(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) = MONTH(CURRENT_DATE()) AND YEAR(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) = YEAR(CURRENT_DATE())";
					break;
				case 'lastmonth':
					$filters[] = "MONTH(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH) AND YEAR(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH)";
					break;
			}
		}

		// 2. Custom Date Logic (overrides/augments range if set)
		if ( $from && $to ) {
			$filters[] = "DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) BETWEEN '$from' AND '$to'";
		} elseif ( $from && ! $to ) {
			$filters[] = "DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) BETWEEN '$from' AND CURDATE()";
		} elseif ( ! $from && $to ) {
			$filters[] = "DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')) = '$to'";
		}

		// 3. Post ID Logic
		if ( ! is_null( $post_id ) ) {
			$filters[] = 'post_id = ' . $post_id;
		}

		// 4. Traffic Type Logic
		if ( $traffic_type === 'human' ) {
			$filters[] = 'bot_or_not = 0';
		} elseif ( $traffic_type === 'known_bots' ) {
			$filters[] = 'bot_or_not = 1';
		} elseif ( $traffic_type === 'unknown' ) {
			$filters[] = 'bot_or_not = 2';
		}

		return $filters;
	}

	/**
	 * Renders the complete filter form.
	 *
	 * @param string $min_date Earliest available date for date picker min attribute.
	 */
	public static function render( $min_date = '' ) {
		$params = self::get_params();
		$today  = date( 'Y-m-d' );

		$from_value = $params['from'];
		$to_value   = $params['to'];

		echo '<h2>' . esc_html__( 'Filter Clicks', 'rarlinks' ) . '</h2>';
		echo '<form method="get" class="rar-dashboard-filter-form">';
		echo '<input type="hidden" name="post_type" value="rar_redirect">';
		echo '<input type="hidden" name="page" value="rar_dashboard">';

		echo '<div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center; margin-bottom: 15px;">';

		// --- Quick Range Select ---
		echo '<div><label for="range">Quick Range: </label>';
		echo '<select name="range" id="range" onchange="document.getElementById(`from`).value=``; document.getElementById(`to`).value=``;">';

		$options = [
			''          => '-- Select Range --',
			'today'     => 'Today',
			'yesterday' => 'Yesterday',
			'7days'     => 'Last 7 Days',
			'14days'    => 'Last 14 Days',
			'30days'    => 'Last 30 Days',
			'thismonth' => 'This Month',
			'lastmonth' => 'Last Month',
			'all'       => 'All Time',
		];

		// Default to 30days in the dropdown if no range is selected
$display_range = empty( $params['range'] ) ? '30days' : $params['range'];
foreach ( $options as $val => $label ) {
    $selected = selected( $display_range, $val, false );
    echo "<option value='" . esc_attr( $val ) . "' $selected>" . esc_html( $label ) . "</option>";
}
		echo '</select></div>';

		// --- RARLink Search Input ---
		// Pre-populates a datalist with all RARLink titles and IDs.
		// JS matches the typed value to an option and populates the hidden post_id field on selection.
		$rar_links = get_posts( [
			'post_type'      => 'rar_redirect',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		echo '<datalist id="rar-link-list">';
		foreach ( $rar_links as $link ) {
			echo '<option value="' . esc_attr( $link->post_title ) . '" data-id="' . esc_attr( $link->ID ) . '">';
		}
		echo '</datalist>';

		// Hidden post_id field — populated by JS when a RARLink is selected, submitted with Apply Filters
		echo '<input type="hidden" name="post_id" id="rar-post-id-input" value="' . esc_attr( $params['post_id'] ?? '' ) . '">';

		echo '<div>';
		echo '<label for="rar-link-search">Search RARLink: </label>';
		// Pre-populate search box with current link title if filtering by post_id
$current_link_title = '';
if ( ! is_null( $params['post_id'] ) ) {
    $current_link = get_post( $params['post_id'] );
    if ( $current_link ) {
        $current_link_title = $current_link->post_title;
    }
}
echo '<input type="text" id="rar-link-search" list="rar-link-list" placeholder="Type to search..." autocomplete="off" value="' . esc_attr( $current_link_title ) . '">';
		echo '</div>';

		// --- Traffic Type Radio Buttons ---
		echo '<div><label style="margin-right: 10px;">Traffic Type:</label>';
		echo '<label><input type="radio" name="traffic_type" value="all" ' . checked( $params['traffic_type'], 'all', false ) . '> All</label> &nbsp; ';
		echo '<label><input type="radio" name="traffic_type" value="human" ' . checked( $params['traffic_type'], 'human', false ) . '> Human</label> &nbsp; ';
		echo '<label><input type="radio" name="traffic_type" value="known_bots" ' . checked( $params['traffic_type'], 'known_bots', false ) . '> Known Bots</label> &nbsp; ';
		echo '<label><input type="radio" name="traffic_type" value="unknown" ' . checked( $params['traffic_type'], 'unknown', false ) . '> Unknown</label></div>';

		// --- Custom Range Toggle ---
		$show_custom = ( ! empty( $params['from'] ) || ! empty( $params['to'] ) );
		echo '<div class="rartoggle">';
		echo '<input type="checkbox" id="customRangeToggle" name="custom_range_toggle" ' . checked( $show_custom, true, false ) . '>';
		echo '<label for="customRangeToggle"></label>';
		echo '<span>Custom Range</span>';
		echo '</div>';

		// --- Custom Date Inputs ---
		echo '<div id="customRangeFields" style="display: ' . ( $show_custom ? 'inline-block' : 'none' ) . ';">';
		echo 'From <input type="date" name="from" id="from" min="' . esc_attr( $min_date ) . '" max="' . esc_attr( $today ) . '" value="' . esc_attr( $from_value ) . '"> ';
		echo 'To <input type="date" name="to" id="to" min="' . esc_attr( $min_date ) . '" max="' . esc_attr( $today ) . '" value="' . esc_attr( $to_value ) . '"> ';
		echo '</div>';

		echo '</div>'; // End flex container

		echo '<input type="submit" class="button button-primary" value="Apply Filters">';
		echo '</form><br>';
	}
}