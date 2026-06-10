<?php
/**
 * Helper class to manage setting and retrieving the RAR UID cookie.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Cogito_RAR_SetCookie {

	/**
	 * Checks for rar_uid cookie, sets it if missing.
	 */
	public static function maybe_set_cookie() {
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
}
