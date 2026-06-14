<?php
/**
 * Rewrites in-content links to RARLink slugs into the canonical /go/ form
 * at render time — without touching the database.
 *
 * A `the_content` filter swaps href="/pando-bf" for href="/go/pando-bf/"
 * in the outgoing HTML only. The stored post is unchanged, so the rewrite
 * is fully reversible (deactivate this filter and links revert). Legacy
 * bare-slug links keep working regardless, via the redirect engine's
 * fallback — this just moves on-site links onto the prefixed path so the
 * edge (Cloudflare) can target /go/ cleanly.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Link_Rewriter {

    /** Transient caching the set of redirect slugs (slug => true). */
    const TRANSIENT = 'rar_redirect_slugs';

    public static function init() {
        add_filter( 'the_content', [ self::class, 'rewrite' ], 20 );
        // Keep the cached slug set fresh as links are added/edited/removed
        add_action( 'save_post_rar_redirect', [ self::class, 'flush_slug_cache' ] );
        add_action( 'deleted_post', [ self::class, 'flush_slug_cache' ] );
    }

    /**
     * Returns the set of published redirect slugs as [ slug => true ],
     * cached in a transient for O(1) lookups during the content filter.
     *
     * @return array
     */
    public static function get_slugs() {
        $slugs = get_transient( self::TRANSIENT );
        if ( false !== $slugs ) {
            return $slugs;
        }

        $ids = get_posts( [
            'post_type'      => 'rar_redirect',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $slugs = [];
        foreach ( $ids as $id ) {
            $name = get_post_field( 'post_name', $id );
            if ( $name ) {
                $slugs[ $name ] = true;
            }
        }

        set_transient( self::TRANSIENT, $slugs, DAY_IN_SECONDS );
        return $slugs;
    }

    /**
     * Clears the cached slug set.
     */
    public static function flush_slug_cache() {
        delete_transient( self::TRANSIENT );
    }

    /**
     * Rewrites matching hrefs in post content to the prefixed vanity path.
     *
     * @param string $content Post content HTML.
     * @return string
     */
    public static function rewrite( $content ) {
        // Cheap bail-outs
        if ( is_admin() || '' === self::prefix() || strpos( $content, 'href' ) === false ) {
            return $content;
        }

        $slugs = self::get_slugs();
        if ( empty( $slugs ) ) {
            return $content;
        }

        $prefix    = self::prefix();
        $home_host = parse_url( home_url(), PHP_URL_HOST );

        return preg_replace_callback(
            '/href=(["\'])(.*?)\1/i',
            function ( $m ) use ( $slugs, $prefix, $home_host ) {
                $quote = $m[1];
                $url   = $m[2];

                // Absolute URL to a different host — leave alone
                $host = parse_url( $url, PHP_URL_HOST );
                if ( $host && strcasecmp( $host, $home_host ) !== 0 ) {
                    return $m[0];
                }

                $path = parse_url( $url, PHP_URL_PATH );
                if ( ! $path ) {
                    return $m[0];
                }

                // Only a single-segment path that is a known redirect slug,
                // and not already under the prefix, gets rewritten
                $seg = trim( $path, '/' );
                if ( '' === $seg || strpos( $seg, '/' ) !== false || ! isset( $slugs[ $seg ] ) ) {
                    return $m[0];
                }

                $new_path = '/' . $prefix . '/' . $seg . '/';

                // Preserve any query string / fragment from the original href
                $query    = parse_url( $url, PHP_URL_QUERY );
                $fragment = parse_url( $url, PHP_URL_FRAGMENT );
                if ( $query ) {
                    $new_path .= '?' . $query;
                }
                if ( $fragment ) {
                    $new_path .= '#' . $fragment;
                }

                // Preserve the original absolute-vs-relative form
                if ( $host ) {
                    $scheme  = parse_url( $url, PHP_URL_SCHEME ) ?: 'https';
                    $new_url = $scheme . '://' . $host . $new_path;
                } else {
                    $new_url = $new_path;
                }

                return 'href=' . $quote . $new_url . $quote;
            },
            $content
        );
    }

    /**
     * The active prefix, from the redirect engine (single source of truth).
     */
    private static function prefix() {
        return class_exists( 'Cogito_RAR_Redirect_Engine' ) ? Cogito_RAR_Redirect_Engine::PREFIX : '';
    }
}
