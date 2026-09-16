<?php
/**
 * AJAX handlers for the self-service Tracked Events UI on the Conversions
 * settings tab. Each row saves independently — create an event, add or
 * remove one tracked class/ID, update its custom parameter names, or
 * delete the whole event — with no full-page form submit/reload.
 *
 * An event's name is the lookup key for every action below, so it has to
 * stay unique and is never editable again after create_event() — the
 * settings page itself renders it as plain text, not an input, for any
 * already-existing event.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Tracked_Events_Ajax {

    const NONCE_ACTION = 'rar_tracked_events_nonce';

    public static function init() {
        add_action( 'wp_ajax_rar_create_tracked_event', [ self::class, 'create_event' ] );
        add_action( 'wp_ajax_rar_add_tracked_group', [ self::class, 'add_group' ] );
        add_action( 'wp_ajax_rar_remove_tracked_group', [ self::class, 'remove_group' ] );
        add_action( 'wp_ajax_rar_save_tracked_event_fields', [ self::class, 'save_fields' ] );
        add_action( 'wp_ajax_rar_delete_tracked_event', [ self::class, 'delete_event' ] );
    }

    private static function authorize() {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token.' ], 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }
    }

    /** @return array The full raw event-definitions array. */
    private static function load_events() {
        return Cogito_RAR_Conversion_Capture::get_event_definitions_raw();
    }

    private static function save_events( array $events ) {
        update_option( Cogito_RAR_Conversion_Capture::OPTION_EVENT_DEFINITIONS, $events );
    }

    /**
     * @param array  $events
     * @param string $name Already-sanitised event name.
     * @return int|null
     */
    private static function find_event_index( array $events, $name ) {
        foreach ( $events as $i => $event ) {
            if ( Cogito_RAR_Conversion_Capture::sanitize_event_name( $event['name'] ?? '' ) === $name ) {
                return $i;
            }
        }
        return null;
    }

    private static function posted_event_name() {
        return isset( $_POST['event_name'] ) ? Cogito_RAR_Conversion_Capture::sanitize_event_name( wp_unslash( $_POST['event_name'] ) ) : '';
    }

    /**
     * Creates a brand-new, empty event.
     */
    public static function create_event() {
        self::authorize();

        $name = isset( $_POST['name'] ) ? Cogito_RAR_Conversion_Capture::sanitize_event_name( wp_unslash( $_POST['name'] ) ) : '';
        if ( '' === $name ) {
            wp_send_json_error( [ 'message' => 'Enter a valid event name (letters, numbers, underscores only).' ], 400 );
        }

        $events = self::load_events();
        if ( null !== self::find_event_index( $events, $name ) ) {
            wp_send_json_error( [ 'message' => 'An event with that name already exists.' ], 409 );
        }
        if ( count( $events ) >= Cogito_RAR_Conversion_Capture::MAX_EVENTS ) {
            wp_send_json_error( [ 'message' => 'Maximum number of events reached.' ], 400 );
        }

        $events[] = [ 'name' => $name ];
        self::save_events( $events );

        wp_send_json_success( [ 'name' => $name ] );
    }

    /**
     * Adds one tracked class/ID line ("group" — may itself be several
     * space-separated tokens, chained) to an existing event.
     */
    public static function add_group() {
        self::authorize();

        $event_name = self::posted_event_name();
        $group_raw  = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';

        $events = self::load_events();
        $index  = self::find_event_index( $events, $event_name );
        if ( null === $index ) {
            wp_send_json_error( [ 'message' => 'Event not found — refresh the page.' ], 404 );
        }

        // parse_identifier_groups() only ever sees one line here, so at
        // most one group comes back.
        $groups = Cogito_RAR_Conversion_Capture::parse_identifier_groups( $group_raw );
        if ( empty( $groups ) ) {
            wp_send_json_error( [ 'message' => 'Enter a class name or ID first.' ], 400 );
        }
        $new_group = $groups[0];

        $existing_raw = rtrim( (string) ( $events[ $index ]['identifiers'] ?? '' ), "\r\n" );
        $events[ $index ]['identifiers'] = ( '' === $existing_raw ? '' : $existing_raw . "\n" ) . implode( ' ', $new_group );

        self::save_events( $events );

        wp_send_json_success( [
            'group' => $new_group,
            'label' => implode( ' + ', $new_group ),
        ] );
    }

    /**
     * Removes one exact group, matched by its raw (space-joined) token
     * string as sent back by add_group()/rendered by PHP — not an index,
     * so this can't drift if another tab changed the list meanwhile.
     */
    public static function remove_group() {
        self::authorize();

        $event_name  = self::posted_event_name();
        $group_match = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';

        $events = self::load_events();
        $index  = self::find_event_index( $events, $event_name );
        if ( null === $index ) {
            wp_send_json_error( [ 'message' => 'Event not found — refresh the page.' ], 404 );
        }

        $groups   = Cogito_RAR_Conversion_Capture::parse_identifier_groups( (string) ( $events[ $index ]['identifiers'] ?? '' ) );
        $filtered = array_values( array_filter( $groups, function ( $group ) use ( $group_match ) {
            return implode( ' ', $group ) !== $group_match;
        } ) );

        $events[ $index ]['identifiers'] = implode( "\n", array_map( function ( $group ) {
            return implode( ' ', $group );
        }, $filtered ) );

        self::save_events( $events );

        wp_send_json_success( [] );
    }

    /**
     * Updates just the custom parameter-name overrides for one event.
     */
    public static function save_fields() {
        self::authorize();

        $event_name = self::posted_event_name();

        $events = self::load_events();
        $index  = self::find_event_index( $events, $event_name );
        if ( null === $index ) {
            wp_send_json_error( [ 'message' => 'Event not found — refresh the page.' ], 404 );
        }

        foreach ( array_keys( Cogito_RAR_Conversion_Capture::DEFAULT_FIELD_NAMES ) as $signal ) {
            $key      = 'field_' . $signal;
            $override = isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
            if ( '' === $override ) {
                unset( $events[ $index ][ $key ] );
            } else {
                $events[ $index ][ $key ] = Cogito_RAR_Conversion_Capture::sanitize_event_name( $override );
            }
        }

        self::save_events( $events );

        wp_send_json_success( [] );
    }

    /**
     * Deletes an event entirely.
     */
    public static function delete_event() {
        self::authorize();

        $event_name = self::posted_event_name();

        $events = self::load_events();
        $index  = self::find_event_index( $events, $event_name );
        if ( null === $index ) {
            wp_send_json_error( [ 'message' => 'Event not found — refresh the page.' ], 404 );
        }

        array_splice( $events, $index, 1 );
        self::save_events( $events );

        wp_send_json_success( [] );
    }
}
