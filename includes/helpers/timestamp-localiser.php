<?php
/**
 * Helper function to localise UTC timestamps to Europe/London time.
 *
 * @package Cogito_RAR
  /**
     * Displays the timestamp in the stats report, converted from UTC timestamp to Europe/London time with DST awareness.
     *
     * @param string $timestamp UTC timestamp from the DB (e.g., '2025-05-27 15:35:07')
     * @return string Localized time (e.g., '27 May 2025 16:35:07')
     */


if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Displays the timestamp in the stats report, converted from UTC timestamp to Europe/London time with DST awareness.
 *
 * @param string $timestamp UTC timestamp from the DB (e.g., '2025-05-27 15:35:07')
 * @return string Localized time (e.g., '27 May 2025 16:35:07')
 */
function cogito_rar_localise_timestamp( $timestamp ) {
    // Source time (PST/PDT) - assuming your DB stores in America/Los_Angeles as per previous code.
    // If your database stores in UTC, change 'America/Los_Angeles' to 'UTC'.
    $dt = new DateTime( $timestamp, new DateTimeZone('America/Los_Angeles') ); 

    // Destination (GMT/BST)
    $dt->setTimezone( new DateTimeZone('Europe/London') ); 

    return $dt->format('d M Y H:i:s');
}