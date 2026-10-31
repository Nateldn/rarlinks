<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Settings_Networks {

    public static function init() {
        add_action( 'rar_settings_render_tab_networks', [ self::class, 'render' ] );
        add_action( 'admin_init', [ self::class, 'maybe_handle_save' ] );
        add_action( 'admin_init', [ self::class, 'maybe_handle_test' ] );
        add_action( 'admin_init', [ self::class, 'maybe_handle_sync' ] );
    }

    private static function tab_url() {
        return add_query_arg(
            [ 'post_type' => 'rar_redirect', 'page' => 'rar_settings', 'tab' => 'networks' ],
            admin_url( 'edit.php' )
        );
    }

    private static function guard( $nonce_field, $nonce_action ) {
        if ( ! isset( $_POST[ $nonce_field ] ) ) {
            return false;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST[ $nonce_field ] ), $nonce_action ) ) {
            wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
        }
        return true;
    }

    public static function maybe_handle_save() {
        if ( ! self::guard( 'rar_networks_settings_nonce', 'rar_networks_settings' ) ) {
            return;
        }

        $provider_id = isset( $_POST['rar_network_provider'] ) ? sanitize_key( wp_unslash( $_POST['rar_network_provider'] ) ) : '';
        $provider    = Cogito_RAR_Affiliate_Networks::get( $provider_id );

        if ( null === $provider ) {
            wp_die( 'Unknown affiliate network.', '', [ 'response' => 400 ] );
        }

        $provider->handle_settings_save();

        wp_safe_redirect( add_query_arg( 'saved', 1, self::tab_url() ) );
        exit;
    }

    public static function maybe_handle_test() {
        if ( ! self::guard( 'rar_networks_test_nonce', 'rar_networks_test' ) ) {
            return;
        }

        $result = Cogito_RAR_Rakuten_API::test_connection();

        $args = is_wp_error( $result )
            ? [ 'test' => 'fail', 'msg' => rawurlencode( $result->get_error_message() ) ]
            : [ 'test' => 'ok', 'count' => (int) $result ];

        wp_safe_redirect( add_query_arg( $args, self::tab_url() ) );
        exit;
    }

    public static function maybe_handle_sync() {
        if ( ! self::guard( 'rar_networks_sync_nonce', 'rar_networks_sync' ) ) {
            return;
        }

        $result = Cogito_RAR_Rakuten_API::sync_advertisers();

        $args = is_wp_error( $result )
            ? [ 'sync' => 'fail', 'msg' => rawurlencode( $result->get_error_message() ) ]
            : [ 'sync' => 'ok', 'count' => (int) $result ];

        wp_safe_redirect( add_query_arg( $args, self::tab_url() ) );
        exit;
    }

    private static function render_notices() {
        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Credentials saved.</p></div>';
        }

        $message = isset( $_GET['msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['msg'] ) ) ) : '';

        if ( isset( $_GET['test'] ) ) {
            if ( 'ok' === $_GET['test'] ) {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html( sprintf(
                    'Connected to Rakuten. %d advertiser%s visible to this account.',
                    absint( $_GET['count'] ?? 0 ),
                    1 === absint( $_GET['count'] ?? 0 ) ? '' : 's'
                ) );
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
            }
        }

        if ( isset( $_GET['sync'] ) ) {
            if ( 'ok' === $_GET['sync'] ) {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html( sprintf(
                    '%d deep-link-capable advertiser%s synced.',
                    absint( $_GET['count'] ?? 0 ),
                    1 === absint( $_GET['count'] ?? 0 ) ? '' : 's'
                ) );
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
            }
        }
    }

    public static function render() {
        self::render_notices();

        $advertisers = Cogito_RAR_Rakuten_API::get_advertisers();
        $synced      = Cogito_RAR_Rakuten_API::get_last_synced();

        echo '<div class="rar-conversions">';

        echo '<div class="rar-card">';
        echo '<h3>Affiliate Networks</h3>';
        echo '<p>API credentials for generating affiliate links directly from the RARLink edit screen. Credentials are stored on this server and are never exposed through the REST API.</p>';
        echo '</div>';

        foreach ( Cogito_RAR_Affiliate_Networks::all() as $provider ) {
            echo '<div class="rar-card">';
            echo '<h4>' . esc_html( $provider->label() ) . '</h4>';
            echo '<form method="post" action="' . esc_url( self::tab_url() ) . '">';
            wp_nonce_field( 'rar_networks_settings', 'rar_networks_settings_nonce' );
            echo '<input type="hidden" name="rar_network_provider" value="' . esc_attr( $provider->id() ) . '">';
            $provider->render_settings();
            submit_button( 'Save credentials' );
            echo '</form>';
            echo '</div>';
        }

        echo '<div class="rar-card">';
        echo '<h4>Connection</h4>';

        if ( ! Cogito_RAR_Rakuten_API::has_credentials() ) {
            echo '<p>Enter and save all three credentials above first.</p>';
        } else {
            echo '<form method="post" action="' . esc_url( self::tab_url() ) . '" style="display:inline-block; margin-right:8px;">';
            wp_nonce_field( 'rar_networks_test', 'rar_networks_test_nonce' );
            submit_button( 'Test connection', 'secondary', 'submit', false );
            echo '</form>';

            echo '<form method="post" action="' . esc_url( self::tab_url() ) . '" style="display:inline-block;">';
            wp_nonce_field( 'rar_networks_sync', 'rar_networks_sync_nonce' );
            submit_button( 'Sync advertisers', 'secondary', 'submit', false );
            echo '</form>';

            echo '<p class="description" style="margin-top:12px;">';
            if ( $synced > 0 ) {
                echo esc_html( sprintf(
                    '%d advertiser%s stored. Last synced %s ago.',
                    count( $advertisers ),
                    1 === count( $advertisers ) ? '' : 's',
                    human_time_diff( $synced, time() )
                ) );
            } else {
                echo 'No advertisers synced yet. Sync to enable automatic advertiser matching when generating links.';
            }
            echo '</p>';
        }

        echo '</div>';

        if ( ! empty( $advertisers ) ) {
            $search = isset( $_GET['adv_search'] ) ? sanitize_text_field( wp_unslash( $_GET['adv_search'] ) ) : '';

            if ( '' !== $search ) {
                $needle      = strtolower( $search );
                $advertisers = array_values( array_filter( $advertisers, static function ( $advertiser ) use ( $needle ) {
                    return false !== stripos( $advertiser['name'], $needle )
                        || false !== stripos( $advertiser['host'], $needle )
                        || $advertiser['id'] === $needle;
                } ) );
            }

            echo '<div class="rar-card">';
            echo '<h4>Synced advertisers</h4>';

            echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '" style="margin-bottom:12px;">';
            echo '<input type="hidden" name="post_type" value="rar_redirect">';
            echo '<input type="hidden" name="page" value="rar_settings">';
            echo '<input type="hidden" name="tab" value="networks">';
            echo '<input type="search" name="adv_search" value="' . esc_attr( $search ) . '" placeholder="Search name, domain or ID" style="width:280px;">';
            submit_button( 'Search advertisers', 'secondary', 'submit', false );
            if ( '' !== $search ) {
                echo ' <a href="' . esc_url( self::tab_url() ) . '">Clear</a>';
            }
            echo '</form>';

            if ( empty( $advertisers ) ) {
                echo '<p>No advertiser matches that search.</p>';
            } else {
                $shown = array_slice( $advertisers, 0, 100 );

                echo '<p class="description">';
                echo esc_html( '' !== $search
                    ? sprintf( '%d match%s.', count( $advertisers ), 1 === count( $advertisers ) ? '' : 'es' )
                    : sprintf( '%d advertisers. Showing the first %d — search to narrow.', count( $advertisers ), count( $shown ) )
                );
                echo '</p>';

                echo '<table class="widefat striped"><thead><tr><th>Advertiser</th><th>Domain</th><th>ID</th></tr></thead><tbody>';
                foreach ( $shown as $advertiser ) {
                    echo '<tr>';
                    echo '<td>' . esc_html( $advertiser['name'] ) . '</td>';
                    echo '<td>' . esc_html( $advertiser['host'] ) . '</td>';
                    echo '<td><code>' . esc_html( $advertiser['id'] ) . '</code></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }

            echo '</div>';
        }

        echo '</div>';
    }
}
