<?php
/**
 * Daily-refreshed cloud/hosting-provider IP range feed, mirroring the
 * existing Spamhaus DROP/EDROP integration's shape (a local JSON cache
 * read at classify() time) but for a different threat model: not
 * confirmed-malicious netblocks, but the general-purpose datacenter
 * ranges (AWS, Google Cloud, Azure-scale providers, cheap VPS hosts,
 * etc.) that click-farm/bot-clicking scripts are commonly run from.
 *
 * That broader net means a real, non-trivial false-positive risk this
 * plugin's other IP feeds don't carry: a genuine human on a VPN or
 * corporate proxy that happens to egress through one of these providers
 * would also match. Spamhaus DROP/ASNDROP are safe to run unconditionally
 * because they're narrow, high-confidence, malicious-only lists; this one
 * is NOT that, so — unlike DROP — it's gated behind its own OFF-by-default
 * setting (OPTION_ENABLED) rather than always running. Nate should watch
 * Bot Report for a while after enabling it.
 *
 * Source: rezmoss/cloud-provider-ip-addresses on GitHub — CC0-licensed,
 * refreshed daily upstream, IPv4 CIDR lists per provider. IPv4 only (no
 * IPv6 support here), matching the existing Spamhaus DROP integration's
 * own scope — an IPv6 visitor is simply a no-op for this whole feature,
 * neither blocked nor allowed by it, so there's no asymmetric gap.
 *
 * Azure is deliberately excluded from the default provider list: its own
 * IPv4 CIDR count (~71,500) dwarfs every other provider combined and
 * skews toward large enterprise/SaaS customers rather than the cheap,
 * disposable VPS instances a click-farm script actually runs on — a poor
 * size-to-relevance tradeoff for a linear scan run on every click.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Datacenter_IP_Feed {

    const OPTION_ENABLED = 'rar_datacenter_ip_filtering_enabled';

    const CRON_HOOK = 'rar_datacenter_ip_refresh';

    const CACHE_FILE           = 'datacenter-ips.json';
    const PRIVATE_RELAY_CACHE  = 'apple-private-relay-ips.json';

    const BASE_URL = 'https://raw.githubusercontent.com/rezmoss/cloud-provider-ip-addresses/main/';

    /**
     * Provider key => path suffix, for the general-purpose datacenter
     * blocklist. Trivial to extend (e.g. add back 'azure' =>
     * 'azure/azure_ips_v4.txt') if the size tradeoff above ever changes.
     */
    const PROVIDERS = [
        'aws'          => 'aws/aws_ips_v4.txt',
        'googlecloud'  => 'googlecloud/googlecloud_ips_v4.txt',
        'cloudflare'   => 'cloudflare/cloudflare_ips_v4.txt',
        'digitalocean' => 'digitalocean/digitalocean_ips_v4.txt',
        'linode'       => 'linode/linode_ips_v4.txt',
        'vultr'        => 'vultr/vultr_ips_v4.txt',
        'oracle'       => 'oracle/oracle_ips_v4.txt',
        'hetzner'      => 'hetzner/hetzner_ips_v4.txt',
        'fastly'       => 'fastly/fastly_ips_v4.txt',
    ];

    const PRIVATE_RELAY_PATH = 'apple_private_relay/apple_private_relay_ips_v4.txt';

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

    private static function cache_path( $filename ) {
        return plugin_dir_path( __FILE__ ) . '../data/' . $filename;
    }

    /**
     * Refreshes both caches (the datacenter blocklist and the Apple
     * Private Relay allowlist). Each provider is fetched independently —
     * unlike Spamhaus DROP+EDROP (one conceptual list needing both halves
     * to be meaningful), these are genuinely separate sources, so one
     * provider having a bad day shouldn't stale out every other
     * provider's data too. Only a total wipeout (zero providers reachable)
     * leaves the existing cache untouched; anything else overwrites with
     * whatever succeeded, and failures are logged individually.
     */
    public static function refresh() {
        $ranges     = [];
        $any_failed = false;

        foreach ( self::PROVIDERS as $key => $path ) {
            $fetched = self::fetch_list( self::BASE_URL . $path );
            if ( false === $fetched ) {
                $any_failed = true;
                error_log( "[RAR] Datacenter IP feed refresh failed fetching '$key' — skipping it this run." );
                continue;
            }
            $ranges = array_merge( $ranges, $fetched );
        }

        if ( empty( $ranges ) ) {
            error_log( '[RAR] Datacenter IP feed refresh: every provider failed — keeping the existing cache.' );
        } else {
            self::write_cache( self::CACHE_FILE, array_values( array_unique( $ranges ) ) );
        }

        $private_relay = self::fetch_list( self::BASE_URL . self::PRIVATE_RELAY_PATH );
        if ( false === $private_relay ) {
            error_log( '[RAR] Apple Private Relay feed refresh failed — keeping the existing cache.' );
        } else {
            self::write_cache( self::PRIVATE_RELAY_CACHE, $private_relay );
        }

        unset( $any_failed ); // Only used for the log line above; nothing else needs it.
    }

    private static function write_cache( $filename, array $ranges ) {
        $dir = dirname( self::cache_path( $filename ) );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        file_put_contents( self::cache_path( $filename ), wp_json_encode( $ranges ) );
    }

    /**
     * Downloads and parses one plain-text CIDR-per-line file.
     *
     * @param string $url
     * @return string[]|false Array of CIDR strings, or false on failure.
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
            if ( preg_match( '#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $line ) ) {
                $ranges[] = $line;
            }
        }

        return $ranges;
    }

    /**
     * @return string[] Cached datacenter CIDR list, or [] before the first
     *                   successful refresh.
     */
    public static function load() {
        return self::load_cache( self::CACHE_FILE );
    }

    /**
     * @return string[] Cached Apple Private Relay CIDR list, or [] before
     *                   the first successful refresh.
     */
    public static function load_private_relay() {
        return self::load_cache( self::PRIVATE_RELAY_CACHE );
    }

    private static function load_cache( $filename ) {
        $path = self::cache_path( $filename );
        if ( ! file_exists( $path ) ) {
            return [];
        }
        $data = json_decode( (string) file_get_contents( $path ), true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Whether an IPv4 address falls inside any of the given CIDR ranges.
     * Same logic as Cogito_RAR_Spamhaus_Drop::matches() — duplicated
     * rather than shared, matching this codebase's existing convention of
     * separate, self-contained IP-feed classes (see ASNDROP vs DROP).
     *
     * @param string   $ip
     * @param string[] $ranges
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
