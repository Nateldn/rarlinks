<?php
/**
 * ASNResolver - Uses MaxMind GeoLite2 ASN database to get ISP/organization info.
 */

use GeoIp2\Database\Reader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASNResolver {

	/** @var Reader|null */
	private static $reader = null;

	/**
	 * Get the ASN organization name for a given IP address.
	 *
	 * @param string|null $ip IP address (defaults to REMOTE_ADDR)
	 * @return string|null ISP or organization name, or null if not found
	 */
	public static function get_organization( ?string $ip = null ): ?string {
		if ( ! $ip ) {
			$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		if ( ! self::$reader ) {
			$dbPath = plugin_dir_path( __FILE__ ) . '../geo/GeoLite2-ASN.mmdb';
			if ( file_exists( $dbPath ) ) {
				self::$reader = new Reader( $dbPath );
			} else {
				return null;
			}
		}

		try {
			$record = self::$reader->asn( $ip );
			return $record->autonomousSystemOrganization ?? null;
		} catch ( \Exception $e ) {
			return null;
		}
	}

    /**
     * Get the Autonomous System Number (ASN) for a given IP.
     *
     * @param string|null $ip IP address (defaults to REMOTE_ADDR)
     * @return int|null ASN, or null if not found
     */
    public static function get_asn_number( ?string $ip = null ): ?int {
        if ( ! $ip ) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return null;
        }

        if ( ! self::$reader ) {
            $dbPath = plugin_dir_path( __FILE__ ) . '../geo/GeoLite2-ASN.mmdb';
            if ( file_exists( $dbPath ) ) {
                self::$reader = new Reader( $dbPath );
            } else {
                return null;
            }
        }

        try {
            $record = self::$reader->asn( $ip );
            return $record->autonomousSystemNumber ?? null;
        } catch ( \Exception $e ) {
            return null;
        }
    }

    /**
     * Test method to verify ASN and Organization retrieval for a given IP.
     * Use for debugging purposes only.
     * @param string $ip The IP to test.
     */
    public static function test_resolver( string $ip ): void {
        error_log('[RAR TEST] Testing IP: ' . $ip);
        error_log('[RAR TEST] Organization: ' . (self::get_organization($ip) ?? 'null'));
        error_log('[RAR TEST] ASN Number: ' . (self::get_asn_number($ip) ?? 'null'));
    }
}