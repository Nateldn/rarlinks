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
			'post_id'      => isset( $_GET['post_id'] ) && is_numeric( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : null,
		];
	}

	/**
	 * Strictly validate a date string as YYYY-MM-DD.
	 * Returns '' for anything that doesn't match, which prevents these
	 * values from being used to break out of the SQL string literals
	 * they are later interpolated into.
	 */
	public static function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Builds timezone-aware SQL date clauses for a quick range and/or a custom
	 * from/to. Shared by the dashboard and Bot Cleanup. Pure — applies no
	 * default range; the caller decides whether to default.
	 *
	 * @param string $range A quick-range key, '' or 'all'.
	 * @param string $from  Validated YYYY-MM-DD or ''.
	 * @param string $to    Validated YYYY-MM-DD or ''.
	 * @return array SQL clauses.
	 */
	public static function date_clauses( $range, $from, $to ) {
		$clauses = [];
		$col     = "DATE(CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London'))";
		$tz      = "CONVERT_TZ(timestamp, 'America/Los_Angeles', 'Europe/London')";

		if ( ! empty( $range ) && $range !== 'all' ) {
			switch ( $range ) {
				case 'today':
					$clauses[] = "$col = CURDATE()";
					break;
				case 'yesterday':
					$clauses[] = "$col = CURDATE() - INTERVAL 1 DAY";
					break;
				case '7days':
					$clauses[] = "$col >= CURDATE() - INTERVAL 6 DAY";
					break;
				case '14days':
					$clauses[] = "$col >= CURDATE() - INTERVAL 13 DAY";
					break;
				case '30days':
					$clauses[] = "$col >= CURDATE() - INTERVAL 29 DAY";
					break;
				case 'thismonth':
					$clauses[] = "MONTH($tz) = MONTH(CURRENT_DATE()) AND YEAR($tz) = YEAR(CURRENT_DATE())";
					break;
				case 'lastmonth':
					$clauses[] = "MONTH($tz) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH) AND YEAR($tz) = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH)";
					break;
			}
		}

		// Custom dates override/augment the range. $from/$to are pre-validated
		// YYYY-MM-DD (sanitize_date), safe to interpolate.
		if ( $from && $to ) {
			$clauses[] = "$col BETWEEN '$from' AND '$to'";
		} elseif ( $from && ! $to ) {
			$clauses[] = "$col BETWEEN '$from' AND CURDATE()";
		} elseif ( ! $from && $to ) {
			$clauses[] = "$col = '$to'";
		}

		return $clauses;
	}

	/**
	 * Builds the SQL filter clauses for the (human-only) dashboard.
	 *
	 * @return array An array of SQL filter clauses.
	 */
	public static function get_filters() {
		$params  = self::get_params();
		$range   = $params['range'];
		$from    = $params['from'];
		$to      = $params['to'];
		$post_id = $params['post_id'];

		// Default to last 30 days if no date filter is set
		if ( empty( $range ) && empty( $from ) && empty( $to ) ) {
			$range = '30days';
		}

		$filters = self::date_clauses( $range, $from, $to );

		if ( ! is_null( $post_id ) ) {
			$filters[] = 'post_id = ' . $post_id;
		}

		// Human-only: the dashboard is the human reporting interface.
		// Bots and unknowns are excluded here and managed in Bot Cleanup.
		$filters[] = 'bot_or_not = 0';

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