<?php
/**
 * Helper class to manage setting and retrieving the RAR UID cookie.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Cogito_RAR_SetCookie {

	/**
	 * Whether a valid rar_uid cookie existed when the request ARRIVED,
	 * recorded before anything overwrites $_COOKIE. A visitor who has
	 * loaded any site page carries the cookie; a bot replaying a harvested
	 * redirect URL does not — a key bot-detection signal.
	 *
	 * @var bool|null Null until first checked.
	 */
	private static $was_present = null;

	/**
	 * Checks for rar_uid cookie, sets it if missing.
	 */
	public static function maybe_set_cookie() {
		// Record the arrival state FIRST — everything below mutates $_COOKIE
		self::was_present();

		if ( headers_sent() ) return;

		if ( empty( $_COOKIE['rar_uid'] ) || ! preg_match( '/^[a-f0-9]{32}$/', $_COOKIE['rar_uid'] ) ) {
			// 🎲 Generate a cryptographically secure 32-character hex token
			$uid = bin2hex( random_bytes(16) );

			setcookie(
				'rar_uid',
				$uid,
				[
					'expires' => time() + 15552000, // 180 days / 6 months
					'path'     => COOKIEPATH,
					'domain'   => COOKIE_DOMAIN,
					'secure'   => true,
					'httponly' => false,
					'samesite' => 'None',
				]
			);

			$_COOKIE['rar_uid'] = $uid;
		}
	}

	/**
	 * Returns the current UID from the cookie, or empty string.
	 *
	 * @return string
	 */
	public static function get() {
		return sanitize_text_field( $_COOKIE['rar_uid'] ?? '' );
	}

	/**
	 * Whether a valid rar_uid cookie arrived with this request.
	 * Lazily computed on first call, so it is accurate even if
	 * maybe_set_cookie() never ran — provided it is called before
	 * any code writes to $_COOKIE['rar_uid'].
	 *
	 * @return bool
	 */
	public static function was_present() {
		if ( null === self::$was_present ) {
			self::$was_present = ! empty( $_COOKIE['rar_uid'] )
				&& (bool) preg_match( '/^[a-f0-9]{32}$/', $_COOKIE['rar_uid'] );
		}
		return self::$was_present;
	}
}
