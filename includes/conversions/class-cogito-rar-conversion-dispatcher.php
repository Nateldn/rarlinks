<?php
/**
 * Flushes eligible queued conversion events to their provider. Shared by
 * the manual "Flush Now" button today and a cron job later — the schedule
 * is just something that calls flush_all() on a timer; the logic itself
 * doesn't change.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Conversion_Dispatcher {

    /**
     * Stay safely inside a provider's event-time acceptance window (Meta:
     * 7 days) rather than attempting right up to the edge.
     */
    const STALE_DAYS = 6.5;

    const CRON_HOOK = 'rar_conversions_cron_dispatch';

    /**
     * Registers the automatic dispatch schedule. Events are meant to go out
     * as soon as they occur, not sit waiting for someone to click "Flush
     * Now" — WP-Cron only checks for due events on incoming page requests
     * (there's no true background process), so on a quiet site the actual
     * delay depends on traffic, but on a live site this is effectively
     * immediate.
     */
    public static function init() {
        add_filter( 'cron_schedules', [ self::class, 'add_cron_schedule' ] );
        add_action( self::CRON_HOOK, [ self::class, 'flush_all' ] );
        add_action( 'init', [ self::class, 'maybe_schedule' ] );
    }

    public static function add_cron_schedule( $schedules ) {
        $schedules['rar_conversions_minute'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display'  => 'Every minute (RARLinks Conversions dispatch)',
        ];
        return $schedules;
    }

    public static function maybe_schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'rar_conversions_minute', self::CRON_HOOK );
        }
    }

    /**
     * Called on plugin deactivation so a disabled plugin doesn't leave a
     * cron event still firing (and failing, since its hook's callback would
     * no longer be registered).
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Flushes every enabled provider's eligible queue.
     *
     * @return array Per-provider summary: [ 'meta' => [ 'sent'=>n, 'failed'=>n, ... ] ]
     */
    public static function flush_all() {
        $summary = [];

        foreach ( Cogito_RAR_Conversion_Providers::all() as $provider ) {
            if ( ! $provider->is_enabled() ) {
                continue;
            }
            $summary[ $provider->get_key() ] = self::flush_provider( $provider );
        }

        return $summary;
    }

    /**
     * @param Cogito_RAR_Conversion_Provider $provider
     * @return array [ 'sent'=>n, 'failed'=>n, 'skipped_stale'=>n, 'skipped_reclassified'=>n ]
     */
    private static function flush_provider( $provider ) {
        $result = [ 'sent' => 0, 'failed' => 0, 'skipped_stale' => 0, 'skipped_reclassified' => 0 ];
        $rows   = Cogito_RAR_Conversion_Queue::get_eligible( $provider->get_key(), 1000 );

        if ( empty( $rows ) ) {
            return $result;
        }

        $to_send      = []; // [ row_id => mapped_payload ]
        $stale_cutoff = time() - ( self::STALE_DAYS * DAY_IN_SECONDS );

        foreach ( $rows as $row ) {
            $signals = json_decode( (string) $row->signals, true );
            $signals = is_array( $signals ) ? $signals : [];

            if ( (int) ( $signals['click_time'] ?? 0 ) < $stale_cutoff ) {
                // Too old for the provider's event-time window — give up
                // rather than let one ancient row block newer ones forever.
                Cogito_RAR_Conversion_Queue::mark_failed( $row->id, "Stale: older than the provider's event-time window" );
                $result['skipped_stale']++;
                continue;
            }

            if ( self::now_looks_like_a_bot( $signals ) ) {
                // The free safety net: re-run the SAME detection waterfall
                // with CURRENT data (live bot list etc.), not just the
                // click-time snapshot. Catches burst-fraud siblings flagged
                // after this row was queued — zero ongoing effort required.
                Cogito_RAR_Conversion_Queue::mark_failed( $row->id, 'Reclassified as bot/unknown before dispatch — not sent' );
                $result['skipped_reclassified']++;
                continue;
            }

            $to_send[ $row->id ] = $provider->map_payload( $signals );
        }

        if ( empty( $to_send ) ) {
            return $result;
        }

        $ids      = array_keys( $to_send );
        $payloads = array_values( $to_send );
        $outcomes = $provider->send_batch( $payloads );

        foreach ( $ids as $i => $id ) {
            $outcome = $outcomes[ $i ] ?? [ 'success' => false, 'error' => 'No response from provider' ];

            if ( ! empty( $outcome['success'] ) ) {
                Cogito_RAR_Conversion_Queue::mark_sent( [ $id ] );
                $result['sent']++;
            } else {
                Cogito_RAR_Conversion_Queue::mark_failed( $id, $outcome['error'] ?? 'Unknown error' );
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * Re-runs the shared click classifier against a queued row's stored
     * signals. had_cookie is set true (unknown at this point, and true is
     * the non-false-flagging default) — the same convention the historical
     * click re-scan already uses elsewhere in this plugin.
     */
    private static function now_looks_like_a_bot( array $signals ) {
        if ( ! class_exists( 'Cogito_RAR_Click_Logger' ) ) {
            return false;
        }

        $check = Cogito_RAR_Click_Logger::classify( [
            'ip_address' => $signals['ip'] ?? '',
            'hostname'   => $signals['hostname'] ?? '',
            'org'        => $signals['org'] ?? '',
            'user_agent' => $signals['user_agent'] ?? '',
            'referrer'   => $signals['event_source_url'] ?? '',
            'had_cookie' => true,
            'post_id'    => $signals['post_id'] ?? 0,
            'click_date' => $signals['click_date'] ?? current_time( 'Y-m-d' ),
        ] );

        return (int) $check['bot_or_not'] !== 0;
    }
}
