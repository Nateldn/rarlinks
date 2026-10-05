<?php
/**
 * Makes RARLink fields available through the REST API, and enforces —
 * independently of whatever a request actually asks for — that an account
 * without publish rights (the "RARLinks Assistant" role; see
 * Cogito_RAR_Roles) can only ever produce a draft with its Active toggle
 * left at the site default. This is deliberately NOT left to the
 * requesting client's own good behaviour: even if a request explicitly
 * asks for status=publish or Active=on, this overrides it server-side.
 *
 * Also narrows what such an account can even SEE — by default, being
 * able to edit a post type means seeing every published post of it in
 * both the admin list and the REST collection endpoint (the same way a
 * Contributor sees everyone's published Posts), which isn't something
 * the Assistant role needs or was asked to have. restrict_listing_to_own_posts()
 * scopes both down to the current user's own links.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Rest_Access {

    const CPT = 'rar_redirect';

    public static function init() {
        add_action( 'init', [ self::class, 'register_meta_fields' ] );
        add_filter( 'rest_pre_insert_' . self::CPT, [ self::class, 'force_draft_without_publish_cap' ], 10, 2 );
        add_action( 'rest_after_insert_' . self::CPT, [ self::class, 'force_default_active_without_publish_cap' ], 10, 2 );
        add_action( 'pre_get_posts', [ self::class, 'restrict_listing_to_own_posts' ] );
        add_filter( 'wp_count_posts', [ self::class, 'scope_counts_to_own_posts' ], 10, 2 );
        add_action( 'rest_after_insert_' . self::CPT, [ self::class, 'auto_detect_track_only' ], 10, 2 );
    }

    /**
     * Auto-detects track-only status from the destination (see
     * Cogito_RAR_Track_Only) when a REST create/update didn't explicitly
     * include meta._rar_track_only — mirrors the classic Edit screen's own
     * checkbox, which previews the same auto-detection before a human
     * ever saves it. Applies to any REST caller, not just the restricted
     * Assistant role; this is a general convenience, not an enforcement
     * rule.
     */
    public static function auto_detect_track_only( $post, $request ) {
        if ( self::CPT !== $post->post_type ) {
            return;
        }

        $meta = $request->get_param( 'meta' );
        if ( is_array( $meta ) && array_key_exists( Cogito_RAR_Track_Only::META_KEY, $meta ) ) {
            return; // Explicitly set by the request — never override it.
        }

        $target  = (string) get_post_meta( $post->ID, '_rar_target', true );
        $current = get_post_meta( $post->ID, Cogito_RAR_Track_Only::META_KEY, true );
        $resolved = Cogito_RAR_Track_Only::resolve( null, $target );

        if ( $resolved !== $current ) {
            update_post_meta( $post->ID, Cogito_RAR_Track_Only::META_KEY, $resolved );
        }
    }

    /**
     * The "All (683) | Published (682) | Draft (1)" status tabs above the
     * list table are a SEPARATE site-wide count (wp_count_posts()), not
     * derived from the list query above — so restricting the list alone
     * still let the total number of links site-wide leak through those
     * numbers, even with every row itself hidden. Same gating as
     * restrict_listing_to_own_posts(), recomputing the per-status counts
     * scoped to just the current user's own links instead.
     *
     * @param object $counts
     * @param string $type
     * @return object
     */
    public static function scope_counts_to_own_posts( $counts, $type ) {
        if ( self::CPT !== $type ) {
            return $counts;
        }
        if ( ! is_user_logged_in() || current_user_can( 'edit_others_rar_redirects' ) ) {
            return $counts;
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_status, COUNT(*) AS num_posts FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d GROUP BY post_status",
            $type,
            get_current_user_id()
        ) );

        $scoped = new stdClass();
        foreach ( $rows as $row ) {
            $scoped->{$row->post_status} = (int) $row->num_posts;
        }
        return $scoped;
    }

    /**
     * WordPress's own default for "can edit this post type" is "can see
     * every published post of it," the same way a Contributor can already
     * see everyone else's published Posts, not just their own — that's
     * not something the capability model alone changes. For the
     * Assistant role specifically, seeing the whole link catalogue isn't
     * useful and isn't something it was asked to have, so this narrows
     * both the admin "All RARLinks" list AND the REST collection
     * endpoint (both run on WP_Query under the hood, so one filter
     * covers both) to only the current user's own links.
     *
     * Gated on being logged in — without that, this would also catch
     * the redirect engine's own anonymous front-end lookups and break
     * every public link for every visitor by restricting the query to
     * "author = (nobody)".
     */
    public static function restrict_listing_to_own_posts( $query ) {
        if ( self::CPT !== $query->get( 'post_type' ) ) {
            return;
        }
        if ( ! is_user_logged_in() || current_user_can( 'edit_others_rar_redirects' ) ) {
            return;
        }

        $query->set( 'author', get_current_user_id() );
    }

    /**
     * Every field a link actually uses, so a draft created through the API
     * is a genuine, fully-configured link — not a bare title needing to be
     * finished by hand in wp-admin before it'll work.
     *
     * All of these are "protected" meta by WordPress's own convention
     * (leading underscore), which defaults to REQUIRING manage_options via
     * REST unless an auth_callback says otherwise — the callback below
     * ties access to the SAME per-post edit_post meta capability the rest
     * of this plugin already uses (class-metabox-save.php,
     * class-metaboxes.php), so it automatically respects the "own,
     * unpublished only" boundary the Assistant role is built around,
     * with no separate logic to keep in sync.
     */
    public static function register_meta_fields() {
        $auth_callback = function ( $allowed, $meta_key, $post_id ) {
            return current_user_can( 'edit_post', $post_id );
        };

        $string_fields = [ '_rar_target', '_rar_notes', '_rar_rotation', '_rar_geo' ];
        foreach ( $string_fields as $key ) {
            register_post_meta( self::CPT, $key, [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'string',
                'auth_callback' => $auth_callback,
            ] );
        }

        // '1'/'0' strings, matching exactly how the rest of this plugin
        // already reads them (e.g. $is_active !== '1' in the redirect
        // engine) — registered as strings rather than true booleans so a
        // REST-created link's meta is byte-for-byte identical to one
        // saved through the classic Edit screen.
        $flag_fields = [ '_rar_nofollow', '_rar_sponsored', '_rar_active', '_rar_geo_enabled', '_rar_rotation_enabled', Cogito_RAR_Track_Only::META_KEY ];
        foreach ( $flag_fields as $key ) {
            register_post_meta( self::CPT, $key, [
                'show_in_rest'      => true,
                'single'            => true,
                'type'              => 'string',
                'auth_callback'     => $auth_callback,
                'sanitize_callback' => function ( $value ) {
                    return ( '1' === (string) $value ) ? '1' : '0';
                },
            ] );
        }

        register_post_meta( self::CPT, '_rar_type', [
            'show_in_rest'      => true,
            'single'            => true,
            'type'              => 'integer',
            'auth_callback'     => $auth_callback,
            'sanitize_callback' => function ( $value ) {
                $value = (int) $value;
                return in_array( $value, [ 301, 302, 307 ], true ) ? $value : 302;
            },
        ] );
    }

    /**
     * Forces a brand-new link to post_status=draft when the creating
     * account can't publish — overriding whatever status was requested.
     * WordPress's own REST controller already downgrades an unauthorised
     * publish attempt, but to 'pending', not 'draft'; this makes it
     * 'draft' specifically, matching how Nate reviews these (Edit screen,
     * same as any link he starts himself).
     *
     * Only applies to NEW posts — an update to an existing draft doesn't
     * need this, since the Assistant role can only ever reach its own
     * still-unpublished posts in the first place (map_meta_cap already
     * enforces that; see Cogito_RAR_Roles), and forcing status here on
     * every edit would fight a legitimate publish by someone who DOES
     * have the capability.
     *
     * @param stdClass        $prepared_post
     * @param WP_REST_Request $request
     * @return stdClass
     */
    public static function force_draft_without_publish_cap( $prepared_post, $request ) {
        $is_new_post = empty( $prepared_post->ID );
        if ( $is_new_post && ! current_user_can( 'publish_rar_redirects' ) ) {
            $prepared_post->post_status = 'draft';
        }
        return $prepared_post;
    }

    /**
     * Forces _rar_active back to the site's configured default for any
     * create/update from an account without publish rights — regardless
     * of what the request's own meta.\_rar_active value asked for. Paired
     * with the redirect engine's own published-AND-active gate: even if
     * this ever left Active "on" on a draft, the link still wouldn't
     * redirect until Nate actually publishes it — this exists so the
     * Active column reads correctly (matching what a manually-created
     * link would show) rather than as the only thing standing between a
     * draft and going live.
     *
     * @param WP_Post         $post
     * @param WP_REST_Request $request
     */
    public static function force_default_active_without_publish_cap( $post, $request ) {
        if ( self::CPT !== $post->post_type || current_user_can( 'publish_rar_redirects' ) ) {
            return;
        }

        $default_active = class_exists( 'Cogito_RAR_Settings_Defaults' )
            ? get_option( Cogito_RAR_Settings_Defaults::OPTION_ACTIVE, '1' )
            : '1';

        update_post_meta( $post->ID, '_rar_active', ( '1' === (string) $default_active ) ? '1' : '0' );
    }
}
