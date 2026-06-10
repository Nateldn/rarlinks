<?php
// Ensure WP_List_Table is loaded if in the admin and not already present.
if ( is_admin() && ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// ✅ Include the parse_user_agent helper
require_once plugin_dir_path( __FILE__ ) . 'helpers/parse-user-agent.php';


// Conditionally define the class only if the parent WP_List_Table exists.
if ( class_exists( 'WP_List_Table' ) ) :

class Cogito_RAR_Clicks_List_Table extends WP_List_Table {

    protected $filters;

    public function __construct( $filters = [] ) {
        $this->filters = $filters;
        parent::__construct([
            'singular' => 'rar_click',
            'plural'   => 'rar_clicks',
            'ajax'     => false,
        ]);
    }

  public function get_columns() {

    // On individual link view: hide RARLink Name column and lead with Time
    $is_filtered = isset( $_GET['post_id'] ) && is_numeric( $_GET['post_id'] );

    if ( $is_filtered ) {
        return [
            'cb'         => '<input type="checkbox" />',
            'timestamp'  => 'Time',             // ✅ Matches DB column
            'visitor_id' => 'Visitor ID',       // ✅ Matches DB column
            'ip_address' => 'IP',               // ✅ Matches DB column
            'hostname'   => 'Host',             // ✅ Matches DB column
            'org'        => 'Org',              // ✅ Matches DB column
            'type'       => 'Type',             // ✅ Derived from `bot_or_not`
            'bot_name'   => 'Bot Name',         // ✅ Matches DB column
            'referrer'   => 'Referrer',         // ✅ Matches DB column
            'browser'    => 'Browser',          // 🟡 Derived
            'os'         => 'OS',               // 🟡 Derived
            'device'     => 'Device',           // 🟡 Derived
        ];
    }

    // Default view: all columns, RARLink Name first
    return [
        'cb'          => '<input type="checkbox" />',
        'post_title'  => 'RARLink Name',        // ✅ Matches DB column
        'timestamp'   => 'Time',                // ✅ Matches DB column
        'visitor_id'  => 'Visitor ID',          // ✅ Matches DB column
        'ip_address'  => 'IP',                  // ✅ Matches DB column
        'hostname'    => 'Host',                // ✅ Matches DB column
        'org'         => 'Org',                 // ✅ Matches DB column
        'type'        => 'Type',                // ✅ Derived from `bot_or_not`
        'bot_name'    => 'Bot Name',            // ✅ Matches DB column
        'referrer'    => 'Referrer',            // ✅ Matches DB column
        'browser'     => 'Browser',             // 🟡 Derived
        'os'          => 'OS',                  // 🟡 Derived
        'device'      => 'Device',              // 🟡 Derived
    ];
}

// Add get_primary_column_name if it's not defined, or if you want a specific primary column.
// Otherwise, WP_List_Table will try to auto-detect.
// protected function get_primary_column_name() {
//     return 'post_title'; 
// }


    public function prepare_items() {
        global $wpdb;
        $table = "{$wpdb->prefix}rarlinks_clicks";
    
        $per_page     = $this->get_items_per_page('rar_clicks_per_page', 100);
        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;
    
        $where = '';
        if ( ! empty( $this->filters ) ) {
            $where = 'WHERE ' . implode( ' AND ', $this->filters );
        }
    
        $total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
    
        $query = "SELECT * FROM $table $where ORDER BY timestamp DESC LIMIT %d OFFSET %d";
        $sql = $wpdb->prepare( $query, $per_page, $offset );
    
    
        $this->items = $wpdb->get_results( $sql );
    
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ]);

        // Force column headers to be populated immediately after items are prepared.
        // This ensures get_column_info() has current data when called later for rendering.
        $this->_column_headers = [
            $this->get_columns(),
            $this->get_hidden_columns(),
            $this->get_sortable_columns(),
            $this->get_primary_column_name() // get_primary_column_name is a method of WP_List_Table
        ];
    }


    public function column_default( $item, $column_name ) {

        switch ( $column_name ) {
            case 'post_title':
                $title = esc_html( $item->post_title );
                $url   = add_query_arg([
                    'post_type' => 'rar_redirect',
                    'page'      => 'rar_dashboard',
                    'post_id'   => $item->post_id,
                ], admin_url( 'edit.php' ) );
                return "<a href='" . esc_url($url) . "'>$title</a>";

            case 'timestamp':
                return esc_html( cogito_rar_localise_timestamp( $item->timestamp ) );

            case 'visitor_id':
            case 'ip_address':
            case 'hostname':
            case 'org':
                // Debug for these specific columns displaying incorrect data
                error_log('[RAR DEBUG] For ' . $column_name . ' - raw $item value: ' . ($item->$column_name ?? 'NOT_SET'));
                return esc_html( $item->$column_name ?: '—' ); // Correctly render their specific data

            case 'bot_name':
            $bot_name_value = $item->bot_name;

            // If bot_name is empty (which should be for human traffic), return 'n/a'
            if ( empty( $bot_name_value ) ) {
                return 'n/a';
            }

            $display_name = esc_html( $bot_name_value );
            $tooltip_text = '';

            // Attempt to parse the identification method and original name for tooltip
            if ( preg_match( '/^(AS\d+)|(By (PTR|IP|Org|Spamhaus ASN): (.+?))( \(Legit Bot\))?$/i', $bot_name_value, $matches ) ) {
                if ( !empty($matches[1]) ) { // It's an ASN (e.g., "AS123" from Spamhaus ASN check)
                    $display_name = esc_html($matches[1]);
                    $tooltip_text = 'Identified by Spamhaus ASN'; // ALTERED: Only method, no name
                } else { // It's a "By Type: Name" pattern
                    $identification_method = strtolower($matches[3]); // e.g., 'ptr', 'ip', 'org'
                    $extracted_name        = esc_html($matches[4]); 
                    $legit_bot_suffix      = !empty($matches[5]) ? $matches[5] : ''; 

                    $display_name = $extracted_name;
                    $tooltip_text = 'Identified by ' . $identification_method; // ALTERED: Only method, no name
                }
            }
            else {
                $display_name = esc_html($bot_name_value);
                $tooltip_text = 'Identified by User Agent'; // ALTERED: Only method, no name
            }


            // Final return: wrap with span and title for tooltip if text exists
            if ( ! empty( $tooltip_text ) && $display_name !== 'n/a' ) {
                return '<span title="' . esc_attr($tooltip_text) . '">' . $display_name . '</span>';
            }
            return $display_name; // Fallback to just displaying the name

            case 'referrer': // Referrer should be handled separately from the bot_name group
                return esc_html( $item->$column_name ?: '—' );

            case 'type':
                $icon = 'dashicons-editor-help';
                $tooltip = 'Click from unknown traffic type – further investigation required';
                switch ( (int) $item->bot_or_not ) {
                    case 0:
                        $icon = 'dashicons-admin-users';
                        $tooltip = 'Likely human';
                        break;
                    case 1:
                        $icon = 'dashicons-welcome-view-site';
                        $tooltip = 'Known Bot';
                        break;
                }
                return "<span class='dashicons $icon' title='" . esc_attr($tooltip) . "'></span>";

            case 'browser':
            case 'os':
            case 'device':
                $parsed = cogito_rar_parse_user_agent( $item->user_agent ?? '' );

                return esc_html( $parsed[ $column_name ] ?? '—' );

            default:
                return '—';
        }
    }


    public function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="bulk-select[]" value="%d" />',
            absint( $item->id )
        );
    }

    public function get_sortable_columns() {
        return [
            'timestamp'   => [ 'timestamp', true ],
            'post_title'  => [ 'post_title', false ],
            'ip_address'  => [ 'ip_address', false ],
        ];
    }

    protected function get_hidden_columns() {
        return [];
    }

    // IMPORTANT: Keep this method commented out. WP_List_Table handles row iteration.
    // If uncommented, it will recursively call single_row() and not work as intended
    // public function display_rows() {
    //     error_log('[RAR DEBUG] display_rows() was called'); // 🔍 KEY LOG
    //     foreach ( $this->items as $item ) {
    //         error_log('[RAR DEBUG] Rendering row for item ID: ' . $item->id); // 🔍 VERIFY
    //         $this->single_row( $item );
    //     }
    // }

    /**
     * Renders the columns for a single row. This is a critical override to ensure
     * WP_List_Table properly calls column_default() for all defined columns,
     * especially when dealing with custom or dynamically derived fields.
     *
     * @param object $item The current data item for the row.
     */
    protected function single_row_columns( $item ) {
        // Retrieve column headers (includes hidden and sortable info)
        list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();

        // Loop through each column defined in get_columns()
        foreach ( $columns as $column_name => $column_display_name ) {
            // Prepare CSS classes and inline styles for the table cell
            $classes    = "class='$column_name column-$column_name'";
            $style      = in_array( $column_name, $hidden ) ? ' style="display:none;"' : '';
            $attributes = "$classes$style";

            echo "<td $attributes>"; // Open table data cell

            // Explicitly call column_default() for all columns.
            // This ensures every column is processed by your defined logic,
            // bypassing any potential internal WP_List_Table ambiguities.
            echo $this->column_default( $item, $column_name );

            echo "</td>"; // Close table data cell
        }
    }
    
    /**
     * Ensures the default WP_List_Table HTML output is rendered.
     */
    public function display() {
        parent::display();
    }
}

endif; // End class_exists( 'WP_List_Table' ) check.