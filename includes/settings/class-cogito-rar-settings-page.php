<?php
/**
 * Registers the RARLinks settings page and handles tab routing.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Settings_Page {

    /**
     * Hook the submenu registration and asset enqueuing.
     */
    public static function init() {
        add_action( 'admin_menu', [ self::class, 'add_settings_page' ], 11 );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    /**
     * Add the Settings submenu under the RARLinks menu.
     * Priority 11 + array order places it beneath "Clicks Report".
     */
    public static function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=rar_redirect',
            'RARLinks Settings',
            'Settings',
            'manage_options',
            'rar_settings',
            [ self::class, 'render' ]
        );
    }

    /**
     * Enqueues the settings-page script, only on the RARLinks settings screen.
     *
     * @param string $hook The current admin page hook suffix.
     */
    public static function enqueue_assets( $hook ) {
        // Only load on our settings page. The hook suffix for a submenu under
        // edit.php?post_type=rar_redirect is 'rar_redirect_page_rar_settings'.
        // Shared across the three admin pages built from what used to be
        // one tabbed Settings screen: this page (Defaults), CAPI (the
        // Tracked Events repeater) and Bot Report (Moto Partner list +
        // Bot Cleanup + Re-scan).
        $our_hooks = [
            'rar_redirect_page_rar_settings',
            'rar_redirect_page_' . Cogito_RAR_Settings_Conversions::PAGE_SLUG,
            'rar_redirect_page_' . Cogito_RAR_Settings_Reports::PAGE_SLUG,
        ];
        if ( ! in_array( $hook, $our_hooks, true ) ) {
            return;
        }

        wp_enqueue_script(
            'cogito-rar-settings',
            // plugin_dir_url() of includes/ resolves to the plugin root URL.
            // (plugins_url() with a bare directory path dropped the plugin
            // folder from the URL, 404ing the script — fixed.)
            plugin_dir_url( dirname( __FILE__, 2 ) ) . 'assets/js/cogito-rar-settings.js',
            [],
            // File modification time as version: busts caches on every change
            filemtime( dirname( __FILE__, 3 ) . '/assets/js/cogito-rar-settings.js' ),
            true // Load in footer
        );
    }

    /**
     * Defines the available tabs as slug => label. Reports (now the
     * standalone Bot Report page) and Conversions (now the standalone
     * CAPI page) no longer live here.
     */
    private static function get_tabs() {
        return [
            'defaults' => 'Defaults',
        ];
    }

    /**
     * Renders the settings page shell: heading, tab nav, and active tab body.
     */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tabs       = self::get_tabs();
        // Determine active tab (default to first)
        $active_tab = isset( $_GET['tab'] ) && array_key_exists( $_GET['tab'], $tabs )
            ? sanitize_key( $_GET['tab'] )
            : array_key_first( $tabs );

        echo '<div class="wrap">';
        echo '<h1>RARLinks Settings</h1>';

        // Tab navigation
        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $slug => $label ) {
            $url   = add_query_arg( [
                'post_type' => 'rar_redirect',
                'page'      => 'rar_settings',
                'tab'       => $slug,
            ], admin_url( 'edit.php' ) );
            $class = ( $slug === $active_tab ) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . $class . '">' . esc_html( $label ) . '</a>';
        }
        echo '</h2>';

        // Active tab body — each tab renders its own content
        echo '<div class="rar-settings-body">';
        do_action( 'rar_settings_render_tab_' . $active_tab );
        echo '</div>';

        echo '</div>'; // .wrap
    }
}