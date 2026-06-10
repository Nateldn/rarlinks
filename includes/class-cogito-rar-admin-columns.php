<?php
/**
 * Adds custom columns to the RARLinks admin list table.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Cogito_RAR_Admin_Columns {

	/**
	 * Hook into admin column filters/actions.
	 */
	public static function init() {
	add_filter( 'manage_rar_redirect_posts_columns', [ self::class, 'add_columns' ] );
	add_action( 'manage_rar_redirect_posts_custom_column', [ self::class, 'render_column' ], 10, 2 );
	add_filter( 'manage_edit-rar_redirect_sortable_columns', [ self::class, 'make_columns_sortable' ] );
	add_action( 'pre_get_posts', [ self::class, 'handle_column_sorting' ] );
	add_filter( 'posts_search', [ self::class, 'extend_admin_search' ], 10, 2 );
}

    /**
 * 🔍 Extend admin search to include slug and meta fields.
 */
public static function extend_admin_search( $search, $query ) {
	if (
		! is_admin() ||
		! $query->is_main_query() ||
		$query->get( 'post_type' ) !== 'rar_redirect' ||
		! $query->is_search()
	) {
		return $search;
	}

	global $wpdb;
	$search_term = esc_sql( $query->get( 's' ) );

	$search = " AND (
		{$wpdb->posts}.post_title LIKE '%{$search_term}%'
		OR {$wpdb->posts}.post_name LIKE '%{$search_term}%'
		OR EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} pm
			WHERE pm.post_id = {$wpdb->posts}.ID
			AND pm.meta_key IN ('_rar_target', '_rar_notes', '_rar_type')
			AND pm.meta_value LIKE '%{$search_term}%'
		)
	)";

	return $search;
}


	/**
	 * ➕ Add custom columns inlcuding "Clicks" column to admin table.
	 */
	public static function add_columns( $columns ) {
	return [
		'cb'         => $columns['cb'],
		'title'      => 'Title',
		'target'     => 'Target URL',
		'type'       => 'Type',
		'active'     => 'Active',
		'nofollow'   => 'Nofollow',
		'sponsored'  => 'Sponsored',
		'rar_clicks' => 'Clicks',
		'date'       => 'Created At',
        'slug'       => 'Vanity Slug',
	];
}

        /**
     * 🧭 Make selected custom columns sortable in admin.
     */
    public static function make_columns_sortable( $columns ) {
        $columns['rar_clicks'] = 'rar_clicks';         // Sort by click count (custom JOIN logic)
        $columns['active']     = 'rar_active';         // Sort by Active meta key
        $columns['nofollow']   = 'rar_nofollow';       // Sort by Nofollow meta key
        $columns['sponsored']  = 'rar_sponsored';      // Sort by Sponsored meta key
        $columns['type']       = 'rar_type';           // Sort by Redirect Type
        return $columns;
    }


    /**
 * 🧮 Modify the query to support sorting by custom meta fields.
 */
public static function handle_column_sorting( $query ) {
	// Only run in admin area, main query, and for our CPT
	if ( ! is_admin() || ! $query->is_main_query() ) return;
	if ( $query->get( 'post_type' ) !== 'rar_redirect' ) return;

	// Determine which column is being sorted
	$orderby = $query->get( 'orderby' );

	switch ( $orderby ) {
		case 'rar_clicks':
			// Join against our custom clicks table
			global $wpdb;
			$query->set( 'orderby', 'click_count' );
			add_filter( 'posts_clauses', function ( $clauses ) use ( $wpdb ) {
				$clicks_table = esc_sql( $wpdb->prefix . 'rarlinks_clicks' );


				// Join click count per post_id
				$clauses['join'] .= " LEFT JOIN (
					SELECT post_id, COUNT(*) as click_count
					FROM {$clicks_table}
					GROUP BY post_id
				) AS click_data ON {$wpdb->posts}.ID = click_data.post_id";

				// Order by click_count (default to 0 if null)
				$order = strtoupper( get_query_var( 'order' ) );
                $order = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';
                $clauses['orderby'] = "click_count+0 $order";


				return $clauses;
			}, 10, 1 );
			break;

		case 'rar_active':
		case 'rar_nofollow':
		case 'rar_sponsored':
		case 'rar_type':
			// Sort by postmeta values
			$query->set( 'meta_key', '_' . $orderby );
			$query->set( 'orderby', 'meta_value' );
			break;
	}
}



	/**
    * 🧩 Output values for each custom column.
    */

    	public static function render_column( $column, $post_id ) {
    	global $wpdb;
    
    	switch ( $column ) {
    		case 'target':
    			echo esc_url( get_post_meta( $post_id, '_rar_target', true ) );
    			break;
    
    		case 'type':
    			echo esc_html( get_post_meta( $post_id, '_rar_type', true ) );
    			break;
    
    		case 'active':
    			$is_active = get_post_meta( $post_id, '_rar_active', true ) === '1';
    			$icon      = $is_active ? 'randomize' : 'dismiss';
    			$title     = $is_active ? 'Active' : 'Inactive';
    			echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" title="' . esc_attr( $title ) . '"></span>';
    			break;
    
    		case 'nofollow':
    			$is_nofollow = get_post_meta( $post_id, '_rar_nofollow', true ) !== '0';
    			$icon        = $is_nofollow ? 'hidden' : 'visibility';
    			$title       = $is_nofollow ? 'nofollow Enabled' : 'nofollow Disabled';
    			echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" title="' . esc_attr( $title ) . '"></span>';
    			break;
    
    		case 'sponsored':
    			$is_sponsored = get_post_meta( $post_id, '_rar_sponsored', true ) !== '0';
    			$icon         = $is_sponsored ? 'money-alt' : 'admin-links';
    			$title        = $is_sponsored ? 'sponsored Enabled' : 'sponsored Disabled';
    			echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" title="' . esc_attr( $title ) . '"></span>';
    			break;
    
    		case 'rar_clicks':
    			$count = $wpdb->get_var( $wpdb->prepare(
    				"SELECT COUNT(*) FROM {$wpdb->prefix}rarlinks_clicks WHERE post_id = %d",
    				$post_id
    			));
    			echo intval( $count );
    			break;
    
    		case 'slug':
    			$slug   = get_post_field( 'post_name', $post_id );
    			$vanity = home_url( '/' . $slug . '/' );
    
    			echo '<div class="rar-copy-slug-wrap">';
    			echo '<input type="text" class="rar-copy-input" value="' . esc_url( $vanity ) . '" readonly>';
    			echo '<button type="button" class="rar-copy-btn button" data-copy="' . esc_url( $vanity ) . '" aria-label="Copy Vanity URL">';
    			echo '<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>';
    			echo '</button>';
    			echo '<span class="rar-copy-feedback" style="display:none; margin-left:8px;">Copied!</span>';
    			echo '</div>';
    			break;
    	}
    }

}

add_action( 'admin_footer', function() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.rar-copy-btn').on('click', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const text = $btn.data('copy');
            const $input = $btn.siblings('.rar-copy-input');
            const $feedback = $btn.siblings('.rar-copy-feedback');

            // Copy to clipboard
            navigator.clipboard.writeText(text).then(() => {
                // Show feedback
                $feedback.fadeIn(200).delay(1000).fadeOut(400);
            });
        });
    });
    </script>
    <?php
});

