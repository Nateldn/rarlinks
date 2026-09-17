<?php
/**
 * Bot Report: the Moto Partner list, the Bot Cleanup tool, and Re-scan.
 * Its own admin page rather than a Settings tab — reached via a link from
 * the Clicks Report page, not the persistent RARLinks submenu (the
 * add_submenu_page() call below passes an empty menu title so nothing
 * shows there), since it's a debugging tool for that report, not a
 * setting.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Settings_Reports {

    const PAGE_SLUG = 'rar_bot_report';

    public static function init() {
        add_action( 'admin_menu', [ self::class, 'add_page' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_filter( 'set-screen-option', [ self::class, 'save_screen_option' ], 10, 3 );
    }

    public static function page_url() {
        return add_query_arg(
            [ 'post_type' => 'rar_redirect', 'page' => self::PAGE_SLUG ],
            admin_url( 'edit.php' )
        );
    }

    /**
     * Registers the page with an empty menu title, so it's reachable by
     * URL (and from the link on Clicks Report) without adding its own
     * entry to the RARLinks submenu.
     */
    public static function add_page() {
        $hook = add_submenu_page(
            'edit.php?post_type=rar_redirect',
            'Bot Report',
            '',
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render' ]
        );

        add_action( "load-$hook", [ self::class, 'add_screen_options' ] );
    }

    /**
     * Registers the "Bot rows per page" Screen Option. The Bot Cleanup
     * table reads this value via get_items_per_page( 'rar_bot_cleanup_per_page' ).
     */
    public static function add_screen_options() {
        add_screen_option( 'per_page', [
            'label'   => 'Bot rows per page',
            'default' => 100,
            'option'  => 'rar_bot_cleanup_per_page',
        ] );
    }

    /**
     * Saves the per-page value (WP discards it unless a filter returns it).
     */
    public static function save_screen_option( $status, $option, $value ) {
        return ( 'rar_bot_cleanup_per_page' === $option ) ? (int) $value : $status;
    }

    /**
     * Chart.js + the shared chart renderer, fed bot/unknown click data for
     * the spike-spotting line graph on Bot Cleanup.
     *
     * @param string $hook The current admin page hook suffix.
     */
    public static function enqueue_assets( $hook ) {
        if ( $hook !== 'rar_redirect_page_' . self::PAGE_SLUG ) {
            return;
        }

        if ( ! class_exists( 'Cogito_RAR_Line_Chart' ) || ! class_exists( 'Cogito_RAR_Bot_Cleanup_Filters' ) ) {
            return;
        }

        wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true );
        wp_enqueue_script(
            'rar-charts-js',
            plugin_dir_url( dirname( __FILE__, 2 ) ) . 'includes/charts/js/rar-charts.js',
            [ 'chartjs' ],
            filemtime( dirname( __FILE__, 3 ) . '/includes/charts/js/rar-charts.js' ),
            true
        );

        // Same filters as the table, so the chart and rows agree
        $line = Cogito_RAR_Line_Chart::get_data( Cogito_RAR_Bot_Cleanup_Filters::get_filters() );
        wp_localize_script( 'rar-charts-js', 'rarChartData', [ 'line' => $line ] );
    }

    /**
     * Renders the Bot Report page.
     */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>Bot Report</h1>';

        // Moto Partner list: tells the bot-detection waterfall which links sit on the homepage.
        Cogito_RAR_Moto_Partner_List::render();

        // Bot Cleanup: review table + bulk delete for bot/unknown click rows.
        Cogito_RAR_Bot_Cleanup::render();

        // Re-scan: re-run current detection rules over historical clicks.
        Cogito_RAR_Rescan::render();

        echo '</div>'; // .wrap
    }
}
