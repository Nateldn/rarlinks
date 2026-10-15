<?php
/**
 * Shared list of known affiliate/ad networks for the Network field's
 * datalist suggestions and the admin list table's Network filter — one
 * source of truth rather than two copies that can drift apart.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Networks {

    const DEFAULT_NETWORKS = [
        'Amazon Associates',
        'Awin',
        'CJ Affiliate',
        'Impact',
        'Levanta',
        'PartnerStack',
        'Rakuten Advertising',
        'ShareASale',
        'Skimlinks',
        'Sovrn //Commerce',
        'Direct',
    ];

    /**
     * The hardcoded defaults, merged with every distinct value already
     * saved on an existing RARLink — so a free-text Network typed once
     * shows up as a suggestion from then on, even if it's not in the
     * hardcoded list. Filterable via 'rarlinks_network_options'.
     *
     * @return string[]
     */
    public static function options() {
        global $wpdb;

        $existing = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_rar_network' AND meta_value != ''"
        );

        $options = array_unique( array_merge( self::DEFAULT_NETWORKS, $existing ) );
        sort( $options, SORT_STRING | SORT_FLAG_CASE );

        return apply_filters( 'rarlinks_network_options', $options );
    }
}
