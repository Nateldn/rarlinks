<?php
/**
 * Renders the Reports tab on the RARLinks settings page.
 * Houses management features for the clicks report:
 * the Moto Partner list and the Bot Cleanup tool.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Settings_Reports {

    /**
     * Hook this tab's render to its settings-page action.
     */
    public static function init() {
        add_action( 'rar_settings_render_tab_reports', [ self::class, 'render' ] );
    }

    /**
     * Renders the Reports tab body.
     */
    public static function render() {
        // Moto Partner list: tells the bot-detection waterfall which links sit on the homepage.
        Cogito_RAR_Moto_Partner_List::render();

        // Bot Cleanup: review table + bulk delete for bot/unknown click rows.
        Cogito_RAR_Bot_Cleanup::render();
    }
}