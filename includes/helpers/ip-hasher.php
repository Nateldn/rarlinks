<?php
/**
 * One-way IP address hashing for storage. A click's IP is kept raw at
 * insert time — Bot Cleanup and Clicks Report both show it for near-term
 * manual review (WHOIS lookups, deciding whether to flag a click) — and
 * later redacted in place by Cogito_RAR_Retention::redact_old_ips() once
 * a row is old enough that operational review no longer needs it, well
 * before the row is eventually deleted outright by the same cron.
 *

 * Salted with wp_salt() so the stored hash can't be reversed by
 * brute-forcing every possible IPv4/IPv6 address against a plain SHA-256
 * (a small enough space — ~4.3 billion for IPv4 alone — to be practical
 * without a secret salt). This deliberately does NOT need to survive a
 * security-key rotation: unlike a returning-visitor identity cookie,
 * nothing here ever looks up a row BY its hash to recognise a repeat IP —
 * each row is already independently addressed by its own primary key, so
 * the hash exists purely for at-rest anonymisation, not matching.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @param string $ip Raw IPv4 or IPv6 address. Blank input returns blank —
 *                    there's nothing to anonymise, and hashing an empty
 *                    string would just produce a false sense of privacy
 *                    around a value that was never captured anyway.
 * @return string 64-character hex SHA-256 digest, or '' if $ip is blank.
 */
function cogito_rar_hash_ip( $ip ) {
    $ip = trim( (string) $ip );
    if ( '' === $ip ) {
        return '';
    }
    return hash( 'sha256', $ip . wp_salt( 'auth' ) );
}
