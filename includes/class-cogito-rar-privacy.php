<?php
/**
 * Registers RARLinks with WordPress's personal-data export/erasure tools
 * (Tools → Export/Erase Personal Data) and contributes a paragraph to the
 * privacy-policy content WordPress generates for you (Settings → Privacy).
 *
 * The exporter/eraser callbacks are deliberately no-ops: WordPress's
 * privacy request flow is keyed by an EMAIL ADDRESS, but RARLinks never
 * captures one — the rar_uid visitor cookie is an anonymous, randomly
 * generated token with no link to any identified person, and click-log
 * rows carry no user ID or email either. There is genuinely nothing to
 * look up by email, so registering an exporter/eraser that always
 * returns "no data" is the honest answer, not a placeholder to fill in
 * later. What DOES need documenting is what's collected and for how
 * long — that's what the privacy-policy content below covers, and it's
 * also why this data is handled through its own automatic retention
 * schedule (Settings → Defaults) rather than a manual per-request
 * erasure tool that has no way to identify which rows belong to whom.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Privacy {

    public static function init() {
        add_action( 'admin_init', [ self::class, 'add_privacy_policy_content' ] );
        add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_eraser' ] );
    }

    public static function add_privacy_policy_content() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $retention_days = class_exists( 'Cogito_RAR_Retention' )
            ? (int) get_option( Cogito_RAR_Retention::OPTION_CLICKS_RETENTION_DAYS, Cogito_RAR_Retention::DEFAULT_CLICKS_RETENTION_DAYS )
            : 180;
        $redaction_days = class_exists( 'Cogito_RAR_Retention' )
            ? (int) get_option( Cogito_RAR_Retention::OPTION_IP_REDACTION_DAYS, Cogito_RAR_Retention::DEFAULT_IP_REDACTION_DAYS )
            : 30;

        $content = '<p class="privacy-policy-tutorial">' . esc_html__( 'This suggested text covers RARLinks, an affiliate-link redirect and click-tracking plugin.', 'rarlinks' ) . '</p>'
            . '<p>' . sprintf(
                /* translators: 1: cookie lifetime in days */
                esc_html__( 'When you click a tracked affiliate link on this site, we set a cookie ("rar_uid") containing a randomly generated identifier, not linked to your name, email, or any account. This cookie lasts %d days and helps us tell repeat visits from automated/bot traffic apart, so click statistics are more accurate.', 'rarlinks' ),
                180
            ) . '</p>'
            . '<p>' . sprintf(
                /* translators: 1: IP redaction window in days, 2: full retention window in days */
                esc_html__( 'We also log the IP address, browser user-agent string, and referring page of each click, for the same bot-detection purpose and to measure link performance. This IP address is visible to site administrators for %1$d days, after which it is irreversibly anonymised; the full click record is deleted after %2$d days.', 'rarlinks' ),
                $redaction_days,
                $retention_days
            ) . '</p>'
            . '<p>' . esc_html__( 'None of this data is linked to an email address or user account, so it cannot be looked up or exported/erased via a personal-data request in the usual way — it ages out automatically on the schedule above instead.', 'rarlinks' ) . '</p>';

        wp_add_privacy_policy_content( 'RARLinks', wp_kses_post( $content ) );
    }

    /**
     * @param array $exporters
     * @return array
     */
    public static function register_exporter( $exporters ) {
        $exporters['rarlinks'] = [
            'exporter_friendly_name' => 'RARLinks',
            'callback'               => [ self::class, 'export_data' ],
        ];
        return $exporters;
    }

    /**
     * Always reports "nothing found, done" — see the class docblock for
     * why there's genuinely nothing to look up by email.
     */
    public static function export_data( $email_address, $page = 1 ) {
        return [
            'data' => [],
            'done' => true,
        ];
    }

    /**
     * @param array $erasers
     * @return array
     */
    public static function register_eraser( $erasers ) {
        $erasers['rarlinks'] = [
            'eraser_friendly_name' => 'RARLinks',
            'callback'             => [ self::class, 'erase_data' ],
        ];
        return $erasers;
    }

    /**
     * Always reports "nothing to remove, done" — with an explanatory
     * message shown in the admin erasure-request results, rather than
     * silently doing nothing with no explanation.
     */
    public static function erase_data( $email_address, $page = 1 ) {
        return [
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => [
                'RARLinks does not store any data linked to an email address or user account, so there is nothing to erase for this request. Click-log data ages out automatically on its own retention schedule (Settings → RARLinks → Defaults).',
            ],
            'done' => true,
        ];
    }
}
