<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Rakuten_Bulk {

    const PAGE_SLUG  = 'rar_bulk_links';
    const MAX_ROWS   = 25;
    const THROTTLE_U = 700000;

    public static function init() {
        add_action( 'admin_menu', [ self::class, 'add_page' ], 12 );
        add_action( 'admin_init', [ self::class, 'maybe_handle_create' ] );
    }

    public static function add_page() {
        add_submenu_page(
            'edit.php?post_type=rar_redirect',
            'Bulk Affiliate Links',
            'Bulk Affiliate Links',
            'edit_rar_redirects',
            self::PAGE_SLUG,
            [ self::class, 'render' ]
        );
    }

    private static function page_url() {
        return add_query_arg(
            [ 'post_type' => 'rar_redirect', 'page' => self::PAGE_SLUG ],
            admin_url( 'edit.php' )
        );
    }

    public static function parse_input( $raw ) {
        $rows  = [];
        $lines = preg_split( '/\r\n|\r|\n/', (string) $raw );

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            $title = '';
            if ( false !== strpos( $line, '|' ) ) {
                $parts = explode( '|', $line, 2 );
                $line  = trim( $parts[0] );
                $title = trim( $parts[1] );
            }

            if ( ! wp_http_validate_url( $line ) ) {
                $rows[] = [
                    'url'        => $line,
                    'title'      => $title,
                    'advertiser' => null,
                    'error'      => 'Not a valid URL.',
                ];
                continue;
            }

            $advertiser = Cogito_RAR_Rakuten_API::resolve_advertiser_for_url( $line );

            $rows[] = [
                'url'        => $line,
                'title'      => $title,
                'advertiser' => $advertiser,
                'error'      => null === $advertiser
                    ? 'No synced advertiser matches ' . Cogito_RAR_Rakuten_API::normalise_host( $line ) . '.'
                    : '',
            ];
        }

        return $rows;
    }

    private static function derive_title( $row ) {
        if ( '' !== $row['title'] ) {
            return $row['title'];
        }

        $path     = (string) wp_parse_url( $row['url'], PHP_URL_PATH );
        $segments = array_values( array_filter( explode( '/', $path ) ) );
        $segment  = empty( $segments ) ? '' : trim( (string) end( $segments ) );
        $segment  = ucwords( str_replace( [ '-', '_' ], ' ', preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $segment ) ) );

        $advertiser = isset( $row['advertiser']['name'] ) ? $row['advertiser']['name'] : 'Rakuten';

        return '' !== $segment
            ? sprintf( '%s – %s [Rakuten Advertising]', $advertiser, $segment )
            : sprintf( '%s [Rakuten Advertising]', $advertiser );
    }

    private static function create_link( $row ) {
        $title = self::derive_title( $row );

        $generated = Cogito_RAR_Rakuten_Links::generate(
            $row['url'],
            isset( $row['advertiser']['id'] ) ? $row['advertiser']['id'] : '',
            $title
        );

        if ( is_wp_error( $generated ) ) {
            return $generated;
        }

        $post_id = wp_insert_post( [
            'post_type'   => 'rar_redirect',
            'post_status' => 'draft',
            'post_title'  => $title,
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $defaults_exist = class_exists( 'Cogito_RAR_Settings_Defaults' );

        update_post_meta( $post_id, '_rar_target', $generated['deep_link'] );
        update_post_meta( $post_id, '_rar_network', Cogito_RAR_Rakuten_Links::NETWORK_LABEL );
        update_post_meta( $post_id, '_rar_brand', $generated['advertiser_name'] );
        update_post_meta( $post_id, '_rar_notes', 'Generated from ' . $row['url'] );

        update_post_meta( $post_id, '_rar_type', $defaults_exist
            ? (int) get_option( Cogito_RAR_Settings_Defaults::OPTION_REDIRECT_TYPE, 307 )
            : 307 );
        update_post_meta( $post_id, '_rar_active', $defaults_exist
            ? ( '1' === get_option( Cogito_RAR_Settings_Defaults::OPTION_ACTIVE, '1' ) ? '1' : '0' )
            : '1' );
        update_post_meta( $post_id, '_rar_nofollow', $defaults_exist
            ? ( '1' === get_option( Cogito_RAR_Settings_Defaults::OPTION_NOFOLLOW, '1' ) ? '1' : '0' )
            : '1' );
        update_post_meta( $post_id, '_rar_sponsored', $defaults_exist
            ? ( '1' === get_option( Cogito_RAR_Settings_Defaults::OPTION_SPONSORED, '1' ) ? '1' : '0' )
            : '1' );

        update_post_meta( $post_id, '_rar_track_only', '0' );
        update_post_meta( $post_id, '_rar_geo_enabled', '0' );
        update_post_meta( $post_id, '_rar_rotation_enabled', '0' );
        update_post_meta( $post_id, '_rar_geo', '[]' );
        update_post_meta( $post_id, '_rar_rotation', '[]' );

        return [
            'post_id'    => $post_id,
            'title'      => $title,
            'deep_link'  => $generated['deep_link'],
            'advertiser' => $generated['advertiser_name'],
            'edit_link'  => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
        ];
    }

    public static function maybe_handle_create() {
        if ( ! isset( $_POST['rar_bulk_create_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST['rar_bulk_create_nonce'] ), 'rar_bulk_create' ) ) {
            wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
        }
        if ( ! current_user_can( 'edit_rar_redirects' ) ) {
            wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
        }

        $raw  = isset( $_POST['rar_bulk_urls'] ) ? wp_unslash( $_POST['rar_bulk_urls'] ) : '';
        $rows = array_slice( self::parse_input( $raw ), 0, self::MAX_ROWS );

        $created = [];
        $failed  = [];

        foreach ( $rows as $index => $row ) {
            if ( null === $row['advertiser'] ) {
                $failed[] = [ 'url' => $row['url'], 'error' => $row['error'] ];
                continue;
            }

            $result = self::create_link( $row );

            if ( is_wp_error( $result ) ) {
                $failed[] = [ 'url' => $row['url'], 'error' => $result->get_error_message() ];
            } else {
                $created[] = $result;
            }

            if ( $index < count( $rows ) - 1 ) {
                usleep( self::THROTTLE_U );
            }
        }

        set_transient( 'rar_bulk_result_' . get_current_user_id(), [
            'created' => $created,
            'failed'  => $failed,
        ], 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( add_query_arg( 'done', 1, self::page_url() ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'edit_rar_redirects' ) ) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>Bulk Affiliate Links</h1>';

        if ( ! Cogito_RAR_Rakuten_API::has_credentials() ) {
            echo '<div class="notice notice-warning"><p>Add your Rakuten credentials under <a href="'
                . esc_url( add_query_arg( [ 'post_type' => 'rar_redirect', 'page' => 'rar_settings', 'tab' => 'networks' ], admin_url( 'edit.php' ) ) )
                . '">Settings → Affiliate Networks</a> first.</p></div>';
            echo '</div>';
            return;
        }

        if ( isset( $_GET['done'] ) ) {
            self::render_result();
        }

        $previewing = isset( $_POST['rar_bulk_preview_nonce'] )
            && wp_verify_nonce( sanitize_key( $_POST['rar_bulk_preview_nonce'] ), 'rar_bulk_preview' );

        $raw     = $previewing && isset( $_POST['rar_bulk_urls'] ) ? wp_unslash( $_POST['rar_bulk_urls'] ) : '';
        $preview = ( $previewing && '' !== trim( (string) $raw ) ) ? self::parse_input( $raw ) : [];

        echo '<div class="rar-conversions">';
        echo '<div class="rar-card">';
        echo '<p>Paste one merchant URL per line. Optionally add a title after a pipe: <code>https://example.com/product | My title</code>.</p>';
        echo '<p>Each line is matched to a synced Rakuten advertiser by domain. Up to ' . (int) self::MAX_ROWS . ' links per run.</p>';

        echo '<form method="post" action="' . esc_url( self::page_url() ) . '">';
        wp_nonce_field( 'rar_bulk_preview', 'rar_bulk_preview_nonce' );
        echo '<textarea name="rar_bulk_urls" rows="8" style="width:100%; font-family:monospace;">' . esc_textarea( $raw ) . '</textarea>';
        submit_button( 'Preview', 'secondary' );
        echo '</form>';
        echo '</div>';

        if ( ! empty( $preview ) ) {
            self::render_preview( $preview, $raw );
        }

        echo '</div>';
        echo '</div>';
    }

    private static function render_preview( $rows, $raw ) {
        $ready = 0;

        echo '<div class="rar-card">';
        echo '<h4>Preview</h4>';
        echo '<table class="widefat striped"><thead><tr><th>URL</th><th>Advertiser</th><th>Title</th><th>Status</th></tr></thead><tbody>';

        foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
            $ok = null !== $row['advertiser'];
            if ( $ok ) {
                $ready++;
            }

            echo '<tr>';
            echo '<td style="word-break:break-all;">' . esc_html( $row['url'] ) . '</td>';
            echo '<td>' . esc_html( $ok ? $row['advertiser']['name'] : '—' ) . '</td>';
            echo '<td>' . esc_html( self::derive_title( $row ) ) . '</td>';
            echo '<td>' . ( $ok
                ? '<span style="color:#1e7e34;">Ready</span>'
                : '<span style="color:#b32d2e;">' . esc_html( $row['error'] ) . '</span>' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ( count( $rows ) > self::MAX_ROWS ) {
            echo '<p class="description">Only the first ' . (int) self::MAX_ROWS . ' lines will be processed. Run the rest in a second batch.</p>';
        }

        if ( $ready > 0 ) {
            echo '<form method="post" action="' . esc_url( self::page_url() ) . '" style="margin-top:12px;">';
            wp_nonce_field( 'rar_bulk_create', 'rar_bulk_create_nonce' );
            echo '<input type="hidden" name="rar_bulk_urls" value="' . esc_attr( $raw ) . '">';
            submit_button( sprintf( 'Generate and create %d draft link%s', $ready, 1 === $ready ? '' : 's' ), 'primary', 'submit', false );
            echo '<p class="description" style="margin-top:8px;">Links are generated in Rakuten and saved as drafts here. Nothing goes live until you publish it.</p>';
            echo '</form>';
        }

        echo '</div>';
    }

    private static function render_result() {
        $result = get_transient( 'rar_bulk_result_' . get_current_user_id() );
        if ( ! is_array( $result ) ) {
            return;
        }
        delete_transient( 'rar_bulk_result_' . get_current_user_id() );

        if ( ! empty( $result['created'] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html( sprintf(
                '%d draft link%s created.',
                count( $result['created'] ),
                1 === count( $result['created'] ) ? '' : 's'
            ) ) . '</p></div>';

            echo '<table class="widefat striped" style="margin-bottom:16px;"><thead><tr><th>Title</th><th>Advertiser</th><th>Deep link</th><th></th></tr></thead><tbody>';
            foreach ( $result['created'] as $row ) {
                echo '<tr>';
                echo '<td>' . esc_html( $row['title'] ) . '</td>';
                echo '<td>' . esc_html( $row['advertiser'] ) . '</td>';
                echo '<td style="word-break:break-all;">' . esc_html( $row['deep_link'] ) . '</td>';
                echo '<td><a href="' . esc_url( $row['edit_link'] ) . '">Edit</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if ( ! empty( $result['failed'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( sprintf(
                '%d line%s failed:',
                count( $result['failed'] ),
                1 === count( $result['failed'] ) ? '' : 's'
            ) ) . '</p><ul style="list-style:disc; margin-left:20px;">';
            foreach ( $result['failed'] as $row ) {
                echo '<li>' . esc_html( $row['url'] ) . ' — ' . esc_html( $row['error'] ) . '</li>';
            }
            echo '</ul></div>';
        }
    }
}
