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
 * Separately, and for EVERY REST caller regardless of role:
 * enforce_track_only_and_dependents() makes track-only status (see
 * Cogito_RAR_Track_Only) non-negotiable for a recognised no-cloak
 * domain, and forces GEO/rotation off alongside it — same enforcement
 * the classic Edit screen's own save handler applies.
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
        add_action( 'rest_api_init', [ self::class, 'register_edit_link_field' ] );
        add_filter( 'rest_pre_insert_' . self::CPT, [ self::class, 'force_draft_without_publish_cap' ], 10, 2 );
        add_action( 'rest_after_insert_' . self::CPT, [ self::class, 'force_default_active_without_publish_cap' ], 10, 2 );
        add_action( 'rest_after_insert_' . self::CPT, [ self::class, 'force_default_type_if_missing' ], 10, 3 );
        add_action( 'pre_get_posts', [ self::class, 'restrict_listing_to_own_posts' ] );
        add_filter( 'wp_count_posts', [ self::class, 'scope_counts_to_own_posts' ], 10, 2 );
        add_action( 'rest_after_insert_' . self::CPT, [ self::class, 'enforce_track_only_and_dependents' ], 10, 2 );
        // Priority 20: must run after enforce_track_only_and_dependents
        // above, so that when a track-only destination has just wiped
        // _rar_geo back to '[]', this reads THAT value rather than
        // whatever was there before this request.
        add_action( 'rest_after_insert_' . self::CPT, [ self::class, 'sync_geo_enabled_flag' ], 20 );
    }

    /**
     * Enforces track-only status from the destination (see
     * Cogito_RAR_Track_Only) on every REST create/update that actually
     * touches the target or the flag itself — non-negotiable for a
     * recognised no-cloak domain regardless of what meta._rar_track_only
     * the request sent, same as the classic Edit screen's own save
     * handler. GEO and rotation, which only ever have anything to act on
     * at the moment of a server-side redirect, are forced off alongside
     * it for the same reason.
     *
     * Bails out entirely if the request's meta touches neither
     * _rar_target nor _rar_track_only (e.g. a REST update that only sends
     * a new title). A REST partial update omits every field it isn't
     * changing — unlike the classic Edit screen's full form POST, where
     * an unchecked checkbox is still submitted, just absent, and genuinely
     * means "off." Treating a REST omission the same way silently flipped
     * an existing track-only link back to a normal redirect on ANY update
     * that didn't happen to resend the flag.
     */
    public static function enforce_track_only_and_dependents( $post, $request ) {
        if ( self::CPT !== $post->post_type ) {
            return;
        }

        $meta               = $request->get_param( 'meta' );
        $touches_target     = is_array( $meta ) && array_key_exists( '_rar_target', $meta );
        $touches_track_only = is_array( $meta ) && array_key_exists( Cogito_RAR_Track_Only::META_KEY, $meta );

        if ( ! $touches_target && ! $touches_track_only ) {
            return;
        }

        $submitted = $touches_track_only ? $meta[ Cogito_RAR_Track_Only::META_KEY ] : null;

        $target   = (string) get_post_meta( $post->ID, '_rar_target', true );
        $resolved = Cogito_RAR_Track_Only::resolve( $submitted, $target );

        if ( $resolved !== get_post_meta( $post->ID, Cogito_RAR_Track_Only::META_KEY, true ) ) {
            update_post_meta( $post->ID, Cogito_RAR_Track_Only::META_KEY, $resolved );
        }

        if ( '1' === $resolved ) {
            update_post_meta( $post->ID, '_rar_geo_enabled', '0' );
            update_post_meta( $post->ID, '_rar_rotation_enabled', '0' );
            // Not just disabled — wiped, so a track-only link created or
            // edited via the API has no lingering GEO/rotation rules
            // sitting in its data, matching the classic Edit screen's own
            // save handler.
            update_post_meta( $post->ID, '_rar_geo', '[]' );
            update_post_meta( $post->ID, '_rar_rotation', '[]' );
        }
    }

    /**
     * Validates a submitted _rar_geo value against the exact same rule
     * the classic Edit screen's own save handler uses: each entry needs
     * an ISO 3166 alpha-2 country code and a non-empty URL, everything
     * else is dropped. Accepts either an already-JSON-encoded string
     * (what a REST client sends, since the field is registered as
     * type => 'string') or a bare PHP array (register_meta still runs
     * sanitize_callback on a classic update_post_meta() call, so this
     * covers that path too). Always returns a JSON string.
     *
     * @param string|array $value
     * @return string
     */
    public static function sanitize_geo_meta( $value ) {
        $decoded = is_array( $value ) ? $value : json_decode( (string) $value, true );
        if ( ! is_array( $decoded ) ) {
            return '[]';
        }

        $clean = [];
        foreach ( $decoded as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $country = strtoupper( sanitize_text_field( $entry['country'] ?? '' ) );
            $url     = esc_url_raw( $entry['url'] ?? '' );
            if ( preg_match( '/^[A-Z]{2}$/', $country ) && ! empty( $url ) ) {
                $clean[] = [ 'country' => $country, 'url' => $url ];
            }
        }

        return wp_json_encode( $clean );
    }

    /**
     * _rar_geo_enabled only ever controls whether the classic Edit
     * screen's GEO panel renders expanded — the redirect engine itself
     * reads _rar_geo directly and doesn't consult this flag at all (see
     * Cogito_RAR_Redirect_Engine::maybe_redirect()). Derives it from
     * whatever _rar_geo now actually contains after this request's own
     * writes (and after enforce_track_only_and_dependents() above has
     * had its chance to wipe it), rather than requiring a REST caller to
     * separately track and set a flag that has no functional effect of
     * its own — one less thing to get wrong, and the classic screen
     * still shows the right thing either way.
     *
     * @param WP_Post $post
     */
    public static function sync_geo_enabled_flag( $post ) {
        if ( self::CPT !== $post->post_type ) {
            return;
        }

        $geo     = json_decode( (string) get_post_meta( $post->ID, '_rar_geo', true ), true );
        $enabled = ( is_array( $geo ) && ! empty( $geo ) ) ? '1' : '0';

        if ( $enabled !== get_post_meta( $post->ID, '_rar_geo_enabled', true ) ) {
            update_post_meta( $post->ID, '_rar_geo_enabled', $enabled );
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
     * REST unless an auth_callback says otherwise.
     *
     * The callback deliberately does NOT use current_user_can('edit_post',
     * $post_id) — WordPress's own meta-capability resolution for "can this
     * user edit this specific post." Live testing (Oct 2026) found that
     * check silently denying the Assistant role access to its own,
     * just-created post on this custom (non-'post') capability_type —
     * meta wrote/read fine for the two fields this plugin sets directly
     * via update_post_meta() elsewhere (bypassing this check entirely),
     * but not for anything routed through this auth_callback, including
     * on a plain GET of an already-existing post well after any
     * insert-time timing could plausibly be the cause. Root cause not
     * fully pinned down without a debugger on the live site; this
     * sidesteps it with a direct, equivalent check instead of
     * reproducing whatever that resolution is getting wrong: the same
     * "own post, or edit_others" rule, via a plain capability check plus
     * a direct post_author comparison, rather than WordPress's own
     * meta-cap chain for it.
     */
    public static function register_meta_fields() {
        $auth_callback = function ( $allowed, $meta_key, $post_id ) {
            if ( ! current_user_can( 'edit_rar_redirects' ) ) {
                return false;
            }
            $post = get_post( $post_id );
            if ( ! $post ) {
                return false;
            }
            if ( (int) $post->post_author === get_current_user_id() ) {
                return true;
            }
            return current_user_can( 'edit_others_rar_redirects' );
        };

        $string_fields = [ '_rar_target', '_rar_notes', '_rar_rotation' ];
        foreach ( $string_fields as $key ) {
            register_post_meta( self::CPT, $key, [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'string',
                'auth_callback' => $auth_callback,
            ] );
        }

        // GEO targeting rules: a JSON-encoded array of {country, url}
        // objects (country an ISO 3166 alpha-2 code, e.g. "US"). Validated
        // on the way in with the exact same rule the classic Edit screen's
        // own save handler applies (class-metabox-save.php) — a 2-letter
        // uppercase code and a non-empty URL — so a REST submission can't
        // store something the redirect engine would silently never match.
        // An invalid or unparseable submission sanitizes down to '[]'
        // rather than erroring, same as an empty/all-rejected classic
        // submission would.
        register_post_meta( self::CPT, '_rar_geo', [
            'show_in_rest'      => true,
            'single'            => true,
            'type'              => 'string',
            'auth_callback'     => $auth_callback,
            'sanitize_callback' => [ self::class, 'sanitize_geo_meta' ],
        ] );

        // Free-text campaign metadata — which advertiser/brand a link
        // belongs to, and which affiliate/ad network it's tracked
        // through (see Cogito_RAR_Networks for the Edit screen's
        // suggestion list; REST doesn't validate against it, since both
        // fields are free text there too).
        $brand_network_fields = [ '_rar_brand', '_rar_network' ];
        foreach ( $brand_network_fields as $key ) {
            register_post_meta( self::CPT, $key, [
                'show_in_rest'      => true,
                'single'            => true,
                'type'              => 'string',
                'auth_callback'     => $auth_callback,
                'sanitize_callback' => 'sanitize_text_field',
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
                if ( in_array( $value, [ 301, 302, 307 ], true ) ) {
                    return $value;
                }
                // Falls back to the site's configured default (see
                // Cogito_RAR_Settings_Defaults), not a hardcoded 302 —
                // this only runs when _rar_type was actually SENT with
                // an invalid value; see force_default_type_if_missing()
                // for the separate case of it being omitted entirely.
                return class_exists( 'Cogito_RAR_Settings_Defaults' )
                    ? (int) get_option( Cogito_RAR_Settings_Defaults::OPTION_REDIRECT_TYPE, 307 )
                    : 307;
            },
        ] );
    }

    /**
     * Adds a read-only 'edit_link' field to every REST response/listing
     * for this post type — the wp-admin Edit-screen URL, so a client
     * (e.g. the Assistant handing a batch of created links back to Nate
     * to review) doesn't have to construct admin_url('post.php?post=…
     * &action=edit') by hand.
     *
     * Deliberately does NOT use get_edit_post_link() — it gates on
     * current_user_can('edit_post', $id), the exact meta-cap resolution
     * already found (see register_meta_fields()'s docblock) to silently
     * return false for the Assistant role on its own post on this
     * custom (non-'post') capability_type, which would make the field
     * disappear precisely for the account that most needs it. The URL
     * itself discloses nothing a post ID doesn't already imply — opening
     * it still goes through wp-admin's own normal login and capability
     * check — so it's built directly rather than gated a second time
     * here.
     */
    public static function register_edit_link_field() {
        register_rest_field( self::CPT, 'edit_link', [
            'get_callback' => function ( $object ) {
                return admin_url( 'post.php?post=' . (int) $object['id'] . '&action=edit' );
            },
            'schema' => [
                'description' => 'The wp-admin Edit-screen URL for this RARLink.',
                'type'        => 'string',
                'format'      => 'uri',
                'context'     => [ 'view', 'edit' ],
            ],
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

    /**
     * Writes the site's configured default redirect type on a brand-new
     * REST-created link when the request never sent _rar_type at all.
     * _rar_type's own sanitize_callback (see register_meta_fields())
     * only ever runs on a value that was actually submitted — omitting
     * the field entirely means WordPress never touches that meta key,
     * so it's left genuinely missing rather than set to anything, and
     * Cogito_RAR_Redirect_Engine falls back to a plain 302 for a link
     * whose _rar_type can't be read as one of the three valid codes.
     * Every account that can create a link this way is affected, not
     * just the restricted Assistant role, so this isn't gated on
     * publish capability the way the Active default above is.
     *
     * Only fires on create ($creating), and only if nothing has set
     * _rar_type by the time this runs — never touches a link that
     * already has a type, saved or otherwise.
     *
     * @param WP_Post         $post
     * @param WP_REST_Request $request
     * @param bool            $creating
     */
    public static function force_default_type_if_missing( $post, $request, $creating ) {
        if ( self::CPT !== $post->post_type || ! $creating ) {
            return;
        }

        if ( '' !== get_post_meta( $post->ID, '_rar_type', true ) ) {
            return;
        }

        $default_type = class_exists( 'Cogito_RAR_Settings_Defaults' )
            ? (int) get_option( Cogito_RAR_Settings_Defaults::OPTION_REDIRECT_TYPE, 307 )
            : 307;

        update_post_meta( $post->ID, '_rar_type', $default_type );
    }
}
