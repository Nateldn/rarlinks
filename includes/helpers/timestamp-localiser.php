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

/**
 * Same idea, for timestamps that are ALREADY stored in UTC (e.g. the
 * conversions queue table, which uses gmdate()/UTC_TIMESTAMP() throughout)
 * rather than the clicks table's America/Los_Angeles convention above —
 * using the wrong source zone would silently produce a time off by
 * several hours instead of no conversion at all.
 *
 * @param string $timestamp A UTC MySQL DATETIME string.
 * @return string
 */
function cogito_rar_localise_utc_timestamp( $timestamp ) {
    $dt = new DateTime( $timestamp, new DateTimeZone( 'UTC' ) );
    $dt->setTimezone( new DateTimeZone( 'Europe/London' ) );
    return $dt->format( 'd M Y H:i:s' );
}