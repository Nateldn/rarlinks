<?php
/**
 * Core plugin class – CPT, meta-boxes, and full redirect logic.
 * Includes Vanity, Target, Type, Notes, Rotation & GEO.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cogito_RAR {

	const CPT = 'rar_redirect';

	/** @var Cogito_Loader */
    private $loader;
    
    public function __construct( $loader ) {
	$this->loader = $loader;

	$loader->add_action( 'init',                    $this, 'register_cpt' );
    $loader->add_action( 'add_meta_boxes',          $this, 'add_meta_box' );
    $loader->add_action( 'save_post',               $this, 'save_meta', 10, 2 );
    $loader->add_filter( 'post_type_link',          $this, 'filter_permalink', 10, 2 );
    $loader->add_action( 'admin_enqueue_scripts',   $this, 'enqueue_admin_assets' );
    $loader->add_action( 'admin_notices',           $this, 'admin_notice_rotation_error' );
    add_action( 'template_redirect', [ 'Cogito_RAR_Redirect_Engine', 'maybe_redirect' ], 5 );

    }

    
    // Enqueue Admin CSS
    public function enqueue_admin_assets() {
    wp_enqueue_style(
            'rar-admin-css',
            plugin_dir_url( __FILE__ ) . '../assets/css/admin.css',
            [],
            '1.0'
        );
    }


        
    // 🔧 Kick off plugin hooks and filters via the loader

	public function run() {
		$this->loader->run();
	}


    
/* ─────────────────────────────────*/
// Register Custom Post Type (instance method for WordPress init hook)

public function register_cpt() {
	self::register_cpt_static();
}

// Static method for reuse on plugin activation or elsewhere

public static function register_cpt_static() {
	$labels = [
		'name'               => __( 'RARLinks', 'text_domain' ),
		'singular_name'      => __( 'RARLink', 'text_domain' ),
		'menu_name'          => __( 'RARLinks', 'text_domain' ),
		'name_admin_bar'     => __( 'RARLinks', 'text_domain' ),
		'add_new'            => __( 'Add New', 'text_domain' ),
		'add_new_item'       => __( 'Add New RARLink', 'text_domain' ),
		'edit_item'          => __( 'Edit RARLink', 'text_domain' ),
		'new_item'           => __( 'New RARLink', 'text_domain' ),
		'view_item'          => __( 'View RARLink', 'text_domain' ),
		'all_items'          => __( 'All RARLinks', 'text_domain' ),
		'search_items'       => __( 'Search RARLinks', 'text_domain' ),
		'not_found'          => __( 'No RARLinks found', 'text_domain' ),
		'not_found_in_trash' => __( 'No RARLinks found in Trash', 'text_domain' ),
	];

	$args = [
		'label'               => __( 'RARLink', 'text_domain' ),
		'description'         => __( 'Manage vanity redirects with rotation and GEO targeting.', 'text_domain' ),
		'labels'              => $labels,
		'public'              => false,
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_admin_bar'   => true,
		'menu_icon'           => 'dashicons-randomize',
		'supports'            => [ 'title' ],
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
		'capability_type'     => 'post',
		'show_in_rest'        => false,
		'can_export'          => true,
	];

	register_post_type( self::CPT, $args );

}




	/*── Add the Meta-Box ─────────────────────────────────*/
	public function add_meta_box() {
	// ✅ Only allow users who can edit posts to see the meta box
	if ( current_user_can( 'edit_posts' ) ) {
		add_meta_box(
			'rar_details',
			'Redirect Details',
			[ $this, 'render_meta_box' ],
			self::CPT,
			'normal',
			'high'
		);
	}
}

	/*── Render all fields ─────────────────────────────────*/
	public function render_meta_box( $post ) {
	// 🚫 Prevent unauthorized users from accessing meta box content
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		echo '<p><em>You do not have permission to edit this redirect.</em></p>';
		return;
	}

	wp_nonce_field( 'rar_save_meta', 'rar_meta_nonce' );

        // ─── Active Toggle Switch (Enable/Disable Redirect) ─────────────────────
        $is_active = get_post_meta( $post->ID, '_rar_active', true );
        // Default to 'active' if no value is set
        if ( $is_active === '' ) $is_active = '1';
        
        // Container for switch and optional warning
        echo '<div id="rar-active-toggle" class="rartoggle">';
        
        // Toggle input with styled label
        echo '<input type="checkbox" id="rar_active" name="rar_active" value="1"' . checked( $is_active, '1', false ) . '>';
        echo '<label for="rar_active"></label>';
        echo '<span>Activate/Deactivate Redirect</span>';
        
        echo '</div>';
        
        // RARLink status messages
        if ( $is_active !== '1' ) {
            echo '<p class="rar-inactive-note" style="color:#a00; margin-top:-0.5em;">❌ Redirect Deactivated: Toggle to activate and save to enable redirect options.</p>';
        } else {
            echo '<p class="rar-active-note" style="color:green; margin-top:-0.5em;">✅ Redirect Active: Toggle to deactivate and disable redirect options.</p>';
        }


        echo '<div id="rar-meta-fields">';


		// Load existing values
		$slug     = $post->post_name;
		$target   = get_post_meta( $post->ID, '_rar_target', true );
		// Load stored type, default to 307 if none set
        $type = get_post_meta( $post->ID, '_rar_type', true );
        if ( empty( $type ) ) {
            $type = 307;
        }

		$notes    = get_post_meta( $post->ID, '_rar_notes',  true );
		$rotation = json_decode( get_post_meta( $post->ID, '_rar_rotation', true ) ?: '[]', true );
		$geo      = json_decode( get_post_meta( $post->ID, '_rar_geo', true ) ?: '[]', true );
		$vanity   = home_url( '/' . $slug . '/' );
        $json_file = plugin_dir_path( __FILE__ ) . '/data/countries-list.json';
        $countries = [];

        if ( file_exists( $json_file ) ) {
        	$json = file_get_contents( $json_file );
        	$countries = json_decode( $json, true );
        }

        echo '<datalist id="rar-country-list">';
        foreach ( $countries as $code => $name ) {
        	echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $name ) . '</option>';
        }
        echo '</datalist>';



		// Target URL (textarea, 3 rows)
		echo '<p><label><strong>Target URL:</strong><br>
		<textarea name="rar_target" rows="2" style="width:100%;">' . esc_textarea( $target ) . '</textarea>
		</label></p>';

		// Vanity Slug
		echo '<p><label>Vanity Link (slug after domain):<br>
		<input type="text" name="rar_slug" value="' . esc_attr( $slug ) . '" style="width:100%;" />
		</label></p>';

		// Redirect Type
		echo '<p><label><strong>Redirect Type:</strong>
		<select name="rar_type">
			<option value="301"' . selected( $type, 301, false ) . '>301 (Permanent)</option>
			<option value="302"' . selected( $type, 302, false ) . '>302 (Temporary)</option>
			<option value="307"' . selected( $type, 307, false ) . '>307 (Preserve Method)</option>
		</select>
		</label></p>';

        // rel="nofollow sponsored" header toggle
        $nofollow  = get_post_meta( $post->ID, '_rar_nofollow', true );
        $sponsored = get_post_meta( $post->ID, '_rar_sponsored', true );
        
        echo '<p><label><input type="checkbox" name="rar_nofollow" value="1"' . checked( $nofollow !== '0', true, false ) . '> Add <code>rel="nofollow"</code></label></p>';
        echo '<p><label><input type="checkbox" name="rar_sponsored" value="1"' . checked( $sponsored !== '0', true, false ) . '> Add <code>rel="sponsored"</code></label></p>';




		// Notes
		echo '<p><label>Notes:<br>
		<textarea name="rar_notes" rows="3" style="width:100%;">' . esc_textarea( $notes ) . '</textarea>
		</label></p>';

        // Load toggle state from post meta
        $geo_enabled = get_post_meta( $post->ID, '_rar_geo_enabled', true );
        $rot_enabled = get_post_meta( $post->ID, '_rar_rotation_enabled', true );
        
      
        
        echo '<div class="rartoggle">
            <input type="checkbox" id="rar_geo_enabled" name="rar_geo_enabled" value="1"' . checked( $geo_enabled, '1', false ) . ' />
            <label for="rar_geo_enabled"></label>
            <strong>Enable GEO Targeting</strong>
        </div>';
        
        echo '<div class="rartoggle">
            <input type="checkbox" id="rar_rotation_enabled" name="rar_rotation_enabled" value="1"' . checked( $rot_enabled, '1', false ) . ' />
            <label for="rar_rotation_enabled"></label>
            <strong>Enable Weighted Rotation</strong>
        </div>';

        

// ─── Weighted Rotation ─────────────────────────
echo '<h4>Weighted Rotation</h4>';
echo '<div id="rar-rotation">';

// Default row if empty
if ( empty( $rotation ) ) {
    $rotation = [ [ 'url' => $target, 'weight' => 100 ] ];
}

foreach ( $rotation as $i => $r ) {
    $url    = esc_attr( $r['url'] );
    $weight = intval( $r['weight'] );

    echo '<div class="rar-rotation-row" data-index="'. $i .'">';
    
    // URL input
    echo '<label>URL:
        <input type="url" name="rar_rotation['. $i .'][url]" value="'. $url .'" style="width:60%;"'. ( $i === 0 ? ' readonly' : '' ) .'>
    </label>';

    // Weight slider + number input
    echo '<label>Weight:
        <input type="range" class="weight-slider" min="0" max="100" step="1" value="'. $weight .'" data-index="'. $i .'">
        <input type="number" class="weight-input" name="rar_rotation['. $i .'][weight]" value="'. $weight .'" min="0" max="100" style="width:60px;">
    </label>';

    // Remove button (except for first row)
    if ( $i > 0 ) {
        echo '<a href="#" class="remove-rotation" style="margin-left:10px;">Remove</a>';
    }

    echo '</div>';
}

echo '</div>';
echo '<p><button type="button" class="button" id="add-rotation">+ Add Rotation URL</button></p>';




?>

        
<script>
//──────────────────────────────────────────────────────────────
// Inline JS for adding and removing Weighted Rotation 
// ──────────────────────────────────────────────────────────────
/*
 * ──────────────────────────────────────────────────────────────
 * 🔁 RAR Rotation UI: Simplified Auto-Balancing Script (No Locks)
 *
 * When one weight is adjusted, the others automatically update
 * to ensure the total weight always equals 100.
 * ──────────────────────────────────────────────────────────────
 */
jQuery(document).ready(function($) {

  const container = $('#rar-rotation');

  // Get all rows
  function getRows() {
    return container.find('.rar-rotation-row');
  }

  // Sync slider & number input
  function syncInputs($row, val) {
    $row.find('.weight-input').val(val);
    $row.find('.weight-slider').val(val);
  }

  // Rebalance all rows except the one being changed
  function rebalanceRows(changedIndex) {
    const $rows = getRows();
    const $changed = $rows.eq(changedIndex);
    const changedVal = parseInt($changed.find('.weight-input').val(), 10) || 0;

    const others = $rows.not($changed);
    let remaining = 100 - changedVal;
    if (remaining < 0) remaining = 0;

    const share = Math.floor(remaining / others.length);
    let leftover = remaining % others.length;

    others.each(function(i) {
      let val = share + (leftover > 0 ? 1 : 0);
      if (leftover > 0) leftover--;
      syncInputs($(this), val);
    });
  }

  // On slider or number input change
  container.on('input change', '.weight-slider, .weight-input', function() {
    const $row = $(this).closest('.rar-rotation-row');
    const index = $row.index();
    let value = parseInt($(this).val(), 10);
    if (isNaN(value)) value = 0;
    if (value > 100) value = 100;
    if (value < 0) value = 0;
    syncInputs($row, value);
    rebalanceRows(index);
  });

  // Add new row
  $('#add-rotation').on('click', function() {
    const idx = getRows().length;
    const row = `
      <div class="rar-rotation-row" data-index="${idx}">
        <label>URL:
          <input type="url" name="rar_rotation[${idx}][url]" style="width:60%;">
        </label>
        <label>Weight:
          <input type="range" class="weight-slider" min="0" max="100" step="1" value="0">
          <input type="number" class="weight-input" name="rar_rotation[${idx}][weight]" value="0" min="0" max="100" style="width:60px;">
        </label>
        <a href="#" class="remove-rotation" style="margin-left:10px;">Remove</a>
      </div>`;
    container.append(row);
    rebalanceRows(idx);
  });

  // Remove row
  container.on('click', '.remove-rotation', function(e) {
    e.preventDefault();
    $(this).closest('.rar-rotation-row').remove();
    getRows().each(function(i) {
      $(this).attr('data-index', i);
      $(this).find('input[type="url"]').attr('name', `rar_rotation[${i}][url]`);
      $(this).find('.weight-input').attr('name', `rar_rotation[${i}][weight]`);
    });
    rebalanceRows(0);
  });

});
</script>

<!-- // ─── End Rotation JS ─────────────────────────────────────         -->
        
<?php

        


// ─── GEO Targeting UI ─────────────────────────────────────
echo '<div id="rar-geo">';
echo '<h4>GEO Targeting</h4>';

if ( empty( $geo ) ) {
    $geo = array( array( 'country' => '', 'url' => '' ) );
}
foreach ( $geo as $i => $g ) {
    echo '<div class="rar-geo-row" data-index="' . $i . '">
        <label>Country:<input list="rar-country-list" name="rar_geo[' . $i . '][country]" value="' . esc_attr( $g['country'] ) . '" style="width:25%;" /></label>
        <label>URL:<input type="url" name="rar_geo[' . $i . '][url]" value="' . esc_attr( $g['url'] ) . '" style="width:65%;" /></label>';
    echo ' <a href="#" class="remove-geo"' . ( $i === 0 ? ' style="display:inline;"' : '' ) . '>Remove</a>';
    echo '</div>';
}
echo '<p><button type="button" class="button" id="add-geo">+ Add GEO Rule</button></p>';
echo '</div>';


// ─── Inline JS for adding/removing GEO rows ───────────────
?>
<script>
(function(){
  const geoContainer = document.getElementById('rar-geo');

  // Add new row
  document.getElementById('add-geo').addEventListener('click', function(){
    const idx = geoContainer.children.length;
    const row = document.createElement('div');
    row.className = 'rar-geo-row';
    row.dataset.index = idx;
    row.innerHTML = `
      <label>Country:<input list="rar-country-list" name="rar_geo[${idx}][country]" style="width:25%;"></label>
      <label>URL:<input type="url" name="rar_geo[${idx}][url]" style="width:65%;"></label>
      <a href="#" class="remove-geo">Remove</a>
    `;
    geoContainer.appendChild(row);
  });

  // Remove row (event delegation)
  geoContainer.addEventListener('click', function(e){
    if ( e.target && e.target.matches('a.remove-geo') ) {
      e.preventDefault();
      const row = e.target.closest('.rar-geo-row');
      row.remove();
      // Re-index remaining rows
      Array.from(geoContainer.children).forEach(function(r, i) {
        r.dataset.index = i;
        r.querySelector('input[list="rar-country-list"]').name = `rar_geo[${i}][country]`;
        r.querySelector('input[type="url"]').name = `rar_geo[${i}][url]`;
        const rem = r.querySelector('.remove-geo');
        if (rem) rem.style.display = i === 0 ? 'none' : 'inline';
      });
    }
  });
})();
</script>
<script>
jQuery(document).ready(function($) {
  function toggleSection(toggleId, sectionId) {
    const isChecked = $(toggleId).is(':checked');
    $(sectionId).toggle(isChecked);
  }

  // Initial show/hide based on saved meta
  toggleSection('#rar_geo_enabled', '#rar-geo');
  toggleSection('#rar_rotation_enabled', '#rar-rotation');

  // Listen for changes
  $('#rar_geo_enabled').on('change', function() {
    toggleSection('#rar_geo_enabled', '#rar-geo');
  });

  $('#rar_rotation_enabled').on('change', function() {
    toggleSection('#rar_rotation_enabled', '#rar-rotation');
  });
});
</script>        
<?php
// ──────────────────────────────────────────────────────────


		// Full Vanity Preview
		echo '<p><strong>Full Vanity URL:</strong> <code>' . esc_html( $vanity ) . '</code></p>';

        echo '</div>'; // Close rar-meta-fields container


		// Inline JS for dynamic rows
		?>
		
        <script>
		function addRotation(){
			let c = document.getElementById('rar-rotation'),
				i = c.children.length;
			c.insertAdjacentHTML('beforeend',
				'<p>' +
				'<label>URL:<input type="url" name="rar_rotation['+i+'][url]" style="width:70%;"></label>' +
				'<label>Weight:<input type="number" name="rar_rotation['+i+'][weight]" min="0" style="width:20%;"></label>' +
				'</p>'
			);
		}
		function addGeo(){
			let c = document.getElementById('rar-geo'),
				i = c.children.length;
			c.insertAdjacentHTML('beforeend',
				'<p>' +
				'<label>Country Code:<input type="text" name="rar_geo['+i+'][country]" style="width:20%;"></label>' +
				'<label>URL:<input type="url" name="rar_geo['+i+'][url]" style="width:70%;"></label>' +
				'</p>'
			);
		}
		</script>


        <script>
        /*
         * 🔄 Toggle RARLink Meta UI Based on Active Switch
         * Hides all redirect meta fields (except the toggle itself) when the RARLink is inactive.
         */
        jQuery(document).ready(function($) {
          function toggleRARActiveUI() {
            const isActive = $('#rar_active').is(':checked');
        
            // Toggle the main meta field container
            $('#rar-meta-fields').toggle(isActive);
        
            // Show/hide the inactive warning message
            $('.rar-inactive-note').toggle(!isActive);
          }
        
          // Run on page load
          toggleRARActiveUI();
        
          // Re-run on toggle change
          $('#rar_active').on('change', toggleRARActiveUI);
        });
        </script>



        
		<?php
	}

	/*── Save all meta on post save ──────────────────────*/
	public function save_meta( $post_id, $post ) {
		if (
			empty( $_POST['rar_meta_nonce'] ) ||
			! wp_verify_nonce( $_POST['rar_meta_nonce'], 'rar_save_meta' ) ||
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
			$post->post_type !== self::CPT ||
			! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

       // 🧭 Canonical Target URL: used for both saving and syncing rotation
        $canonical_target = esc_url_raw( $_POST['rar_target'] ?? '' );
        

		// Vanity slug
		if ( isset( $_POST['rar_slug'] ) ) {
			$new = sanitize_title( wp_unslash( $_POST['rar_slug'] ) );
			if ( $new && $new !== $post->post_name ) {
				remove_action( 'save_post', [ $this, 'save_meta' ], 10 );
				wp_update_post( [ 'ID' => $post_id, 'post_name' => $new ] );
				add_action( 'save_post', [ $this, 'save_meta' ], 10, 2 );
			}
		}

		// Simple fields
		update_post_meta( $post_id, '_rar_target', $canonical_target );
		update_post_meta( $post_id, '_rar_type',     intval( $_POST['rar_type']         ?? 302 ) );
		update_post_meta( $post_id, '_rar_notes',    sanitize_textarea_field( $_POST['rar_notes'] ?? '' ) );
        update_post_meta( $post_id, '_rar_nofollow', isset( $_POST['rar_nofollow'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_rar_sponsored', isset( $_POST['rar_sponsored'] ) ? '1' : '0' );
        // Save toggle states
        update_post_meta( $post_id, '_rar_geo_enabled', isset( $_POST['rar_geo_enabled'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_rar_rotation_enabled', isset( $_POST['rar_rotation_enabled'] ) ? '1' : '0' );
        // Save active state toggle
        update_post_meta( $post_id, '_rar_active', isset( $_POST['rar_active'] ) ? '1' : '0' );





       $rot = []; // Start with empty array for cleaned entries

        if ( isset( $_POST['rar_rotation_enabled'] ) && $_POST['rar_rotation_enabled'] === '1' ) {
            $raw_rotation = $_POST['rar_rotation'] ?? [];
        
            if ( is_array( $raw_rotation ) ) {
                $total_weight = 0;
        
                foreach ( $raw_rotation as $i => $entry ) {
                    // 🔍 Sanitize and validate input
                    $url_raw    = $entry['url'] ?? '';
                    $weight_raw = $entry['weight'] ?? '';
        
                    $url    = esc_url_raw( $url_raw );
                    $weight = filter_var( $weight_raw, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0, 'max_range' => 100 ] ] );
        
                    if ( $weight === false || $weight === 0 || ! $url ) {
                        continue; // Skip invalid rows
                    }
        
                    // 🔁 Force canonical target as the first URL
                    if ( $i === 0 ) {
                        $url = $canonical_target;
                    }
        
                    $rot[] = [
                        'url'    => $url,
                        'weight' => $weight
                    ];
        
                    $total_weight += $weight;
                }
        
                // 📏 Auto-adjust if only one valid entry
                if ( count( $rot ) === 1 ) {
                    $rot[0]['weight'] = 100;
                }
                // ❌ Reject malformed totals
                elseif ( $total_weight !== 100 ) {
                    add_filter( 'redirect_post_location', function( $location ) {
                        return add_query_arg( 'rar_rotation_error', 1, $location );
                    });
                    return; // Don't save bad config
                }
            }
        }
        
        update_post_meta( $post_id, '_rar_rotation', wp_json_encode( $rot ) );




		// GEO targeting
		$gm = []; // Start with empty array to hold valid geo rules

        if ( ! empty( $_POST['rar_geo'] ) && is_array( $_POST['rar_geo'] ) ) {
            foreach ( $_POST['rar_geo'] as $entry ) {
                $country_raw = $entry['country'] ?? '';
                $url_raw     = $entry['url']     ?? '';
        
                // ✳️ Basic sanitization
                $country = strtoupper( sanitize_text_field( $country_raw ) );
                $url     = esc_url_raw( $url_raw );
        
                // ✅ Validate ISO 3166 alpha-2 country codes (2 uppercase letters)
                if ( preg_match( '/^[A-Z]{2}$/', $country ) && ! empty( $url ) ) {
                    $gm[] = [
                        'country' => $country,
                        'url'     => $url
                    ];
                }
            }
        }
        
        update_post_meta( $post_id, '_rar_geo', wp_json_encode( $gm ) );

	}

        // ─── Show admin notice if rotation weight is invalid ──────────────
        public function admin_notice_rotation_error() {
        	if ( isset( $_GET['rar_rotation_error'] ) ) {
        		echo '<div class="notice notice-error"><p><strong>RAR Error:</strong> Weighted rotation must total exactly 100.</p></div>';
        	}
        }


	/*── Build vanity permalinks in admin ─────────────────*/
	public function filter_permalink( $link, $post ) {
		return ( $post->post_type === self::CPT )
			? home_url( '/' . $post->post_name . '/' )
			: $link;
	}

}  // End Class
