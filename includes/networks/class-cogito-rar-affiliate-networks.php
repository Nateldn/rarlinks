<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Affiliate_Networks {

    private static $providers = [];

    public static function register( Cogito_RAR_Affiliate_Provider $provider ) {
        self::$providers[ $provider->id() ] = $provider;
    }

    public static function all() {
        return apply_filters( 'rarlinks_affiliate_providers', self::$providers );
    }

    public static function configured() {
        return array_filter( self::all(), static function ( $provider ) {
            return $provider->is_configured();
        } );
    }

    public static function get( $id ) {
        $providers = self::all();
        return isset( $providers[ $id ] ) ? $providers[ $id ] : null;
    }

    public static function match( $url ) {
        $hits = [];

        foreach ( self::configured() as $provider ) {
            $result = $provider->match_advertiser( $url );

            if ( ! empty( $result['match'] ) ) {
                $hits[] = [
                    'provider'   => $provider,
                    'advertiser' => $result['match'],
                    'confidence' => isset( $result['confidence'] ) ? $result['confidence'] : '',
                ];
                continue;
            }

            if ( ! empty( $result['candidates'] ) ) {
                foreach ( $result['candidates'] as $candidate ) {
                    $hits[] = [
                        'provider'   => $provider,
                        'advertiser' => $candidate,
                        'confidence' => 'ambiguous',
                    ];
                }
            }
        }

        $definite = array_values( array_filter( $hits, static function ( $hit ) {
            return 'ambiguous' !== $hit['confidence'];
        } ) );

        if ( 1 === count( $definite ) ) {
            return [ 'hit' => $definite[0], 'candidates' => [] ];
        }

        return [ 'hit' => null, 'candidates' => $hits ];
    }

    public static function describe_candidates( $candidates ) {
        $parts = [];
        foreach ( $candidates as $candidate ) {
            $parts[] = sprintf(
                '%s — %s (%s, ID %s)',
                $candidate['provider']->label(),
                $candidate['advertiser']['name'],
                $candidate['advertiser']['host'],
                $candidate['advertiser']['id']
            );
        }
        return implode( '; ', $parts );
    }
}
