<?php
/**
 * Capabilities and roles for the RARLink post type, now that it has its
 * own capability namespace instead of sharing every capability 1:1 with
 * core Posts (see Cogito_RAR_CPT_Registrar — 'capability_type' changed
 * from 'post' to 'rar_redirect'). Handles two things:
 *
 * 1. Backfilling Administrator and Editor with the full new capability
 *    set, so that change is invisible to every existing account — without
 *    this, switching capability_type away from the shared 'post' would
 *    silently strip both roles of the RARLinks access they've always had
 *    (WordPress does not carry old capabilities over automatically when a
 *    post type's capability_type changes).
 * 2. A new, narrowly-scoped "RARLinks Assistant" role, built for an
 *    external integration (e.g. a bot account authenticating via an
 *    Application Password) that should be able to create and edit its own
 *    unpublished drafts, and nothing more — no publishing, no editing or
 *    deleting anyone else's links, no deleting at all. This is intentionally
 *    built through WordPress's own capability/role system rather than
 *    custom permission checks scattered through the codebase: once the
 *    right primitive capabilities are (and aren't) granted, WordPress's
 *    own map_meta_cap() enforces all of the above by itself, including
 *    the "own drafts only, not after it's published" boundary — a
 *    published post additionally requires edit_published_rar_redirects,
 *    which this role is deliberately never given.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Roles {

    const ASSISTANT_ROLE = 'rar_links_assistant';

    /**
     * The full primitive capability set WordPress derives for a post type
     * registered with capability_type => 'rar_redirect' and
     * map_meta_cap => true. Hardcoded (matching WP core's own
     * deterministic naming for a string capability_type) rather than read
     * back from get_post_type_object() — this runs on plugin activation,
     * which can't rely on the CPT already being registered in the same
     * request.
     */
    const FULL_CAPABILITIES = [
        'edit_rar_redirects',
        'edit_others_rar_redirects',
        'publish_rar_redirects',
        'read_private_rar_redirects',
        'delete_rar_redirects',
        'delete_private_rar_redirects',
        'delete_published_rar_redirects',
        'delete_others_rar_redirects',
        'edit_private_rar_redirects',
        'edit_published_rar_redirects',
        'create_rar_redirects',
    ];

    /**
     * What the Assistant role actually gets: enough to create a link and
     * edit its own drafts, nothing that would let it publish, delete, or
     * touch anyone else's. 'read' is WordPress's own generic capability
     * every logged-in account needs for basic access (wp-admin, the REST
     * API's own authentication) — unrelated to RARLinks specifically.
     */
    const ASSISTANT_CAPABILITIES = [
        'read'                => true,
        'create_rar_redirects' => true,
        'edit_rar_redirects'   => true,
    ];

    public static function init() {
        add_action( 'admin_init', [ self::class, 'maybe_sync_capabilities' ] );
    }

    public static function on_activate() {
        self::grant_full_capabilities( 'administrator' );
        self::grant_full_capabilities( 'editor' );
        self::create_assistant_role();
    }

    /**
     * Self-healing, mirroring this plugin's existing pattern for schema
     * upgrades: re-applies the capability grants and (re)creates the
     * Assistant role if either drifted from what they should be — so a
     * plugin update (not just a fresh activation) still ends up correct,
     * and so accidentally removing/editing the role in wp-admin's own
     * role tools gets put back on the next admin page load.
     */
    public static function maybe_sync_capabilities() {
        if ( get_option( 'rar_roles_synced_version' ) === '1.0' ) {
            return;
        }

        self::on_activate();
        update_option( 'rar_roles_synced_version', '1.0' );
    }

    private static function grant_full_capabilities( $role_name ) {
        $role = get_role( $role_name );
        if ( ! $role ) {
            return;
        }
        foreach ( self::FULL_CAPABILITIES as $cap ) {
            $role->add_cap( $cap );
        }
    }

    private static function create_assistant_role() {
        // add_role() is a no-op if the role already exists — remove it
        // first so re-running this (the self-heal above) actually resets
        // its capabilities to exactly ASSISTANT_CAPABILITIES, rather than
        // only ever adding to whatever it drifted to.
        remove_role( self::ASSISTANT_ROLE );
        add_role( self::ASSISTANT_ROLE, 'RARLinks Assistant', self::ASSISTANT_CAPABILITIES );
    }
}
