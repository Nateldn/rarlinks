<?php
/**
 * GeoResolver - Uses MaxMind GeoLite2 to get visitor country.
 */

use GeoIp2\Database\Reader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GeoResolver {

	/** @var Reader|null */
	private static $reader = null;

	/**
	 * Get the ISO country code for a given IP.
	 *
	 * @param string|null $ip IP address (optional — defaults to REMOTE_ADDR)
	 * @return string|null 2-letter country code, or null if unavailable
	 */
	public static function get_country( ?string $ip = null ): ?string {
		if ( ! $ip ) {
			$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		}

		if ( ! self::$reader ) {
			$dbPath = plugin_dir_path( __FILE__ ) . '../geo/GeoLite2-Country.mmdb';
			if ( file_exists( $dbPath ) ) {
				self::$reader = new Reader( $dbPath );
			} else {
				return null;
			}
		}

		try {
			$record = self::$reader->country( $ip );
			return strtoupper( $record->country->isoCode );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
