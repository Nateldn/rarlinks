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
     * Hook the submenu registration.
     */
    public static function init() {
        add_action( 'admin_menu', [ self::class, 'add_settings_page' ], 11 );
    }

    /**
     * Add the Settings submenu under the RARLinks menu.
     * Priority 11 + array order places it beneath "View Clicks".
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
     * Defines the available tabs as slug => label.
     */
    private static function get_tabs() {
        return [
            'defaults'     => 'Defaults',
            'tracking'     => 'Tracking',
            'bot_filtering'=> 'Bot Filtering',
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

        // Active tab body — each tab renders its own content (added next)
        echo '<div class="rar-settings-body">';
        do_action( 'rar_settings_render_tab_' . $active_tab );
        echo '</div>';

        echo '</div>'; // .wrap
    }
}