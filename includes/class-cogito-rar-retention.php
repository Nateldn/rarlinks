<?php
/**
 * Daily housekeeping: purges old click-log rows past their retention
 * window, and old conversion-queue rows that have already been fully
 * resolved (sent, or given up on). Two long-parked tasks bundled together
 * since they're the same kind of work — a scheduled DELETE against a
 * table that only ever grows otherwise.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Retention {

    const CRON_HOOK = 'rar_retention_daily';

    /**
     * Days of click-log history to keep. Configurable (see the Defaults
     * settings tab); 180 matches the rar_uid visitor cookie's own
     * lifetime, so a click never outlives the visitor identifier it's
     * tied to.
     */
    const OPTION_CLICKS_RETENTION_DAYS  = 'rar_clicks_retention_days';
    const DEFAULT_CLICKS_RETENTION_DAYS = 180;

    /**
     * Days a click-log row keeps its real IP address before it's
     * irreversibly hashed in place (see cogito_rar_hash_ip()) — well
     * before the row itself is eventually deleted outright, above. Kept
     * deliberately short: Clicks Report and Bot Cleanup both show the IP
     * for near-term manual review (WHOIS lookups, deciding whether to
     * flag a click), but there's no ongoing operational reason to keep it
     * readable for the row's entire retention window.
     */
    const OPTION_IP_REDACTION_DAYS  = 'rar_ip_redaction_days';
    const DEFAULT_IP_REDACTION_DAYS = 30;

    /**
     * Conversion-queue rows are an audit trail, not analytics data — kept
     * far shorter, and only once fully resolved. A 'pending' or 'failed'
     * (still retrying) row is NEVER purged regardless of age; only
     * 'sent' and 'permanently_failed' rows age out. Not user-configurable:
     * there's no real reason to tune this, unlike click-log retention.
     */
    const CONVERSIONS_RETENTION_DAYS = 90;

    public static function init() {
        add_action( self::CRON_HOOK, [ self::class, 'run' ] );
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

    public static function run() {
        self::redact_old_ips();
        self::purge_old_clicks();
        self::purge_old_conversion_rows();
    }

    /**
     * Irreversibly hashes ip_address in place for click-log rows past the
     * redaction window but not yet old enough to be deleted outright by
     * purge_old_clicks() below. A row's IP is only ever real-looking
     * (contains '.' or ':') before this runs on it — a SHA-256 hex digest
     * contains neither — so an already-redacted row is naturally skipped
     * on every subsequent daily run, no separate flag needed.
     *
     * Batched (5000/run) rather than done in one pass: this is brand new,
     * so the first run after deploying it could otherwise face the whole
     * table's worth of already-eligible rows at once. Anything left over
     * just gets picked up on the next day's run.
     */
    private static function redact_old_ips() {
        global $wpdb;

        $days   = max( 1, (int) get_option( self::OPTION_IP_REDACTION_DAYS, self::DEFAULT_IP_REDACTION_DAYS ) );
        $table  = $wpdb->prefix . 'rarlinks_clicks';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, ip_address FROM $table
             WHERE timestamp < %s AND ( ip_address LIKE '%%.%%' OR ip_address LIKE '%%:%%' )
             LIMIT 5000",
            $cutoff
        ) );

        foreach ( $rows as $row ) {
            $wpdb->update(
                $table,
                [ 'ip_address' => cogito_rar_hash_ip( $row->ip_address ) ],
                [ 'id' => (int) $row->id ],
                [ '%s' ],
                [ '%d' ]
            );
        }
    }

    private static function purge_old_clicks() {
        global $wpdb;

        $days   = max( 1, (int) get_option( self::OPTION_CLICKS_RETENTION_DAYS, self::DEFAULT_CLICKS_RETENTION_DAYS ) );
        $table  = $wpdb->prefix . 'rarlinks_clicks';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE timestamp < %s", $cutoff ) );
    }

    private static function purge_old_conversion_rows() {
        if ( ! class_exists( 'Cogito_RAR_Conversion_Queue' ) ) {
            return;
        }

        global $wpdb;
        $table  = Cogito_RAR_Conversion_Queue::table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::CONVERSIONS_RETENTION_DAYS * DAY_IN_SECONDS ) );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE status IN ('sent', 'permanently_failed') AND created_at < %s",
            $cutoff
        ) );
    }
}
