<?php
/**
 * Plugin Name: RARLinks
 * Description: Custom redirect management with vanity URLs, GEO targeting, and link rotation.
 * Version: 0.1.0
 * Author: Nate @ Renchlist
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// 🧰 Composer Autoload (for future dependency management)
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

// 🧠 Core Plugin Loader & Main Class
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cogito-loader.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cogito-rar.php';


// 🧭 ASN Resolver
require_once plugin_dir_path( __FILE__ ) . 'includes/class-asn-resolver.php';

// 🔗 Custom Post Type Registration
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cpt-registrar.php';


// 🎛 Meta Boxes
require_once plugin_dir_path( __FILE__ ) . 'includes/metaboxes/class-metabox-basic-fields.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/metaboxes/class-metabox-preview.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/metaboxes/class-metabox-rotation.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/metaboxes/class-metabox-geo.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/metaboxes/class-metabox-save.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/metaboxes/class-metaboxes.php';

// 🍪 Cookie Management Helper - Sets or retrieves the persistent RAR cookie for tracking visitors
require_once plugin_dir_path( __FILE__ ) . 'includes/helpers/class-cogito-rar-setcookie.php';
add_action( 'init', [ 'Cogito_RAR_SetCookie', 'maybe_set_cookie' ], 1 );

// Redirect logic engine for conditional redirect checks, GEO, rotation, fallback.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-redirect-engine.php';

// 📋 WP_List_Table for displaying click logs
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cogito-rar-clicks-list-table.php';

// ⏰ Timestamp Localisation Helper
require_once plugin_dir_path( __FILE__ ) . 'includes/helpers/timestamp-localiser.php';


// 📊 Stats Dashboard & Click Logging
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cogito-rar-dashboard.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cogito-rar-click-logger.php';

// 🚩 Live bot list (user-flagged signals; written by Flag-as-bot, read by the click logger)
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cogito-rar-live-bot-list.php';

// Dashboard Components
// New consolidated filters class
require_once plugin_dir_path( __FILE__ ) . 'includes/dashboard/class-dashboard-filters.php';

// 🚩 Flag-as-bot AJAX handler (clicks table row action)
require_once plugin_dir_path( __FILE__ ) . 'includes/dashboard/class-cogito-rar-flag-bot.php';
Cogito_RAR_Flag_Bot::init();

// 🗂 Bulk re-classification handler (clicks table bulk actions)
require_once plugin_dir_path( __FILE__ ) . 'includes/dashboard/class-cogito-rar-bulk-classify.php';
Cogito_RAR_Bulk_Classify::init();

// Moto Partner AJAX toggle handler
require_once plugin_dir_path( __FILE__ ) . 'includes/settings/class-cogito-rar-moto-partner-toggle.php';
Cogito_RAR_Moto_Partner_Toggle::init();

// Moto Partner list (rendered inside the Bot Filtering settings tab)
require_once plugin_dir_path( __FILE__ ) . 'includes/settings/class-cogito-rar-moto-partner-list.php';

// Bot Cleanup (Reports tab): review table, render, and bulk delete handler
require_once plugin_dir_path( __FILE__ ) . 'includes/settings/class-cogito-rar-bot-cleanup-table.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/settings/class-cogito-rar-bot-cleanup.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/settings/class-cogito-rar-bot-cleanup-actions.php';
Cogito_RAR_Bot_Cleanup_Actions::init();

// Reports settings tab
require_once plugin_dir_path( __FILE__ ) . 'includes/settings/class-cogito-rar-settings-reports.php';
Cogito_RAR_Settings_Reports::init();

// We can now optionally remove traffic-filter if no longer needed, but keeping it commented out for safety or reference if you prefer.
// require_once plugin_dir_path( __FILE__ ) . 'includes/dashboard/class-dashboard-traffic-filter.php'; 

Cogito_RAR_Dashboard::init(); 

// 📈 Chart Rendering (Chart.js + chart classes)
require_once plugin_dir_path( __FILE__ ) . 'includes/charts/cogito-rar-charts-loader.php';

// ⚙️ Settings Page (submenu)
require_once plugin_dir_path( __FILE__ ) . 'includes/settings/class-cogito-rar-settings-page.php';
Cogito_RAR_Settings_Page::init();

// 📥 RARLink Importer (submenu)
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cogito-rar-importer.php';
new Cogito_RAR_Importer();

// 🧱 Custom Admin Columns for RARLinks
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cogito-rar-admin-columns.php';
add_action( 'init', [ 'Cogito_RAR_Admin_Columns', 'init' ] );


/**
 * 🔁 Handles plugin activation tasks:
 * - Registers CPT rewrite rules
 * - Creates the click log table if it doesn't exist
 * - Flushes rewrite rules
 */
function cogito_rar_on_activate() {
	Cogito_RAR_CPT_Registrar::register_cpt_static();
	cogito_rar_create_click_log_table();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cogito_rar_on_activate' );



/**
 * 🔄 Creates the wp_rarlinks_clicks table to store basic click logs.
 * Stores post ID, post title, timestamp, referrer, and user agent.
 * Triggered automatically on plugin activation.
 */
function cogito_rar_create_click_log_table() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'rarlinks_clicks';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	post_id BIGINT UNSIGNED NOT NULL,
	post_title TEXT NOT NULL,
	timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	referrer TEXT NULL,
	user_agent TEXT NULL,
	ip_address VARCHAR(45) NULL,
	hostname TEXT NULL,
	visitor_id VARCHAR(64) NULL,
	org TEXT NULL,
	bot_name VARCHAR(255) NULL,
	bot_or_not TINYINT(1) DEFAULT 0,
	PRIMARY KEY (id),
	KEY post_id (post_id)
) $charset_collate;";



	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

    // /**
    //  * 🧱 Ensures new columns exist on plugin update.
    //  * Adds 'ip_address' and 'hostname' columns if missing.
    //  */
    // function cogito_rar_maybe_upgrade_click_log_table() {
    // 	global $wpdb;
    // 	$table_name = $wpdb->prefix . 'rarlinks_clicks';
    
    // 	// Check and add ip_address column
    // 	$ip_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'ip_address'");
    // 	if (empty($ip_exists)) {
    // 		$wpdb->query("ALTER TABLE $table_name ADD ip_address VARCHAR(45) NULL");
    // 	}
    
    // 	// Check and add hostname column
    // 	$host_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'hostname'");
    // 	if (empty($host_exists)) {
    // 		$wpdb->query("ALTER TABLE $table_name ADD hostname TEXT NULL");
    // 	}
    // }
    // add_action( 'plugins_loaded', 'cogito_rar_maybe_upgrade_click_log_table' );

/**
 * 🛠️ One-time update to add 'visitor_id' column if it doesn't exist.
 */
// function cogito_rar_maybe_add_visitor_id_column() {
// 	global $wpdb;
// 	$table_name = $wpdb->prefix . 'rarlinks_clicks';

// 	$exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'visitor_id'");
// 	if ( empty( $exists ) ) {
// 		$wpdb->query("ALTER TABLE $table_name ADD visitor_id VARCHAR(64) NULL");
// 	}
// }
// add_action( 'plugins_loaded', 'cogito_rar_maybe_add_visitor_id_column' );




// Finally, run the plugin
function cogito_rar_init() {
	$loader = new Cogito_Loader();
	$plugin = new Cogito_RAR( $loader );
	$plugin->run();
    // Initialize Meta Box Save Logic.
    // This will register Cogito_RAR_Metabox_Save::handle to 'save_post'
    // and Cogito_RAR_Metabox_Save::admin_notice_rotation_error to 'admin_notices'.
    Cogito_RAR_Metabox_Save::init();

    // Initialize CPT Registrar
    add_action( 'init', [ 'Cogito_RAR_CPT_Registrar', 'init' ] );
    // Initialize Central Meta Boxes handler
    add_action( 'admin_menu', [ 'Cogito_RAR_Metaboxes', 'init' ] );
}
add_action( 'plugins_loaded', 'cogito_rar_init' );