<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Cogito_RAR_Affiliate_Provider {

    public function id();

    public function label();

    public function is_configured();

    public function render_settings();

    public function handle_settings_save();

    public function match_advertiser( $url );

    public function sub_id_label();

    public function default_sub_id();

    public function generate( $url, $advertiser_id = '', $name = '', $sub_id = '' );
}
