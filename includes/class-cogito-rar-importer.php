<?php
/**
 * RAR Importer UI + CSV Logic
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Normalize boolean fields from CSV: accepts "true"/"false" (case-insensitive).
 * Returns '1' for true, '0' for false. Returns null if value is invalid.
 */
function rar_csv_parse_boolean( $value ) {
	$val = strtolower( trim( $value ) );
	if ( $val === 'true' ) return '1';
	if ( $val === 'false' ) return '0';
	return null;
}


class Cogito_RAR_Importer {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_importer_submenu' ] );
	}

	public function add_importer_submenu() {
	add_submenu_page(
		'edit.php?post_type=rar_redirect',
		'RARLinks | Import or Export Links',          // Page title (shown in browser tab when submenu is clicked)
		'Import/Export Links',     // Menu label (shown in WordPress admin sidebar under "RARLinks")
		'manage_options',    // Capability required to access the submenu
		'rar_importer',      // Slug used in the URL (e.g. ?page=rar_importer)
		[ $this, 'render_importer_page' ] // Callback function to render the page content
	);
}


	public function render_importer_page() {
		?>
		<div class="wrap rar_importer_wrap">
			<h1>RARLink Importer</h1>
			<p>Upload a CSV file to import links (from other link management plugins e.g. Pretty Links) into RARLinks.</p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'rar_import_csv', 'rar_import_nonce' ); ?>
				<input type="file" name="rar_csv" accept=".csv" required>
				<?php submit_button( 'Import CSV' ); ?>
			</form>

            <div>
                <h2>CSV Format - Field Descriptions:</h2>
                <ul class="rar-import-fields">
	<li><span class="rar-field-name">title</span>: The name of the redirect (used in admin only).</li>
	<li><span class="rar-field-name">slug</span>: The vanity URL part after the domain (e.g. <code>example-link</code> becomes <code>https://yourdomain.com/example-link/</code>).</li>
	<li><span class="rar-field-name">target</span>: The final destination URL.</li>
	<li><span class="rar-field-name">type</span>: Redirect type. Accepts <code>301</code>, <code>302</code>, or <code>307</code>. Defaults to <code>307</code> if omitted or invalid.</li>
	<li><span class="rar-field-name">notes</span>: Optional description or notes.</li>
	<li><span class="rar-field-name">active</span>: Boolean. Accepts <code>true</code> or <code>false</code>. Defaults to <code>true</code>.</li>
	<li><span class="rar-field-name">nofollow</span>: Boolean. Accepts <code>true</code> or <code>false</code>. Defaults to <code>true</code>.</li>
	<li><span class="rar-field-name">sponsored</span>: Boolean. Accepts <code>true</code> or <code>false</code>. Defaults to <code>true</code>.</li>
        <li><span class="rar-field-name">publish_date</span>: Optional. Format as <code>YYYY-MM-DD HH:MM:SS</code>. Sets the post's publish date. Defaults to now.</li>

</ul>

<h3 class="rar-sample-row">Example row (CSV):</h3><br>
<table class="rar-sample-table">
	<thead>
		<tr>
			<th>title</th>
			<th>slug</th>
			<th>target</th>
			<th>type</th>
			<th>notes</th>
			<th>active</th>
			<th>nofollow</th>
			<th>sponsored</th>
            <th>publish_date</th>

		</tr>
	</thead>
	<tbody>
		<tr>
			<td>Example Link</td>
			<td>example-link</td>
			<td>https://destination.com</td>
			<td>307</td>
			<td>This is a sample RARLink</td>
			<td>true</td>
			<td>true</td>
			<td>true</td>
            <td>2021-05-06 13:35:59</td>

		</tr>
	</tbody>
</table>




            <div>    
            <?php
            // ─── Handle CSV Upload ─────────────────────────
            // - Validates the file and reads headers
            // - Parses rows into associative arrays
            // - Prepares data for processing in later steps

                if ( isset( $_FILES['rar_csv'] ) && check_admin_referer( 'rar_import_csv', 'rar_import_nonce' ) ) {
                
                    $csv = $_FILES['rar_csv'];
                
                    // Check for valid upload
                    if ( $csv['error'] !== UPLOAD_ERR_OK ) {
                        echo '<div class="notice notice-error"><p>CSV upload failed. Please try again.</p></div>';
                        return;
                    }
                
                    // Open file
                    $file = fopen( $csv['tmp_name'], 'r' );
                    if ( ! $file ) {
                        echo '<div class="notice notice-error"><p>Unable to open the CSV file.</p></div>';
                        return;
                    }
                
                    // Read header row
                    $headers = fgetcsv( $file );
                    if ( ! $headers ) {
                        echo '<div class="notice notice-error"><p>CSV appears to be empty or malformed.</p></div>';
                        fclose( $file );
                        return;
                    }
                
                    // Store parsed rows
                    $rows = [];
                    $malformed_rows = [];
                    
                    while ( ( $data = fgetcsv( $file ) ) !== false ) {
                        // Preprocess the line to escape stray quotation marks
                        $sanitized_line = preg_replace_callback(
                            '/"(?:[^"]|"")*"/', // Match valid quoted strings
                            function ( $matches ) {
                                return $matches[0]; // Leave properly quoted fields untouched
                            },
                            implode( ',', $data )
                        );
                    
                        // Escape any single quotes not already escaped
                        $sanitized_line = preg_replace( '/(?<!")"(?!")/', '""', $sanitized_line );
                    
                        // Parse the cleaned line back into an array
                        $data_clean = str_getcsv( $sanitized_line );
                    
                        // Ensure the row has the same number of columns as headers
                        if ( count( $data_clean ) === count( $headers ) ) {
                            $rows[] = array_combine( $headers, $data_clean );
                        } else {
                            $malformed_rows[] = [ 'raw' => $sanitized_line, 'error' => 'Malformed or misquoted row' ];
                        }
                    }

                    fclose( $file ); // Keep this to properly close the file after reading

                
                    echo '<div class="notice notice-success"><p>CSV file loaded successfully with ' . count( $rows ) . ' rows.</p></div>';
                
                    // (We'll process the rows in the next step)
                    // ─── Insert Each RARLink Post from Parsed CSV ─────────────
                    // Loops through each parsed row and inserts a RARLink CPT post.
                    // Sets post title, slug, and all relevant meta fields.
                    // Handles active/inactive toggle as '1' or '0'.
                    
                    $imported = 0;
                    foreach ( $rows as $row ) {
                    	$title = sanitize_text_field( $row['title'] ?? '' );
                    	$slug  = sanitize_title( $row['slug'] ?? '' );
                    	$target = esc_url_raw( $row['target'] ?? '' );
                    	$type = in_array( $row['type'] ?? '', ['301', '302', '307'], true ) ? $row['type'] : '307';
                    	$notes  = sanitize_textarea_field( $row['notes'] ?? '' );
                    
                    	// Interpret active status
                    	$is_active  = rar_csv_parse_boolean( $row['active'] ?? 'true' );
                        $nofollow  = rar_csv_parse_boolean( $row['nofollow'] ?? 'true' );
                        $sponsored = rar_csv_parse_boolean( $row['sponsored'] ?? 'true' );
                        $date_raw = trim( $row['publish_date'] ?? '' );

                        // Validate publish_date format: 'YYYY-MM-DD HH:MM:SS'
                        if ( $date_raw && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date_raw ) ) {
                        	$date = $date_raw;
                        } elseif ( empty( $date_raw ) ) {
                        	$date = current_time( 'mysql' ); // Fallback to now if not provided
                        } else {
                        	$row['error'] = 'Invalid publish_date format';
                        	$malformed_rows[] = $row;
                        	continue;
                        }





                        // Skip malformed rows and track them
                        if ( empty( $title ) || empty( $slug ) || empty( $target ) ) {
                        	$malformed_rows[] = $row;
                        	continue;
                        }

                    
                    	// Insert the post
                    	$post_id = wp_insert_post([
                        	'post_type'   => 'rar_redirect',
                        	'post_title'  => $title,
                        	'post_status' => 'publish',
                        	'post_name'   => $slug,
                        	'post_date'   => $date, // ✅ Set publish date from CSV
                        ]);

                    	if ( is_wp_error( $post_id ) ) continue;

                        wp_update_post( [ 'ID' => $post_id ] );

                    
                    	// Set post meta
                    	update_post_meta( $post_id, '_rar_target',   $target );
                    	update_post_meta( $post_id, '_rar_type',     $type );
                    	update_post_meta( $post_id, '_rar_notes',    $notes );
                    	update_post_meta( $post_id, '_rar_active',   $is_active );
                        update_post_meta( $post_id, '_rar_nofollow', $nofollow );
                        update_post_meta( $post_id, '_rar_sponsored', $sponsored );

                    
                    	$imported++;
                    }
                    
                    echo '<div class="notice notice-success"><p>Successfully imported ' . $imported . ' Link(s).</p></div>';
                    // ─── Show Malformed Rows if Any ─────────────────────────
                    if ( ! empty( $malformed_rows ) ) {
                    	echo '<div class="notice notice-warning"><p>Skipped ' . count( $malformed_rows ) . ' malformed rows:</p><ul style="font-size: 0.9em;">';
                    	foreach ( $malformed_rows as $bad_row ) {
                    		echo '<li>' . esc_html( json_encode( $bad_row ) ) . '</li>';
                    	}
                    	echo '</ul></div>';
                    }


                }
                ?>

		</div>
		<?php
	}
}
