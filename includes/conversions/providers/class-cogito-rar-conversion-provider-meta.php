<?php
/**
 * Meta Conversions API provider.
 *
 * Verified against Meta's own current documentation and Nate's live Meta
 * App dashboard (13-14 Sep 2026) rather than assumed from memory:
 * - Graph API version: v26.0 (his app's version picker shows v20-v26 as
 *   selectable, confirming this is current — kept as a bump-able constant,
 *   since versions have a roughly two-year lifespan).
 * - event_time is a Unix timestamp in SECONDS.
 * - fbc/fbp's internal creation_time component is MILLISECONDS — a
 *   different field, different unit, easy to confuse with event_time.
 * - event_source_url, client_user_agent and action_source are REQUIRED for
 *   any action_source = 'website' event.
 * - destination_url/link_text/link_classes are not standard Meta fields —
 *   they're custom keys nested in custom_data, not top-level parameters.
 * - Batches are all-or-nothing: one bad event fails the whole batch of up
 *   to 1,000. No PII hashing needed for AffiliateClick, so this hand-rolls
 *   the HTTP call with wp_remote_post() rather than pulling in Meta's
 *   official Business SDK for what is a simple JSON POST.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Conversion_Provider_Meta extends Cogito_RAR_Conversion_Provider {

    /**
     * Current Graph API version. A single place to bump when Meta ships a
     * new one — check developers.facebook.com/docs/graph-api/changelog
     * (or the app's own "Upgrade API version" screen) roughly annually.
     */
    const GRAPH_API_VERSION = 'v26.0';

    /** Falls back to this if RAR_META_CAPI_DATASET_ID isn't defined. */
    const DEFAULT_DATASET_ID = '129600977749681';

    public function get_key() {
        return 'meta';
    }

    public function get_label() {
        return 'Meta';
    }

    /**
     * Enabled once an access token is defined in wp-config.php. Never in
     * wp_options, never in the database, never committed.
     */
    public function is_enabled() {
        return defined( 'RAR_META_CAPI_TOKEN' ) && '' !== RAR_META_CAPI_TOKEN;
    }

    private function dataset_id() {
        return ( defined( 'RAR_META_CAPI_DATASET_ID' ) && '' !== RAR_META_CAPI_DATASET_ID )
            ? RAR_META_CAPI_DATASET_ID
            : self::DEFAULT_DATASET_ID;
    }

    public function map_payload( array $signals ) {
        // Which KEYS to use in custom_data below — admin-configurable per
        // event (see Cogito_RAR_Conversion_Capture::get_field_names_for_event()),
        // so e.g. an AffiliateClick's destination can be sent as
        // "affiliate_url" to mirror an existing GA4 tag's own parameter
        // name. Falls back to the plugin's original generic names for any
        // row queued before this feature existed.
        $field_names = is_array( $signals['field_names'] ?? null )
            ? array_merge( Cogito_RAR_Conversion_Capture::DEFAULT_FIELD_NAMES, $signals['field_names'] )
            : Cogito_RAR_Conversion_Capture::DEFAULT_FIELD_NAMES;

        // destination_url/link_text/link_classes (whatever they're actually
        // named per-event) are not standard Meta fields — they live here as
        // custom keys, which Meta explicitly supports for custom events.
        $custom_data = array_filter( [
            $field_names['destination_url'] => $signals['destination_url'] ?? '',
            $field_names['link_text']       => $signals['link_text'] ?? '',
            $field_names['link_classes']    => $signals['link_classes'] ?? '',
        ] );

        // event_source_url is ALSO required as its own top-level field
        // regardless of the above — this only adds a DUPLICATE copy into
        // custom_data, and only if a name for it was actually configured.
        if ( '' !== $field_names['event_source_url'] ) {
            $custom_data[ $field_names['event_source_url'] ] = $signals['event_source_url'] ?? '';
        }

        $event = [
            'event_name'       => $signals['event_name'] ?? 'AffiliateClick',
            'event_time'       => (int) ( $signals['click_time'] ?? time() ), // seconds, not ms
            'event_id'         => (string) ( $signals['event_id'] ?? '' ),
            'action_source'    => 'website',
            'event_source_url' => (string) ( $signals['event_source_url'] ?? '' ),
            'user_data'        => array_filter( [
                'client_ip_address' => $signals['ip'] ?? '',
                'client_user_agent' => $signals['user_agent'] ?? '',
                'fbp'                => $signals['fbp'] ?? '',
                'fbc'                => $signals['fbc'] ?? '',
            ] ),
            'custom_data'      => $custom_data,
        ];

        // Optional appsecret_proof support — "Require app secret" is
        // currently off in the Renchlist Website App, so this stays inert
        // unless RAR_META_CAPI_APP_SECRET is later defined.
        if ( defined( 'RAR_META_CAPI_APP_SECRET' ) && '' !== RAR_META_CAPI_APP_SECRET && $this->is_enabled() ) {
            $event['appsecret_proof'] = hash_hmac( 'sha256', RAR_META_CAPI_TOKEN, RAR_META_CAPI_APP_SECRET );
        }

        return $event;
    }

    public function send_batch( array $payloads ) {
        if ( empty( $payloads ) || ! $this->is_enabled() ) {
            return [];
        }

        $body = [ 'data' => $payloads ];

        // Dev-only test traffic marker. Meta: "remove it when sending your
        // production payload" — controlled by whether this wp-config
        // constant is defined at all, never a DB/UI toggle.
        if ( defined( 'RAR_META_CAPI_TEST_EVENT_CODE' ) && '' !== RAR_META_CAPI_TEST_EVENT_CODE ) {
            $body['test_event_code'] = RAR_META_CAPI_TEST_EVENT_CODE;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events?access_token=%s',
            self::GRAPH_API_VERSION,
            rawurlencode( $this->dataset_id() ),
            rawurlencode( RAR_META_CAPI_TOKEN )
        );

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            // Transport-level failure — every event in the batch gets the same outcome
            return array_fill( 0, count( $payloads ), [ 'success' => false, 'error' => $response->get_error_message() ] );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( $code >= 200 && $code < 300 ) {
            // Meta's batches are accepted or rejected as a whole — one HTTP
            // call, one outcome applied to every event it carried.
            return array_fill( 0, count( $payloads ), [ 'success' => true, 'error' => null ] );
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        $message = $decoded['error']['message'] ?? ( 'HTTP ' . $code );

        return array_fill( 0, count( $payloads ), [ 'success' => false, 'error' => $message ] );
    }
}
