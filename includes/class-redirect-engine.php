<?php
/**
 * Redirect logic engine: handles conditional redirect checks, GEO, rotation, fallback.
 *
 * @package Cogito_RAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cogito_RAR_Redirect_Engine {

	/**
	 * URL path prefix for vanity links, e.g. /go/pando-bf/. Set to '' to
	 * serve links at the site root (legacy behaviour). Single source of
	 * truth: the engine, the permalink filter, the admin previews and the
	 * content rewriter all read this.
	 */
	const PREFIX = 'go';

	/**
	 * Entry point for redirect logic (called early on template_redirect).
	 */
	public static function maybe_redirect() {
		if (
			is_admin() ||
			defined( 'REST_REQUEST' ) ||
			wp_doing_ajax() ||
			php_sapi_name() === 'cli'
		) {
			return;
		}

		$path = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );

		if ( '' === $path || strlen( $path ) > 100 || strpos( $path, "\0" ) !== false ) {
			return;
		}

		// Detect the optional /go/ prefix. Prefixed links are the canonical
		// form; a bare slug is the legacy form (old links already published
		// off-site — bookmarks, social posts, search results).
		$prefixed  = false;
		$slug_part = $path;
		if ( self::PREFIX !== '' && strpos( $path, self::PREFIX . '/' ) === 0 ) {
			$slug_part = substr( $path, strlen( self::PREFIX ) + 1 );
			$prefixed  = true;
		}

		// Our slugs are a single path segment — ignore anything deeper
		if ( '' === $slug_part || strpos( $slug_part, '/' ) !== false ) {
			return;
		}

		$slug = sanitize_title( $slug_part );
		if ( ! $slug ) {
			return;
		}

		// For a legacy (un-prefixed) hit, only intercept when WordPress itself
		// could not resolve the path — so a real page/post sharing the slug is
		// never shadowed. The /go/ namespace holds no real content, so the
		// prefixed route is exempt from this check.
		if ( ! $prefixed && ! is_404() ) {
			return;
		}

		$post = get_page_by_path( $slug, OBJECT, Cogito_RAR::CPT );
		if ( ! $post ) {
			return;
		}

		$is_active = get_post_meta( $post->ID, '_rar_active', true );
		if ( $is_active !== '1' ) {
			return;
		}

		self::handle_redirect_from_post( $post );
		exit;
	}

	/**
	 * Builds the canonical prefixed vanity URL for a slug, e.g. /go/pando-bf/.
	 * The single place the prefix is applied to outgoing links.
	 *
	 * @param string $slug The redirect post slug (post_name).
	 * @return string Absolute vanity URL.
	 */
	public static function vanity_url( $slug ) {
		$prefix = ( self::PREFIX !== '' ) ? self::PREFIX . '/' : '';
		return home_url( '/' . $prefix . $slug . '/' );
	}

	/**
	 * Handle redirect from a post object (GEO > rotation > fallback).
	 */
	public static function handle_redirect_from_post( $post ) {

		// Capture whether the visitor ARRIVED with the site cookie, before
		// the block below (or anything else) sets one. No cookie means they
		// never loaded a site page — a strong bot signal for the logger.
		$had_cookie = Cogito_RAR_SetCookie::was_present();

		// Set RAR visitor cookie if missing
		if ( empty( $_COOKIE['rar_uid'] ) || ! preg_match( '/^[a-f0-9]{32}$/', $_COOKIE['rar_uid'] ) ) {
			$uid = bin2hex( random_bytes( 16 ) );
			setcookie( 'rar_uid', $uid, [
				'expires'  => time() + 15552000,
				'path'     => '/',
				'domain'   => parse_url( home_url(), PHP_URL_HOST ),
				'secure'   => true,
				'httponly' => false,
				'samesite' => 'None',
			] );
			$_COOKIE['rar_uid'] = $uid;
		}

		$visitor_id = Cogito_RAR_SetCookie::get();

		$type = in_array( intval( get_post_meta( $post->ID, '_rar_type', true ) ), [301, 302, 307], true )
			? intval( get_post_meta( $post->ID, '_rar_type', true ) )
			: 302;

		$nofollow  = get_post_meta( $post->ID, '_rar_nofollow', true );
		$sponsored = get_post_meta( $post->ID, '_rar_sponsored', true );

		$geo = json_decode( get_post_meta( $post->ID, '_rar_geo', true ) ?: '[]', true );
		$rot = json_decode( get_post_meta( $post->ID, '_rar_rotation', true ) ?: '[]', true );

		$add_rel_header = function( $url ) use ( $nofollow, $sponsored ) {
			$rels = [];
			if ( $nofollow !== '0' )  $rels[] = 'nofollow';
			if ( $sponsored !== '0' ) $rels[] = 'sponsored';
			if ( $rels ) {
				$rel_string    = implode( ' ', array_map( 'sanitize_html_class', $rels ) );
				$sanitized_url = esc_url_raw( $url );
				if ( $rel_string && $sanitized_url ) {
					header( 'Link: <' . $sanitized_url . '>; rel="' . $rel_string . '"', false );
				}
			}
		};

		// 1️⃣ GEO
		if ( is_array( $geo ) && file_exists( plugin_dir_path( __FILE__ ) . 'class-geo-resolver.php' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-geo-resolver.php';
			if ( class_exists( 'GeoResolver' ) ) {
				$country = GeoResolver::get_country();
				foreach ( $geo as $g ) {
					$url = $g['url'] ?? '';
					if ( strtoupper( $g['country'] ?? '' ) === $country && self::is_valid_redirect_url( $url ) ) {
						$add_rel_header( $url );
						Cogito_RAR_Click_Logger::log_click( $post->ID, $visitor_id, $had_cookie );
						wp_redirect( $url, $type );
						exit;
					}
				}
			}
		}

		// 2️⃣ Weighted rotation
		if ( is_array( $rot ) && count( $rot ) > 0 ) {
			$total = array_sum( array_column( $rot, 'weight' ) );
			if ( $total > 0 ) {
				$rand = mt_rand( 1, $total );
				foreach ( $rot as $r ) {
					$url = $r['url'] ?? '';
					$weight = intval( $r['weight'] ?? 0 );
					if ( ! is_array( $r ) || $weight <= 0 || ! self::is_valid_redirect_url( $url ) ) {
						continue;
					}
					$rand -= $weight;
					if ( $rand <= 0 ) {
						$add_rel_header( $url );
						Cogito_RAR_Click_Logger::log_click( $post->ID, $visitor_id, $had_cookie );
						wp_redirect( $url, $type );
						exit;
					}
				}
			}
		}

		// 3️⃣ Default fallback
		$target = get_post_meta( $post->ID, '_rar_target', true );
		if ( self::is_valid_redirect_url( $target ) ) {
			$add_rel_header( $target );
			Cogito_RAR_Click_Logger::log_click( $post->ID, $visitor_id, $had_cookie );
			wp_redirect( $target, $type );
			exit;
		}
	}

	/**
	 * Validate a redirect URL for security (scheme, format, CRLF check).
	 */
	private static function is_valid_redirect_url( $url ) {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) return false;
		$scheme = parse_url( $url, PHP_URL_SCHEME );
		if ( strtolower( $scheme ) !== 'https' ) return false;
		if ( preg_match( '/[\r\n]/', $url ) ) return false;
		return true;
	}
}
