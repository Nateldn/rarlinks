<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Rakuten_API {

    const API_BASE = 'https://api.linksynergy.com';

    const OPTION_CLIENT_ID          = 'rar_rakuten_client_id';
    const OPTION_CLIENT_SECRET      = 'rar_rakuten_client_secret';
    const OPTION_ACCOUNT_ID         = 'rar_rakuten_account_id';
    const OPTION_ADVERTISERS        = 'rar_rakuten_advertisers';
    const OPTION_ADVERTISERS_SYNCED = 'rar_rakuten_advertisers_synced';

    const TOKEN_TRANSIENT = 'rar_rakuten_access_token';
    const TOKEN_SAFETY_MARGIN = 120;

    const PAGE_LIMIT = 100;
    const MAX_PAGES  = 50;

    public static function has_credentials() {
        return '' !== (string) get_option( self::OPTION_CLIENT_ID, '' )
            && '' !== (string) get_option( self::OPTION_CLIENT_SECRET, '' )
            && '' !== (string) get_option( self::OPTION_ACCOUNT_ID, '' );
    }

    public static function get_access_token( $force_new = false ) {
        if ( ! $force_new ) {
            $cached = get_transient( self::TOKEN_TRANSIENT );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }

        if ( ! self::has_credentials() ) {
            return new WP_Error(
                'rar_rakuten_no_credentials',
                'Rakuten credentials are not set. Add them under RARLinks → Settings → Affiliate Networks.'
            );
        }

        $token_key = base64_encode(
            get_option( self::OPTION_CLIENT_ID, '' ) . ':' . get_option( self::OPTION_CLIENT_SECRET, '' )
        );

        $response = wp_remote_post( self::API_BASE . '/token', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => [ 'scope' => get_option( self::OPTION_ACCOUNT_ID, '' ) ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'rar_rakuten_token_unreachable',
                'Could not reach Rakuten: ' . $response->get_error_message()
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            return new WP_Error(
                'rar_rakuten_token_rejected',
                sprintf(
                    'Rakuten rejected the credentials (HTTP %d). Check the client ID, secret and account ID.',
                    $code
                )
            );
        }

        $expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
        $ttl     = max( 60, $expires - self::TOKEN_SAFETY_MARGIN );

        set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );

        return $body['access_token'];
    }

    private static function request( $method, $path, $body = null, $retrying = false ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ];

        if ( null !== $body ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode( $body );
        }

        $response = wp_remote_request( self::API_BASE . $path, $args );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'rar_rakuten_unreachable',
                'Could not reach Rakuten: ' . $response->get_error_message()
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( 401 === $code && ! $retrying ) {
            delete_transient( self::TOKEN_TRANSIENT );
            return self::request( $method, $path, $body, true );
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code > 299 ) {
            return new WP_Error(
                'rar_rakuten_http_error',
                self::describe_error( $decoded, $code ),
                [ 'status' => $code, 'body' => $decoded ]
            );
        }

        return is_array( $decoded ) ? $decoded : [];
    }

    private static function describe_error( $decoded, $code ) {
        $known = [
            'ACCESS_DENIED'              => 'You are not in an approved partnership with this advertiser.',
            'DEEP_LINK_DENIED'           => 'The advertiser has blacklisted a term in this URL.',
            'CANNOT_RESOLVE_ADVERTISER'  => 'That advertiser ID is not recognised, or the advertiser is inactive.',
            'DEEP_LINKING_NOT_ENABLED'   => 'This advertiser does not support deep linking.',
            'URL_TEMPLATE_MISMATCH'      => 'The URL does not match this advertiser\'s accepted URL format.',
            'UNEXPECTED_ERROR'           => 'Rakuten reported a missing required parameter.',
            'ENTITY_DECODE_ERROR'        => 'Rakuten could not parse the request.',
        ];

        $raw = '';
        if ( is_array( $decoded ) ) {
            foreach ( [ 'error', 'code', 'message', 'error_description' ] as $key ) {
                if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
                    $raw = $decoded[ $key ];
                    break;
                }
            }
        }

        foreach ( $known as $needle => $plain ) {
            if ( false !== stripos( $raw, $needle ) ) {
                return $plain;
            }
        }

        return '' !== $raw
            ? sprintf( 'Rakuten returned an error (HTTP %d): %s', $code, $raw )
            : sprintf( 'Rakuten returned an error (HTTP %d).', $code );
    }

    public static function sync_advertisers() {
        $collected = [];
        $page      = 1;

        do {
            $path = add_query_arg( [
                'deep_links' => 'true',
                'limit'      => self::PAGE_LIMIT,
                'page'       => $page,
            ], '/v2/advertisers' );

            $result = self::request( 'GET', $path );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $batch = isset( $result['advertisers'] ) && is_array( $result['advertisers'] )
                ? $result['advertisers']
                : [];

            foreach ( $batch as $advertiser ) {
                if ( empty( $advertiser['id'] ) ) {
                    continue;
                }
                $collected[] = [
                    'id'   => (string) $advertiser['id'],
                    'name' => isset( $advertiser['name'] ) ? sanitize_text_field( $advertiser['name'] ) : '',
                    'url'  => isset( $advertiser['url'] ) ? esc_url_raw( $advertiser['url'] ) : '',
                    'host' => isset( $advertiser['url'] ) ? self::normalise_host( $advertiser['url'] ) : '',
                ];
            }

            $page++;
        } while ( count( $batch ) >= self::PAGE_LIMIT && $page <= self::MAX_PAGES );

        update_option( self::OPTION_ADVERTISERS, $collected, false );
        update_option( self::OPTION_ADVERTISERS_SYNCED, time(), false );

        return count( $collected );
    }

    public static function get_advertisers() {
        $stored = get_option( self::OPTION_ADVERTISERS, [] );
        return is_array( $stored ) ? $stored : [];
    }

    public static function get_last_synced() {
        return (int) get_option( self::OPTION_ADVERTISERS_SYNCED, 0 );
    }

    public static function normalise_host( $url ) {
        $host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
        return preg_replace( '/^www\./', '', $host );
    }

    public static function resolve_advertiser_for_url( $url ) {
        $host = self::normalise_host( $url );
        if ( '' === $host ) {
            return null;
        }

        $advertisers = self::get_advertisers();

        foreach ( $advertisers as $advertiser ) {
            if ( '' !== $advertiser['host'] && $advertiser['host'] === $host ) {
                return $advertiser;
            }
        }

        foreach ( $advertisers as $advertiser ) {
            if ( '' === $advertiser['host'] ) {
                continue;
            }
            if ( str_ends_with( $host, '.' . $advertiser['host'] ) ) {
                return $advertiser;
            }
        }

        return null;
    }

    public static function create_deep_link( $url, $advertiser_id, $name = '', $u1 = '' ) {
        $url = esc_url_raw( trim( (string) $url ) );
        if ( '' === $url ) {
            return new WP_Error( 'rar_rakuten_bad_url', 'No URL supplied.' );
        }

        $advertiser_id = trim( (string) $advertiser_id );
        if ( '' === $advertiser_id ) {
            return new WP_Error( 'rar_rakuten_no_advertiser', 'No advertiser ID supplied.' );
        }

        $body = [
            'url'           => $url,
            'advertiser_id' => is_numeric( $advertiser_id ) ? (int) $advertiser_id : $advertiser_id,
        ];

        if ( '' !== $name ) {
            $body['name'] = sanitize_text_field( $name );
        }
        if ( '' !== $u1 ) {
            $body['u1'] = sanitize_text_field( $u1 );
        }

        $result = self::request( 'POST', '/v1/links/deep_links', $body );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $deep_link = isset( $result['advertiser']['deep_link']['deep_link_url'] )
            ? $result['advertiser']['deep_link']['deep_link_url']
            : '';

        if ( '' === $deep_link ) {
            return new WP_Error(
                'rar_rakuten_no_link_returned',
                'Rakuten accepted the request but returned no deep link.'
            );
        }

        return [
            'deep_link'       => esc_url_raw( $deep_link ),
            'advertiser_id'   => isset( $result['advertiser']['id'] ) ? (string) $result['advertiser']['id'] : $advertiser_id,
            'advertiser_name' => isset( $result['advertiser']['name'] ) ? sanitize_text_field( $result['advertiser']['name'] ) : '',
        ];
    }

    public static function test_connection() {
        $token = self::get_access_token( true );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $result = self::request( 'GET', '/v2/advertisers?limit=1&page=1' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return isset( $result['_metadata']['total'] ) ? (int) $result['_metadata']['total'] : 0;
    }
}
