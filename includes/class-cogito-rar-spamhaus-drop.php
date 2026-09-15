<?php
/**
 * Daily-refreshed Spamhaus DROP/EDROP IP range feed — mirrors the existing
 * Spamhaus ASNDROP integration (a local cache read at classify() time), but
 * for IP ranges rather than ASNs. DROP/EDROP are small, high-confidence,
 * authoritative lists of netblocks controlled by professional cybercrime
 * operations — not a general residential-proxy/VPN blocklist, so false
 * positives on real visitors should be effectively nonexistent.
 *
 * Note: this complements, not replaces, the referrer+cookie waterfall and
 * the live bot list — a residential-proxy attack (rented consumer IPs)
 * appears on NO IP reputation feed by design.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Spamhaus_Drop {

    const CRON_HOOK  = 'rar_spamhaus_drop_refresh';
    const CACHE_FILE = 'spamhaus-drop.json';
    const DROP_URL   = 'https://www.spamhaus.org/drop/drop.txt';
    const EDROP_URL  = 'https://www.spamhaus.org/drop/edrop.txt';

    public static function init() {
        add_action( self::CRON_HOOK, [ self::class, 'refresh' ] );
        add_action( 'init', [ self::class, 'maybe_schedule' ] );
    }

    public static function maybe_schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    private static function cache_path() {
        return plugin_dir_path( __FILE__ ) . '../data/' . self::CACHE_FILE;
    }

    /**
     * Downloads DROP + EDROP and overwrites the local cache — but only if
     * BOTH fetches succeed. A stale-but-valid cache is far safer than a
     * partial or empty one, so any failure leaves the existing file
     * completely untouched.
     */
    public static function refresh() {
        $ranges = [];

        foreach ( [ self::DROP_URL, self::EDROP_URL ] as $url ) {
            $fetched = self::fetch_list( $url );
            if ( false === $fetched ) {
                error_log( '[RAR] Spamhaus DROP/EDROP refresh failed fetching ' . $url . ' — keeping the existing cache.' );
                return;
            }
            $ranges = array_merge( $ranges, $fetched );
        }

        $ranges = array_values( array_unique( $ranges ) );
        $dir    = dirname( self::cache_path() );

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        file_put_contents( self::cache_path(), wp_json_encode( $ranges ) );
    }

    /**
     * Downloads and parses one Spamhaus DROP-format list — plain text,
     * one CIDR per line as "1.10.16.0/20 ; SBL401265" (comments start
     * with ";"). This format has been stable for over a decade.
     *
     * @param string $url
     * @return string[]|false Array of CIDR strings, or false on any
     *                        failure (network error, non-200, empty body).
     */
    private static function fetch_list( $url ) {
        $response = wp_remote_get( $url, [
            'timeout'    => 30,
            'user-agent' => 'RARLinks/1.0 (+' . home_url() . ')',
        ] );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' === trim( $body ) ) {
            return false;
        }

        $ranges = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
            $line = trim( $line );
            if ( '' === $line || ';' === substr( $line, 0, 1 ) ) {
                continue;
            }
            // The CIDR is everything before the first ";" (the SBL reference).
            $cidr = trim( strtok( $line, ';' ) );
            if ( preg_match( '#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $cidr ) ) {
                $ranges[] = $cidr;
            }
        }

        return $ranges;
    }

    /**
     * Loads the cached CIDR list. Returns [] before the first successful
     * refresh (or if every refresh has ever failed) — the classify() step
     * that reads this simply has nothing to match against yet, exactly
     * like the ASNDROP check when asndrop.json is missing.
     *
     * @return string[]
     */
    public static function load() {
        $path = self::cache_path();
        if ( ! file_exists( $path ) ) {
            return [];
        }
        $data = json_decode( (string) file_get_contents( $path ), true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Whether an IPv4 address falls inside any of the given CIDR ranges.
     * IPv6 always returns false — DROP/EDROP are IPv4-only lists (their
     * IPv6 counterpart, DROPv6, is separate and not pulled in here).
     *
     * @param string   $ip
     * @param string[] $ranges Pass in the already-loaded list, so a caller
     *                         checking many IPs in one request only loads once.
     * @return bool
     */
    public static function matches( $ip, array $ranges ) {
        if ( empty( $ranges ) || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            return false;
        }

        $ip_long = ip2long( $ip );
        if ( false === $ip_long ) {
            return false;
        }

        foreach ( $ranges as $cidr ) {
            $parts = explode( '/', $cidr );
            if ( 2 !== count( $parts ) ) {
                continue;
            }
            [ $subnet, $bits ] = $parts;
            $bits        = (int) $bits;
            $subnet_long = ip2long( $subnet );

            if ( false === $subnet_long || $bits < 0 || $bits > 32 ) {
                continue;
            }

            $mask = ( 0 === $bits ) ? 0 : ( -1 << ( 32 - $bits ) );
            if ( ( $ip_long & $mask ) === ( $subnet_long & $mask ) ) {
                return true;
            }
        }

        return false;
    }
}
