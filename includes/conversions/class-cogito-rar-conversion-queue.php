<?php
/**
 * Persistence layer for the conversions queue. No provider knowledge lives
 * here — rows store RAW signals (JSON), mapped to a provider's own shape
 * only at dispatch time. This is what keeps the queue provider-agnostic.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Conversion_Queue {

    const DB_VERSION        = '1.1';
    const DB_VERSION_OPTION = 'rar_conversion_queue_db_version';
    const MAX_ATTEMPTS      = 5;

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'rarlinks_conversions';
    }

    /**
     * Creates (or updates) the queue table. dbDelta is idempotent, so this
     * is safe to call unconditionally from maybe_upgrade().
     *
     * The created_at column's CURRENT_TIMESTAMP default below is
     * vestigial — enqueue() always sets it explicitly via PHP's gmdate()
     * instead, never relying on MySQL's default. That matters because
     * this host's DB session timezone (whatever CURRENT_TIMESTAMP would
     * actually use) isn't UTC, unlike sent_at/eligible_at, which are
     * always set via UTC-explicit calls (UTC_TIMESTAMP()/gmdate()).
     */
    public static function create_table() {
        global $wpdb;
        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(32) NOT NULL,
            event_name VARCHAR(64) NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'rarlink',
            post_id BIGINT UNSIGNED NULL,
            signals LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            eligible_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY status_eligible (status, eligible_at),
            KEY provider (provider),
            KEY post_id (post_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Idempotent: creates/upgrades the table if the stored version is
     * behind. Hooked on admin_init so it self-heals after a code deploy on
     * an already-active install, without requiring a deactivate/reactivate
     * cycle (register_activation_hook alone never re-fires on an update).
     */
    public static function maybe_upgrade() {
        $current = get_option( self::DB_VERSION_OPTION );
        if ( $current === self::DB_VERSION ) {
            return;
        }

        self::create_table();

        if ( '1.0' === $current ) {
            self::fix_created_at_timezone();
        }
    }

    /**
     * One-time correction for every row inserted under 1.0, whose
     * created_at came from MySQL's own CURRENT_TIMESTAMP default — this
     * host's DB session timezone for that is America/Los_Angeles, not UTC
     * (confirmed from the ~7-hour-off "Created" column Nate reported).
     * A fixed +7 hours is safe here specifically because this table is
     * brand new: every existing row was created within the last couple of
     * days, entirely within Pacific Daylight Time (UTC-7) — there are no
     * older rows that would have been on Pacific Standard Time (UTC-8)
     * needing a different offset. Deliberately NOT using MySQL's
     * CONVERT_TZ() with named zones: the mysql.time_zone_name tables it
     * needs are frequently empty on shared hosting (including, as far as
     * we know, this one), which would make it silently return NULL rather
     * than error — a fixed-hour shift has no such failure mode.
     */
    private static function fix_created_at_timezone() {
        global $wpdb;
        $wpdb->query( 'UPDATE ' . self::table_name() . ' SET created_at = created_at + INTERVAL 7 HOUR' );
    }

    /**
     * Queues one event for one provider.
     *
     * @param string $provider     Provider key, e.g. 'meta'.
     * @param string $event_name   e.g. 'AffiliateClick'.
     * @param array  $signals      Raw, provider-agnostic signal set (JSON-encoded as stored).
     * @param int|null $post_id    The RARLink, when source = 'rarlink'.
     * @param string $source       'rarlink' | 'raw_link'.
     * @param int    $hold_minutes Minutes before this row becomes eligible to send.
     * @return int Inserted row id.
     */
    public static function enqueue( $provider, $event_name, array $signals, $post_id = null, $source = 'rarlink', $hold_minutes = 0 ) {
        global $wpdb;

        $wpdb->insert(
            self::table_name(),
            [
                'provider'    => sanitize_key( $provider ),
                'event_name'  => sanitize_text_field( $event_name ),
                'source'      => sanitize_key( $source ),
                'post_id'     => $post_id ? (int) $post_id : null,
                'signals'     => wp_json_encode( $signals ),
                'status'      => 'pending',
                // Explicit, rather than relying on the column's own
                // CURRENT_TIMESTAMP default — see create_table()'s
                // docblock for why that default isn't trustworthy here.
                'created_at'  => gmdate( 'Y-m-d H:i:s' ),
                'eligible_at' => gmdate( 'Y-m-d H:i:s', time() + ( max( 0, (int) $hold_minutes ) * MINUTE_IN_SECONDS ) ),
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Status counts for the admin UI.
     *
     * @return array [ 'pending' => n, 'sent' => n, 'failed' => n, 'permanently_failed' => n ]
     */
    public static function get_counts() {
        global $wpdb;
        $table  = self::table_name();
        $rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM $table GROUP BY status", ARRAY_A );
        $counts = [ 'pending' => 0, 'sent' => 0, 'failed' => 0, 'permanently_failed' => 0 ];

        foreach ( (array) $rows as $row ) {
            if ( isset( $counts[ $row['status'] ] ) ) {
                $counts[ $row['status'] ] = (int) $row['c'];
            }
        }

        return $counts;
    }

    /**
     * Most recent rows, for the admin UI's activity table.
     */
    public static function get_recent( $limit = 20 ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit ) );
    }

    /**
     * Rows ready to attempt sending: pending, past their hold window,
     * for one specific provider. Never returns sent/failed/permanently_failed rows.
     */
    public static function get_eligible( $provider, $limit = 1000 ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table
             WHERE provider = %s AND status = 'pending' AND eligible_at <= UTC_TIMESTAMP()
             ORDER BY id ASC LIMIT %d",
            $provider,
            $limit
        ) );
    }

    /**
     * Marks rows as successfully sent.
     *
     * @param int[] $ids
     */
    public static function mark_sent( array $ids ) {
        if ( empty( $ids ) ) {
            return;
        }
        global $wpdb;
        $table        = self::table_name();
        $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET status = 'sent', sent_at = UTC_TIMESTAMP() WHERE id IN ($placeholders)",
            $ids
        ) );
    }

    /**
     * Corrects a row's stored signals (and event_name column alongside
     * them) once dispatch-time enrichment
     * (Cogito_RAR_Conversion_Click_Context::enrich()) has filled in
     * link_text/link_classes from the click listener's beacon and/or
     * resolved this click to a different event than the AffiliateClick
     * default it was queued under (e.g. AdvertisementClick) — otherwise
     * the admin UI would only ever show the enqueue-time snapshot, which
     * predates the beacon's data by design (see
     * Cogito_RAR_Conversion_Capture::build_meta_click_signals()).
     *
     * @param int   $id
     * @param array $signals The enriched signal set.
     */
    public static function update_signals( $id, array $signals ) {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            [
                'signals'    => wp_json_encode( $signals ),
                'event_name' => sanitize_text_field( $signals['event_name'] ?? '' ),
            ],
            [ 'id' => (int) $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Records a failed attempt. After MAX_ATTEMPTS the row stops retrying
     * (permanently_failed) so one bad event can't loop forever or block
     * newer ones behind it — mirrors the same reasoning as the historical
     * click re-scan's own retry ceiling elsewhere in this plugin.
     *
     * @param int    $id
     * @param string $error
     */
    public static function mark_failed( $id, $error ) {
        global $wpdb;
        $table    = self::table_name();
        $current  = $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM $table WHERE id = %d", $id ) );
        $attempts = ( null === $current ? 0 : (int) $current ) + 1;
        $status   = ( $attempts >= self::MAX_ATTEMPTS ) ? 'permanently_failed' : 'pending';

        $wpdb->update(
            $table,
            [
                'status'     => $status,
                'attempts'   => $attempts,
                'last_error' => sanitize_text_field( (string) $error ),
            ],
            [ 'id' => (int) $id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );
    }
}
