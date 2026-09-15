<?php
/**
 * Contract every conversions provider (Meta, later Pinterest, ...) must
 * implement. This is the entire extensibility point: the queue, capture
 * and dispatcher never know Meta or Pinterest exist — they only ever talk
 * to this interface. Adding a new provider means one new class that
 * implements these five methods and one line in the registry
 * (Cogito_RAR_Conversion_Providers::all()) — nothing else changes.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Cogito_RAR_Conversion_Provider {

    /**
     * Short machine key, e.g. 'meta', 'pinterest'. Stored on every queue row.
     *
     * @return string
     */
    abstract public function get_key();

    /**
     * Human label for the admin UI, e.g. 'Meta'.
     *
     * @return string
     */
    abstract public function get_label();

    /**
     * Whether this provider is configured and ready to send (e.g. its
     * wp-config.php access-token constant is defined). Capture only queues
     * events for enabled providers; the dispatcher only flushes them.
     *
     * @return bool
     */
    abstract public function is_enabled();

    /**
     * Maps the plugin's raw, provider-agnostic signal set into this
     * provider's own event shape. Never called on a disabled provider.
     *
     * @param array $signals ip, user_agent, referrer, event_source_url,
     *                       destination_url, link_text, link_classes, fbp,
     *                       fbc, click_time (unix seconds), event_id, post_id.
     * @return array The mapped, provider-shaped event.
     */
    abstract public function map_payload( array $signals );

    /**
     * Sends a batch of already-mapped events (from map_payload) to the
     * provider's API.
     *
     * @param array $payloads Mapped events, same order as the caller's ids.
     * @return array One [ 'success' => bool, 'error' => string|null ] per
     *               payload, same order/count as $payloads.
     */
    abstract public function send_batch( array $payloads );
}
