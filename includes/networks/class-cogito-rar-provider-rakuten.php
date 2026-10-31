<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cogito_RAR_Provider_Rakuten implements Cogito_RAR_Affiliate_Provider {

    const ID    = 'rakuten';
    const LABEL = 'Rakuten Advertising';

    const SECRET_MASK = '********';

    public function id() {
        return self::ID;
    }

    public function label() {
        return self::LABEL;
    }

    public function is_configured() {
        return Cogito_RAR_Rakuten_API::has_credentials();
    }

    public function match_advertiser( $url ) {
        return Cogito_RAR_Rakuten_API::match_advertisers( $url );
    }

    public function generate( $url, $advertiser_id = '', $name = '' ) {
        return Cogito_RAR_Rakuten_API::create_deep_link( $url, $advertiser_id, $name );
    }

    public function get_advertiser_by_id( $id ) {
        return Cogito_RAR_Rakuten_API::get_advertiser_by_id( $id );
    }

    public function handle_settings_save() {
        $client_id = isset( $_POST['rar_rakuten_client_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['rar_rakuten_client_id'] ) )
            : '';
        update_option( Cogito_RAR_Rakuten_API::OPTION_CLIENT_ID, $client_id, false );

        $account_id = isset( $_POST['rar_rakuten_account_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['rar_rakuten_account_id'] ) )
            : '';
        update_option( Cogito_RAR_Rakuten_API::OPTION_ACCOUNT_ID, $account_id, false );

        $secret = isset( $_POST['rar_rakuten_client_secret'] )
            ? trim( wp_unslash( $_POST['rar_rakuten_client_secret'] ) )
            : '';
        if ( '' !== $secret && self::SECRET_MASK !== $secret ) {
            update_option( Cogito_RAR_Rakuten_API::OPTION_CLIENT_SECRET, sanitize_text_field( $secret ), false );
        }

        delete_transient( Cogito_RAR_Rakuten_API::TOKEN_TRANSIENT );
    }

    public function render_settings() {
        $client_id  = (string) get_option( Cogito_RAR_Rakuten_API::OPTION_CLIENT_ID, '' );
        $account_id = (string) get_option( Cogito_RAR_Rakuten_API::OPTION_ACCOUNT_ID, '' );
        $has_secret = '' !== (string) get_option( Cogito_RAR_Rakuten_API::OPTION_CLIENT_SECRET, '' );

        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="rar_rakuten_client_id">Client ID</label></th><td>';
        echo '<input type="text" class="regular-text" id="rar_rakuten_client_id" name="rar_rakuten_client_id" value="' . esc_attr( $client_id ) . '" autocomplete="off">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="rar_rakuten_client_secret">Client Secret</label></th><td>';
        echo '<input type="password" class="regular-text" id="rar_rakuten_client_secret" name="rar_rakuten_client_secret" value="' . ( $has_secret ? esc_attr( self::SECRET_MASK ) : '' ) . '" autocomplete="off">';
        echo '<p class="description">' . ( $has_secret ? 'A secret is saved. Leave as-is to keep it, or type a new one to replace it.' : 'Generated with your application in the Rakuten developer portal.' ) . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="rar_rakuten_account_id">Account ID</label></th><td>';
        echo '<input type="text" class="regular-text" id="rar_rakuten_account_id" name="rar_rakuten_account_id" value="' . esc_attr( $account_id ) . '" autocomplete="off">';
        echo '<p class="description">Sent to Rakuten as the token <code>scope</code>.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
    }
}
