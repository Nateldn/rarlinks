<?php
/**
 * Handles logging and classification of RARLink clicks.
 *
 * Classification lives in classify() so it can be reused both at log time
 * (live request signals) and by the historical re-scan (stored row signals).
 *
 * @package Cogito_RAR
 */

use GeoIp2\Database\Reader; // Ensure this is present if ASNResolver is included in this file directly

if ( ! defined( 'ABSPATH' ) ) exit;

class Cogito_RAR_Click_Logger {

	/**
	 * Logs a click to the wp_rarlinks_clicks table.
	 *
	 * @param int    $post_id    The RARLink post ID.
	 * @param string $visitor_id The rar_uid visitor token.
	 * @param bool   $had_cookie Whether a valid rar_uid cookie ARRIVED with the
	 *                           request (i.e. the visitor has loaded a site page
	 *                           before). Defaults true so a missing value can
	 *                           never cause a false bot flag.
	 */
	public static function log_click( $post_id, $visitor_id = '', $had_cookie = true ) {
		global $wpdb;

		// 🌐 Capture IP address & validate it
		$ip_address = filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP ) ?: '';

		// 🔁 Resolve PTR hostname
		$hostname = $ip_address ? gethostbyaddr( $ip_address ) : '';
		// If the resolved hostname is suspiciously similar to the site's domain,
		// revert to the IP address to prevent a false entry.
		if ( stripos( (string) $hostname, 'renchlist.com' ) !== false ) {
			$hostname = $ip_address; // Use the IP address as a safe fallback
		}

		// 🧼 Sanitize referrer & truncate user agent
		$referrer   = sanitize_text_field( $_SERVER['HTTP_REFERER'] ?? '' );
		$user_agent = substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 );

		// 🧭 Load ASN resolver
		$org         = '';
		$current_asn = null;
		$asn_path    = plugin_dir_path( __FILE__ ) . 'class-asn-resolver.php';
		if ( file_exists( $asn_path ) ) {
			require_once $asn_path;
			if ( class_exists( 'ASNResolver' ) ) {
				$org         = ASNResolver::get_organization( $ip_address ) ?? '';
				$current_asn = ASNResolver::get_asn_number( $ip_address );
			} else {
				error_log( '[RAR ERROR] ASNResolver class not found after including: ' . $asn_path );
			}
		} else {
			error_log( '[RAR ERROR] ASN resolver file missing at: ' . $asn_path );
		}

		// 🧮 Classify using the shared detection waterfall
		$result = self::classify( [
			'ip_address'        => $ip_address,
			'hostname'          => $hostname,
			'org'               => $org,
			'user_agent'        => $user_agent,
			'referrer'          => $referrer,
			'current_asn'       => $current_asn,
			'had_cookie'        => $had_cookie,
			'post_id'           => $post_id,
			'spamhaus_asn_data' => self::load_spamhaus_asn_data(),
		] );

		// 📝 Insert into DB
		$wpdb->insert(
			$wpdb->prefix . 'rarlinks_clicks',
			[
				'post_id'    => (int) $post_id,
				'post_title' => sanitize_text_field( get_the_title( $post_id ) ),
				'referrer'   => $referrer,
				'user_agent' => $user_agent,
				'ip_address' => $ip_address,
				'hostname'   => sanitize_text_field( $hostname ),
				'visitor_id' => sanitize_text_field( $visitor_id ),
				'org'        => sanitize_text_field( $org ),
				'bot_or_not' => $result['bot_or_not'],
				'bot_name'   => sanitize_text_field( $result['bot_name'] ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		if ( $wpdb->last_error ) {
			error_log( '[RAR ERROR] DB Insert Error: ' . $wpdb->last_error );
		}
	}

	/**
	 * Loads the Spamhaus ASNDROP list (array of ASN entries), or [] if missing.
	 *
	 * @return array
	 */
	public static function load_spamhaus_asn_data() {
		$path = plugin_dir_path( __FILE__ ) . '../data/asndrop.json';
		if ( ! file_exists( $path ) ) {
			return [];
		}
		$arr = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $arr ) ? $arr : [];
	}

	/**
	 * Classifies a click from its signals.
	 *
	 * Returns [ 'bot_or_not' => 0|1|2, 'bot_name' => string ]. Used at log time
	 * with live signals, and by the re-scan with stored row signals. When the
	 * ASN isn't available (re-scan: it was never stored), pass current_asn=null
	 * and spamhaus_asn_data=[] and that check is simply skipped; pass
	 * had_cookie=true so the cookie-dependent rule can't false-flag.
	 *
	 * @param array $signals ip_address, hostname, org, user_agent, referrer,
	 *                       current_asn, had_cookie, post_id, spamhaus_asn_data.
	 * @return array
	 */
	public static function classify( array $signals ) {
		$ip_address        = (string) ( $signals['ip_address'] ?? '' );
		$hostname          = (string) ( $signals['hostname'] ?? '' );
		$org               = (string) ( $signals['org'] ?? '' );
		$user_agent        = (string) ( $signals['user_agent'] ?? '' );
		$referrer          = (string) ( $signals['referrer'] ?? '' );
		$current_asn       = $signals['current_asn'] ?? null;
		$had_cookie        = array_key_exists( 'had_cookie', $signals ) ? (bool) $signals['had_cookie'] : true;
		$post_id           = (int) ( $signals['post_id'] ?? 0 );
		$spamhaus_asn_data = is_array( $signals['spamhaus_asn_data'] ?? null ) ? $signals['spamhaus_asn_data'] : [];

		// 🤖 Known bot patterns by User Agent
		$bot_patterns = [
			'googlebot' => 'Googlebot',
			'bingbot' => 'Bingbot',
			'slurp' => 'Yahoo Slurp',
			'duckduckgo' => 'DuckDuckBot',
			'baiduspider' => 'Baiduspider',
			'yandexbot' => 'YandexBot',
			'facebookexternalhit' => 'Facebook Bot',
			'twitterbot' => 'Twitter Bot',
			'blexbot' => 'BLEXBot',
			'ccbot' => 'CCBot',
			'cliqzbot' => 'Cliqzbot',
			'dealply' => 'DealPly',
			'exabot' => 'Exabot',
			'mauibot' => 'MauiBot',
			'petalbot' => 'PetalBot',
			'screaming frog seo spider' => 'Screaming Frog SEO Spider',
			'screenshotbot' => 'ScreenshotBot',
			'seekportbot' => 'SeekportBot',
			'seznambot' => 'SeznamBot',
			'siteauditbot' => 'SiteAuditBot',
			'sogou' => 'Sogou web spider',
			'wpscan' => 'WPScan',
			'bytedance' => 'Bytedance',
			'bytespider' => 'Bytespider',
			'megaindex' => 'MegaIndex',
			'semalt' => 'Semalt',
			'semrush' => 'Semrush',
			'ahrefs' => 'Ahrefs',
			'rogerbot' => 'Moz (Rogerbot)',
			'dotbot' => 'Moz (Dotbot)',
			'majestic' => 'MajesticBot',
			'AliyunSecBot/Aliyun' => 'AliyunSecBot',
			// Folded in from the manual SQL cleanup query
			'mj12bot' => 'Majestic (MJ12bot)',
			'duckduckbot' => 'DuckDuckBot',
			'webmeup' => 'BLEXBot (webmeup)',
			'ontaboserver.net' => 'Contabo server',
		];

		// 🌐 Determine traffic classification: 0 = Human, 1 = Known bot, 2 = Unknown
		$bot_or_not = 2;
		$bot_name   = '';

		// --- Bot Detection Waterfall ---
		// Higher priority checks come first. Once classified as a bot (1), stop checking.

		// 🚩 0. Live bot list (user-flagged signals) — takes precedence over
		// EVERYTHING, including the renchlist.com referrer human check below:
		// a spoofed homepage referrer is precisely the pattern these manual
		// flags exist to catch.
		if ( class_exists( 'Cogito_RAR_Live_Bot_List' ) ) {
			$live_match = Cogito_RAR_Live_Bot_List::match( $ip_address, $hostname, $org, $user_agent );
			if ( $live_match ) {
				$bot_or_not = 1;
				$bot_name   = 'Live list (' . $live_match['type'] . ')';
			}
		}

		// ✅ 1. User Agent (UA) match
		if ( $bot_or_not === 2 ) {
			foreach ( $bot_patterns as $pattern => $name ) {
				if ( stripos( $user_agent, $pattern ) !== false ) {
					$bot_or_not = 1;
					$bot_name   = $name; // Store only the bot's name
					break;
				}
			}
		}

		// 🌍 2. PTR hostname check if still Unknown
		$known_ptrs = [
			'googlebot.com',
			'crawl-googlesystem.com',
			'bing.com',
			'search.msn.com',
			'yahoo.net',
			'duckduckgo.com',
			'baidu.com',
			'yandex.ru',
			'facebook.com',
			'cdninstagram.com',
			'amazonbot.amazon',
			'commoncrawl.org',
			'ahrefs.com',
			'pinterest.com',
			'rogerbot.com',
			'dotbot.com',
			'majestic.com',
			'semrush.com',
			'bytedance.com',
			'bytespider.com',
			'megaindex.com',
			'sogou.com',
			'wpscan.com',
			'mauibot.com',
			'petalbot.com',
			'screamingfrog.co.uk',
			'yandex.com',
			'petalsearch.com',
			'webmeup.com',
			'blex.seopowersuite.com',
			'majestic12.co.uk',
			'vdsina.com',
			'vdsina.ru',
			'byfly.by',
			'ertelecom.ru',
			'amazonaws.com',
			'dreamhost.com',
			'ahrefs.net',
			'archive.org',
			'facebot',
			'facebookexternalhit',
			'available.above.ne',
			'.ic2net.net',
			// Folded in from the manual SQL cleanup query
			'duckduckbot.com',
			'exabot.com',
			'googleusercontent.com',
			'static.vnpt.vn',
			'technobytes.com.br',
			'web2objects.com',
			'darkness-reigns.net',
			'servermania.com',
			'angband.teaparty.net',
		];

		if ( $bot_or_not === 2 && ! empty( $hostname ) ) {
			foreach ( $known_ptrs as $ptr ) {
				if ( stripos( $hostname, $ptr ) !== false ) {
					$bot_or_not = 1;
					$bot_name   = $ptr; // Store only the PTR pattern
					break;
				}
			}
		}

		// 🌐 3. IP Address patterns check if still Unknown
		$known_ip_patterns = [
			'212.34.153.',
			'66.249.',
			'207.46.',
			'185.191.171.',
			'20.171.207.128',
			'159.223.142.181',
			'94.103.87.196',
			'195.2.81.242',
			'212.118.32.0',
			'94.103.81.234',
			'89.110.105.167',
			'212.34.135.116',
			'212.118.37.238',
			'91.84.104.205',
			'88.210.11.43',
			'109.166.52.216',
			'161.35.111.188',
			'212.34.153.',
			'159.223.40.',
			// Folded in from the manual SQL cleanup query
			// (the 104.250.52.x / 53.x singles collapsed into two prefixes)
			'34.174.',
			'34.138.',
			'104.250.52.',
			'104.250.53.',
			'106.38.188.',
			'114.250.44.',
			'114.250.59.',
			'39.156.168.',
			'111.13.116.',
			'220.181.90.',
			'82.80.249.156',
			'141.98.11.115',
			'85.254.64.236',
			'92.50.32.112',
			'208.240.29.37',
			'154.30.31.172',
			'85.204.245.150',
			'142.147.194.185',
			'43.153.221.113',
			'45.148.10.203',
			'45.148.10.143',
			'114.250.50.143',
			'23.105.150.212',
			'23.81.69.42',
			'45.165.73.183',
			'14.191.92.125',
			'193.34.213.28',
			'45.169.19.166',
			'65.21.46.73',
			'195.178.110.68',
			'178.17.171.102',
			'204.217.129.21',
			'2a02:c207:2249:151::1',
		];

		if ( $bot_or_not === 2 && ! empty( $ip_address ) ) {
			foreach ( $known_ip_patterns as $ip_pattern ) {
				if ( str_starts_with( $ip_address, $ip_pattern ) ) {
					$bot_or_not = 1;
					$bot_name   = $ip_pattern; // Store only the IP pattern
					break;
				}
			}
		}

		// 🚨 4. Spamhaus ASNDROP check (malicious ASN) if still Unknown
		if ( $bot_or_not === 2 && ! is_null( $current_asn ) && ! empty( $spamhaus_asn_data ) ) {
			foreach ( $spamhaus_asn_data as $asn_entry ) {
				$asn_number_from_list = (int) filter_var( $asn_entry, FILTER_SANITIZE_NUMBER_INT );
				if ( (int) $current_asn === $asn_number_from_list ) {
					$bot_or_not = 1; // Classified as a malicious bot
					$bot_name   = 'AS' . $current_asn; // Store only the ASN
					break;
				}
			}
		}

		// 👤 5. Referrer + cookie heuristics
		// Referrers are trivially spoofed, so a site referrer alone no longer
		// proves human — the visitor must also have arrived carrying the site
		// cookie (set on any page load). Conversely, NO referrer AND NO cookie
		// means the redirect URL was hit directly by something that never
		// loaded a page: the harvested-URL replay signature.
		if ( $bot_or_not === 2 ) {
			// Normalise URLs for comparison: drop scheme/www, trailing slash, case
			$normalise = function ( $url ) {
				$url = strtolower( trim( (string) $url ) );
				$url = preg_replace( '#^https?://(www\.)?#', '', $url );
				return rtrim( $url, '/' );
			};
			$home_norm = $normalise( home_url() );
			$ref_norm  = $normalise( $referrer );

			if ( '' === $ref_norm && ! $had_cookie ) {
				// No referrer AND never visited the site. Suspicious, but not
				// proof: a first-time visitor pasting a shared link looks the
				// same. Leave as Unknown (2) rather than Bot.
				$bot_or_not = 2;
				$bot_name   = 'No referrer or cookie';
			} elseif ( $ref_norm === $home_norm && '' !== $ref_norm ) {
				// Bare homepage referrer: only Moto Partner links (homepage
				// native ads) legitimately produce this. On any other link
				// it is a spoofed referrer.
				if ( get_post_meta( $post_id, '_rar_moto_partner', true ) === '1' ) {
					$bot_or_not = 0; // Genuine homepage native ad click
					$bot_name   = '';
				} else {
					$bot_or_not = 1;
					$bot_name   = 'Homepage referrer (non-partner)';
				}
			} elseif ( '' !== $ref_norm && strpos( $ref_norm, $home_norm . '/' ) === 0 && $had_cookie ) {
				// Referred from a site page AND carrying the site cookie
				$bot_or_not = 0; // Classify as human
				$bot_name   = '';
			}
			// Site referrer without the cookie falls through to the org
			// checks below — cookie-blocking humans land in Unknown, not Bot.
		}

		// 👤 6. Refined 'org' classification if still Unknown
		// Implements cascading org checks: Bad Host Org > Legit Bot Org > Human Org
		if ( $bot_or_not === 2 && ! empty( $org ) ) {

			// Exact-match oddity from the manual SQL cleanup query: some rows
			// carry the literal org "1". Must NOT go in the pattern lists —
			// a substring match on '1' would hit any org containing the digit.
			if ( trim( $org ) === '1' ) {
				return [ 'bot_or_not' => 1, 'bot_name' => 'Org "1" (Bad Host)' ];
			}

			// Define patterns for known *malicious/bad host organizations*
			$known_bad_host_org_patterns = [
				'Servers Tech Fzco',
				// Folded in from the manual SQL cleanup query: proxy/VPS
				// providers and foreign ISPs only ever seen as bot exits
				'UAB code200',
				'Datacamp',
				'MEVSPACE',
				'Contabo GmbH',
				'UAB Bite Lietuva',
				'VNPT Corp',
				'WORLD WIFI TELECOMUNICACOES',
				'MICROTELL SCM LTDA',
				'Claro NXT Telecomunicacoes',
			];

			// Define patterns for known *legitimate bot organizations*
			$legit_bot_org_patterns = [
				'Google LLC',
				'MICROSOFT-CORP-MSN-AS-BLOCK',
				'Amazon.com, Inc.',
				'FACEBOOK',
				'Yandex LLC',
				'Baidu, Inc.',
				'Apple Inc.',
				'Cloudflare, Inc.',
				'Rackspace Hosting',
				'DigitalOcean LLC',
				'OVH SAS',
				'Hetzner Online GmbH',
				'Alibaba',
				'CSTL',
				'Linode',
				'Akamai Technologies',
				'Fastly',
				'Amazon Web Services',
				'Microsoft Azure',
				// Folded in from the manual SQL cleanup query: cloud/datacentre
				// orgs ('DigitalOcean' and 'AMAZON-02' catch the bare ASN forms
				// the existing 'DigitalOcean LLC' / 'Amazon.com, Inc.' miss)
				'Tencent',
				'Kingsoft cloud',
				'UUNET',
				'LEASEWEB',
				'DIGITALOCEAN',
				'AMAZON-02',
			];

			// Patterns for known *human-associated* ISPs/organizations
			$human_org_patterns = [
				'Comcast Cable Communications',
				'Verizon Fios',
				'AT&T Services',
				'Charter Communications',
				'Vodafone',
				'British Telecommunications PLC',
				'Republican Unitary Telecommunication Enterprise Beltelecom',
				'WINDSTREAM',
				'SFR',
				'Orange S.A.',
				'Connect Communications',
				'sbcglobal.net',
				'iowatelecom.net',
			];

			// Check for bad host organizations (mid-high priority)
			$is_bad_host_org = false;
			foreach ( $known_bad_host_org_patterns as $pattern ) {
				if ( stripos( $org, $pattern ) !== false ) {
					$is_bad_host_org = true;
					$bot_name        = $pattern . ' (Bad Host)';
					break;
				}
			}

			if ( $is_bad_host_org ) {
				$bot_or_not = 1; // Classify as a bot due to bad hosting
			} else {
				// Check for known legitimate bot organizations (mid-priority)
				$is_legit_bot_org = false;
				foreach ( $legit_bot_org_patterns as $pattern ) {
					if ( stripos( $org, $pattern ) !== false ) {
						$is_legit_bot_org = true;
						$bot_name         = $pattern . ' (Legit Bot)';
						break;
					}
				}

				if ( $is_legit_bot_org ) {
					$bot_or_not = 1; // Classify as a legitimate bot
				} else {
					// Lowest priority: known human ISPs/organizations
					$is_human_org = false;
					foreach ( $human_org_patterns as $pattern ) {
						if ( stripos( $org, $pattern ) !== false ) {
							$is_human_org = true;
							$bot_name     = '';
							break;
						}
					}

					if ( $is_human_org ) {
						$bot_or_not = 0; // Classify as human
					}
					// Otherwise remains Unknown (2).
				}
			}
		}

		return [ 'bot_or_not' => $bot_or_not, 'bot_name' => $bot_name ];
	}
}
