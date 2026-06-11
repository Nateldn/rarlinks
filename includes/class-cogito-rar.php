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


    // $loader->add_action( 'add_meta_boxes',          $this, 'add_meta_box' );
    // $loader->add_action( 'save_post',               $this, 'save_meta', 10, 2 );
    //$loader->add_filter( 'post_type_link',          $this, 'filter_permalink', 10, 2 );
    $loader->add_action( 'admin_enqueue_scripts',   $this, 'enqueue_admin_assets' );
    // $loader->add_action( 'admin_notices',           $this, 'admin_notice_rotation_error' );
    add_action( 'template_redirect', [ 'Cogito_RAR_Redirect_Engine', 'maybe_redirect' ], 5 );

    }

    
    // Enqueue Admin CSS
    public function enqueue_admin_assets() {
        wp_enqueue_style(
                'rar-admin-css',
                plugin_dir_url( __FILE__ ) . '../assets/css/admin.css',
                [],
                // File modification time as version: changing the file on disk
                // busts browser/Cloudflare caches automatically
                filemtime( plugin_dir_path( __FILE__ ) . '../assets/css/admin.css' )
            );

        // Enqueue the consolidated admin meta box JS file
        wp_enqueue_script(
            'rar-admin-metabox-js', // Changed handle
            plugin_dir_url( __FILE__ ) . '../assets/js/rar-admin-metabox.js', // Changed filename
            ['jquery'], // Depends on jQuery
            filemtime( plugin_dir_path( __FILE__ ) . '../assets/js/rar-admin-metabox.js' ),
            true // Load in footer
           );

        // Enqueue dashboard UI JS (filter toggles, custom date range)
        wp_enqueue_script(
            'rar-dashboard-ui-js',
            plugin_dir_url( __FILE__ ) . '../includes/dashboard/js/rar-dashboard-ui.js',
            [ 'jquery' ],
            filemtime( plugin_dir_path( __FILE__ ) . 'dashboard/js/rar-dashboard-ui.js' ),
            true
           );

    }


        
    // 🔧 Kick off plugin hooks and filters via the loader

	public function run() {
		$this->loader->run();
	}
		
	

}  // End Class
