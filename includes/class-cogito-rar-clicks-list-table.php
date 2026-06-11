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

            // Rows flagged by hand via the "Flag as bot" row action
            if ( $bot_name_value === 'Manually flagged' ) {
                return '<span title="Flagged manually from the clicks table">' . $display_name . '</span>';
            }

            // Rows auto-flagged by the live bot list, e.g. "Live list (ip)"
            if ( strpos( $bot_name_value, 'Live list' ) === 0 ) {
                return '<span title="Matched a signal on the live bot list">' . $display_name . '</span>';
            }

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

            // The primary column also carries the "Flag as bot" row action and
            // its hidden signal panel (WP core CSS reveals .row-actions on hover).
            if ( $column_name === $primary ) {
                echo $this->render_flag_bot_action( $item );
            }

            echo "</td>"; // Close table data cell
        }
    }

    /**
     * Renders the "Flag as bot" row action and its hidden signal panel.
     *
     * The panel lists this row's four signals (IP, hostname, org, user agent)
     * as checkboxes — all UNTICKED by default, so a hasty confirm only flags
     * this one row and adds nothing to the live bot list. Checkboxes carry only
     * the signal TYPE; values are read server-side from the DB row.
     *
     * @param object $item The current click row.
     * @return string Panel HTML.
     */
    protected function render_flag_bot_action( $item ) {
        // Signals available on this row — empty values are skipped (nothing to blacklist)
        $signals = [
            'ip'       => [ 'label' => 'IP',         'value' => $item->ip_address ],
            'hostname' => [ 'label' => 'Hostname',   'value' => $item->hostname ],
            'org'      => [ 'label' => 'Org',        'value' => $item->org ],
            'ua'       => [ 'label' => 'User agent', 'value' => $item->user_agent ],
        ];

        $html  = '<div class="row-actions"><span class="rar-flag-bot">';
        $html .= '<a href="#" class="rar-flag-bot-toggle">Flag as bot</a>';
        $html .= '</span></div>';

        // Hidden panel, revealed by JS. Nonce + row ID travel as data attributes.
        $html .= '<div class="rar-flag-bot-panel" data-click-id="' . absint( $item->id ) . '"';
        $html .= ' data-nonce="' . esc_attr( wp_create_nonce( 'rar_flag_bot_nonce' ) ) . '" hidden>';
        $html .= '<p class="rar-flag-bot-intro">Mark this click as a bot. Tick a signal to also add it to the live bot list — <strong>all future clicks matching it will be auto-flagged</strong>.</p>';

        foreach ( $signals as $type => $signal ) {
            $value = trim( (string) $signal['value'] );
            if ( '' === $value ) {
                continue; // No value on this row — nothing to offer
            }
            // Truncate long values (user agents especially) for display only;
            // the server reads the full value from the DB row.
            $display = mb_strimwidth( $value, 0, 60, '…' );

            $html .= '<label><input type="checkbox" value="' . esc_attr( $type ) . '" /> ';
            $html .= esc_html( $signal['label'] ) . ': ';
            $html .= '<span class="rar-flag-bot-value" title="' . esc_attr( $value ) . '">' . esc_html( $display ) . '</span>';
            $html .= '</label>';
        }

        // type="button" is essential — the table sits inside a GET <form>,
        // and a default submit button would reload the page instead.
        $html .= '<div class="rar-flag-bot-actions">';
        $html .= '<button type="button" class="button button-primary rar-flag-bot-confirm">Confirm flag</button>';
        $html .= '<button type="button" class="button rar-flag-bot-cancel">Cancel</button>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
    
    /**
     * Ensures the default WP_List_Table HTML output is rendered.
     */
    public function display() {
        parent::display();
    }
}

endif; // End class_exists( 'WP_List_Table' ) check.