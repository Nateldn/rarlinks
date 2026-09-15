<?php
/**
 * Registry of active conversions providers. Adding Pinterest later is one
 * new line here (plus its own provider class) — nothing else in the
 * capture/queue/dispatch pipeline needs to change.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Conversion_Providers {

    /**
     * @return Cogito_RAR_Conversion_Provider[]
     */
    public static function all() {
        static $providers = null;

        if ( null === $providers ) {
            $providers = [
                new Cogito_RAR_Conversion_Provider_Meta(),
                // Pinterest goes here once built:
                // new Cogito_RAR_Conversion_Provider_Pinterest(),
            ];
        }

        return $providers;
    }

    /**
     * @param string $key
     * @return Cogito_RAR_Conversion_Provider|null
     */
    public static function get( $key ) {
        foreach ( self::all() as $provider ) {
            if ( $provider->get_key() === $key ) {
                return $provider;
            }
        }
        return null;
    }
}
