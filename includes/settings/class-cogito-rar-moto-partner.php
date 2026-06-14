<?php
/**
 * Moto Partner status + live-period model.
 *
 * A Moto Partner is a RARLink that sits on the homepage as a native ad, so a
 * click arriving with the homepage as referrer can be a genuine human. Only
 * ~3 run at once and they rotate, so "is it a homepage ad?" is time-dependent:
 * a link live two months ago may be archived today.
 *
 * This class records the on/off history as a list of live PERIODS and answers
 * "was this link live on date X?" — used by the bot detector both at click
 * time (X = today) and during the historical re-scan (X = the click's date).
 *
 * Meta:
 *   _rar_moto_partner          '1'/'0'  — currently flagged as a partner
 *   _rar_moto_partner_status   'live'/'archived'
 *   _rar_moto_partner_periods  JSON [ { from:'Y-m-d', to:'Y-m-d'|null }, ... ]
 *                              (to = null means the window is still open)
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Moto_Partner {

    const META_FLAG    = '_rar_moto_partner';
    const META_STATUS  = '_rar_moto_partner_status';
    const META_PERIODS = '_rar_moto_partner_periods';

    const STATUS_LIVE     = 'live';
    const STATUS_ARCHIVED = 'archived';

    /**
     * Today's date in the site timezone (Y-m-d) — the clock used for all
     * period bounds and click-date comparisons.
     */
    public static function today() {
        return current_time( 'Y-m-d' );
    }

    /**
     * Returns the recorded live periods as an array of [ 'from'=>, 'to'=> ].
     */
    public static function get_periods( $post_id ) {
        $raw = get_post_meta( $post_id, self::META_PERIODS, true );
        $arr = json_decode( (string) $raw, true );
        return is_array( $arr ) ? $arr : [];
    }

    /**
     * Index of the currently-open period (to is null/empty), or null.
     */
    private static function open_period_index( array $periods ) {
        foreach ( $periods as $i => $p ) {
            if ( empty( $p['to'] ) ) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Applies a save: stores the flag + status and opens/closes the live
     * window to match. Called from the edit-screen save and the list's
     * archive action. Auto-captures the dates — nothing is hand-entered.
     *
     * @param int    $post_id    The RARLink.
     * @param bool   $is_partner Whether the Moto Partner box is ticked.
     * @param string $status     'live' or 'archived'.
     */
    public static function sync_on_save( $post_id, $is_partner, $status ) {
        $status = ( self::STATUS_ARCHIVED === $status ) ? self::STATUS_ARCHIVED : self::STATUS_LIVE;

        update_post_meta( $post_id, self::META_FLAG, $is_partner ? '1' : '0' );
        update_post_meta( $post_id, self::META_STATUS, $status );

        $periods    = self::get_periods( $post_id );
        $open_index = self::open_period_index( $periods );
        $today      = self::today();

        // Effectively live only when ticked AND status = live
        $effective_live = ( $is_partner && self::STATUS_LIVE === $status );

        if ( $effective_live && null === $open_index ) {
            // Went live → open a new window
            $periods[] = [ 'from' => $today, 'to' => null ];
        } elseif ( ! $effective_live && null !== $open_index ) {
            // Went archived/off → close the open window
            $periods[ $open_index ]['to'] = $today;
        }

        update_post_meta( $post_id, self::META_PERIODS, wp_json_encode( $periods ) );
    }

    /**
     * Whether the link was a live homepage partner on the given date.
     *
     * This is the signal the detector uses: a homepage-referrer click is only
     * treated as possibly human when this returns true for the click's date.
     *
     * @param int    $post_id The RARLink.
     * @param string $date    Date to test, 'Y-m-d' (site timezone).
     * @return bool
     */
    public static function was_live_on( $post_id, $date ) {
        $date = (string) $date;
        if ( '' === $date ) {
            return false;
        }

        $periods = self::get_periods( $post_id );

        // Backward compatibility: a partner flagged before this feature has no
        // recorded periods. Fall back to its current live status so existing
        // partners keep working until their next save records a window.
        if ( empty( $periods ) ) {
            return self::is_currently_live( $post_id );
        }

        $today = self::today();
        foreach ( $periods as $p ) {
            $from = isset( $p['from'] ) ? (string) $p['from'] : '';
            if ( '' === $from ) {
                continue;
            }
            // Open window runs up to today. Y-m-d strings compare chronologically.
            $to = empty( $p['to'] ) ? $today : (string) $p['to'];
            if ( $date >= $from && $date <= $to ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the link is a live partner right now.
     */
    public static function is_currently_live( $post_id ) {
        return get_post_meta( $post_id, self::META_FLAG, true ) === '1'
            && get_post_meta( $post_id, self::META_STATUS, true ) !== self::STATUS_ARCHIVED;
    }

    /**
     * The date the link first went live (earliest period start), or ''.
     */
    public static function live_since( $post_id ) {
        $periods = self::get_periods( $post_id );
        $starts  = array_filter( array_map(
            static function ( $p ) {
                return isset( $p['from'] ) ? (string) $p['from'] : '';
            },
            $periods
        ) );
        return $starts ? min( $starts ) : '';
    }
}
