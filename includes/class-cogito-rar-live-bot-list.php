<?php
/**
 * The live bot list: user-grown signal blacklist for bot detection.
 *
 * This is the single owner of the rar_live_bot_list option. Signals (IP,
 * hostname, org, user agent) are appended by the "Flag as bot" action and
 * read by the click logger, which auto-flags future matching clicks. It is
 * the user-driven layer that sits on top of the plugin's shipped/hardcoded
 * detection lists.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Live_Bot_List {

    /**
     * The wp_options key holding the live bot list.
     * Structure: [ 'ip' => [...], 'hostname' => [...], 'org' => [...], 'ua' => [...] ]
     */
    const OPTION_KEY = 'rar_live_bot_list';

    /**
     * Maps signal keys to their columns in wp_rarlinks_clicks.
     */
    const SIGNAL_COLUMNS = [
        'ip'       => 'ip_address',
        'hostname' => 'hostname',
        'org'      => 'org',
        'ua'       => 'user_agent',
    ];

    /**
     * Returns the live bot list with every signal bucket guaranteed present.
     *
     * @return array
     */
    public static function get_list() {
        $defaults = [ 'ip' => [], 'hostname' => [], 'org' => [], 'ua' => [] ];
        return wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), $defaults );
    }

    /**
     * Appends the given signal types' values from a click row to the list.
     * Skips empty values and exact duplicates.
     *
     * @param object $row          The click row from wp_rarlinks_clicks.
     * @param array  $signal_types Whitelisted signal keys ('ip', 'hostname', 'org', 'ua').
     * @return array Signal types that were actually appended.
     */
    public static function add_signals( $row, $signal_types ) {
        if ( empty( $signal_types ) ) {
            return [];
        }

        $list  = self::get_list();
        $added = [];

        foreach ( $signal_types as $type ) {
            if ( ! isset( self::SIGNAL_COLUMNS[ $type ] ) ) {
                continue; // Unknown signal type — discard
            }

            $column = self::SIGNAL_COLUMNS[ $type ];
            $value  = trim( (string) $row->$column );

            // An empty value would match everything at detection time — never store it
            if ( '' === $value || in_array( $value, $list[ $type ], true ) ) {
                continue;
            }

            $list[ $type ][] = $value;
            $added[]         = $type;
        }

        if ( ! empty( $added ) ) {
            update_option( self::OPTION_KEY, $list );
        }

        return $added;
    }

    /**
     * Matches an incoming click's signals against the live bot list.
     *
     * Matching is EXACT (case-insensitive for hostname/org/ua), not substring:
     * stored values are whole row values, and substring matching would risk
     * over-blocking. The hardcoded pattern lists in the click logger remain
     * the place for substring/prefix patterns.
     *
     * @param string $ip_address The click's IP address.
     * @param string $hostname   The click's resolved PTR hostname.
     * @param string $org        The click's resolved organisation.
     * @param string $user_agent The click's user agent.
     * @return array|null [ 'type' => signal key, 'value' => matched value ] or null.
     */
    public static function match( $ip_address, $hostname, $org, $user_agent ) {
        $list = self::get_list();

        $incoming = [
            'ip'       => $ip_address,
            'hostname' => $hostname,
            'org'      => $org,
            'ua'       => $user_agent,
        ];

        foreach ( $incoming as $type => $value ) {
            $value = trim( (string) $value );
            if ( '' === $value || empty( $list[ $type ] ) ) {
                continue;
            }

            foreach ( $list[ $type ] as $blacklisted ) {
                // IP must match exactly; text signals match case-insensitively
                $hit = ( 'ip' === $type )
                    ? ( $value === $blacklisted )
                    : ( strcasecmp( $value, $blacklisted ) === 0 );

                if ( $hit ) {
                    return [ 'type' => $type, 'value' => $blacklisted ];
                }
            }
        }

        return null;
    }
}
