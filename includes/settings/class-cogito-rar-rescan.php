<?php
/**
 * Re-scan: re-runs the current detection rules over historical clicks and
 * updates each row's classification. Batched over AJAX so a large table
 * doesn't time out.
 *
 * Reclassification reuses Cogito_RAR_Click_Logger::classify() against the
 * STORED row signals. The ASN was never stored (so the Spamhaus check is
 * skipped) and the arrival cookie state is unknown (passed as true, so the
 * cookie-dependent rule can't false-flag) — every other rule applies.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Rescan {

    /** Rows processed per AJAX batch. */
    const BATCH = 200;

    public static function init() {
        add_action( 'wp_ajax_rar_rescan_batch', [ self::class, 'ajax_batch' ] );
    }

    /**
     * Renders the Re-scan control (button + progress) on the Reports tab.
     */
    public static function render() {
        global $wpdb;
        $table = $wpdb->prefix . 'rarlinks_clicks';
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

        echo '<div class="rar-rescan">';
        echo '<h3>Re-scan Clicks</h3>';
        echo '<p>Re-run the current detection rules over all <strong>' . esc_html( number_format( $total ) ) . '</strong> logged clicks and update each row’s classification — useful after the rules have changed. ';
        echo 'This reclassifies <em>every</em> row, including ones you set by hand. It can’t redo the Spamhaus ASN or the no-cookie check on old rows (that data wasn’t stored), but applies every other rule.</p>';
        echo '<p>';
        echo '<button type="button" class="button button-secondary rar-rescan-btn" data-nonce="' . esc_attr( wp_create_nonce( 'rar_rescan' ) ) . '" data-total="' . esc_attr( $total ) . '">Re-scan all clicks</button>';
        echo ' <span class="rar-rescan-status"></span>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * AJAX: reclassify one batch of rows after a given id. Returns the count
     * processed, the last id seen, and whether the run is complete.
     */
    public static function ajax_batch() {
        if ( ! check_ajax_referer( 'rar_rescan', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        global $wpdb;
        $table    = $wpdb->prefix . 'rarlinks_clicks';
        $after_id = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;

        // Keyset pagination by id: robust against rows changing during the run
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, post_id, ip_address, hostname, org, user_agent, referrer
             FROM $table WHERE id > %d ORDER BY id ASC LIMIT %d",
            $after_id,
            self::BATCH
        ) );

        $processed = 0;
        $last_id   = $after_id;

        foreach ( $rows as $row ) {
            $result = Cogito_RAR_Click_Logger::classify( [
                'ip_address'        => $row->ip_address,
                'hostname'          => $row->hostname,
                'org'               => $row->org,
                'user_agent'        => $row->user_agent,
                'referrer'          => $row->referrer,
                'current_asn'       => null, // not stored historically
                'had_cookie'        => true, // unknown historically; true avoids false flags
                'post_id'           => (int) $row->post_id,
                'spamhaus_asn_data' => [],
            ] );

            $wpdb->update(
                $table,
                [ 'bot_or_not' => $result['bot_or_not'], 'bot_name' => $result['bot_name'] ],
                [ 'id' => (int) $row->id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );

            $processed++;
            $last_id = (int) $row->id;
        }

        wp_send_json_success( [
            'processed' => $processed,
            'last_id'   => $last_id,
            'done'      => ( $processed < self::BATCH ),
        ] );
    }
}
