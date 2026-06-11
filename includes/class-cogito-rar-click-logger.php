<?php
/**
 * Handles logging of RARLink clicks to the database.
 *
 * @package Cogito_RAR
 */

use GeoIp2\Database\Reader; // Ensure this is present if ASNResolver is included in this file directly

if ( ! defined( 'ABSPATH' ) ) exit;

class Cogito_RAR_Click_Logger {

	/**
	 * Logs a click to the wp_rarlinks_clicks table.
	 *
	 * @param int $post_id The RARLink post ID.
	 */
    	public static function log_click( $post_id, $visitor_id = '' ) {
        global $wpdb;
    
        // 🌐 Capture IP address & validate it
        $ip_address = filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP ) ?: '';
    
        // 🔁 Resolve PTR hostname
        $hostname = $ip_address ? gethostbyaddr( $ip_address ) : '';
        // If the resolved hostname is suspiciously similar to the site's domain,
        // revert to the IP address to prevent a false entry.
        if ( stripos($hostname, 'renchlist.com') !== false ) {
            $hostname = $ip_address; // Use the IP address as a safe fallback
        }
    
        // 🧼 Sanitize referrer & truncate user agent
        $referrer   = sanitize_text_field( $_SERVER['HTTP_REFERER'] ?? '' );
        $user_agent = substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 );
    
        // 🧭 Load ASN resolver
        $asn_path = plugin_dir_path( __FILE__ ) . 'class-asn-resolver.php';
        if ( file_exists( $asn_path ) ) {
            require_once $asn_path;
             if ( class_exists( 'ASNResolver' ) ) {
                $org         = ASNResolver::get_organization( $ip_address ) ?? '';
                $current_asn = ASNResolver::get_asn_number( $ip_address );
            } else {
                error_log('[RAR ERROR] ASNResolver class not found after including: ' . $asn_path);
                $org         = '';
                $current_asn = null;
            }
        } else {
            error_log('[RAR ERROR] ASN resolver file missing at: ' . $asn_path);
            $org         = '';
            $current_asn = null;
        }
    
        // 🚨 Load Spamhaus ASNDROP data for malicious ASN detection
        $spamhaus_asn_data = [];
        $spamhaus_json_path = plugin_dir_path( __FILE__ ) . '../data/asndrop.json'; 
    
        if ( file_exists( $spamhaus_json_path ) ) {
            $json_content = file_get_contents( $spamhaus_json_path );
            $json_array = json_decode( $json_content, true );
            if ( is_array( $json_array ) && ! empty( $json_array ) ) {
                $spamhaus_asn_data = $json_array;
            } else {
                error_log('[RAR ERROR] Failed to decode asndrop.json or it\'s empty. Check JSON format.');
            }
        } else {
            error_log('[RAR ERROR] Spamhaus asndrop.json not found at: ' . $spamhaus_json_path);
        }
            
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
        ];
    
        // 🌐 Determine traffic classification: 0 = Human, 1 = Known bot, 2 = Unknown
        $bot_or_not = 2;
        $bot_name = ''; // Stores only the name, no 'By X:' prefix
    
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
                    $bot_name = $name; // Store only the bot's name
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
        ];
    
        if ( $bot_or_not === 2 && ! empty( $hostname ) ) {
            foreach ( $known_ptrs as $ptr ) {
                if ( stripos( $hostname, $ptr ) !== false ) {
                    $bot_or_not = 1;
                    $bot_name = $ptr; // Store only the PTR pattern
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
        ];
    
        if ( $bot_or_not === 2 && ! empty( $ip_address ) ) {
            foreach ( $known_ip_patterns as $ip_pattern ) {
                if ( str_starts_with( $ip_address, $ip_pattern ) ) {
                    $bot_or_not = 1;
                    $bot_name = $ip_pattern; // Store only the IP pattern
                    break;
                }
            }
        }
        
        // 🚨 4. Spamhaus ASNDROP check (malicious ASN) if still Unknown
        if ( $bot_or_not === 2 ) {
            if ( ! is_null( $current_asn ) && ! empty( $spamhaus_asn_data ) ) {
                foreach ( $spamhaus_asn_data as $asn_entry ) {
                    $asn_number_from_list = (int) filter_var($asn_entry, FILTER_SANITIZE_NUMBER_INT);
                    if ( $current_asn === $asn_number_from_list ) {
                        $bot_or_not = 1; // Classified as a malicious bot
                        $bot_name = 'AS' . $current_asn; // Store only the ASN
                        break;
                    }
                }
            }
        }
    
        // 👤 5. Referrer check (high confidence human signal)
        if ( $bot_or_not === 2 && ! empty( $referrer ) ) {
            if ( stripos( $referrer, 'renchlist.com' ) !== false ) {
                $bot_or_not = 0; // Classify as human
                $bot_name = ''; // Ensure bot_name is empty for human classification
            }
        }
    
        // 👤 6. Refined 'org' classification if still Unknown
        // Implements cascading org checks: Bad Host Org > Legit Bot Org > Human Org
        if ( $bot_or_not === 2 && ! empty( $org ) ) {
    
            // Define patterns for known *malicious/bad host organizations*
            $known_bad_host_org_patterns = [
                'Servers Tech Fzco',
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
                    $bot_name = $pattern . ' (Bad Host)';
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
                        $bot_name = $pattern . ' (Legit Bot)';
                        break;
                    }
                }
    
                if ( $is_legit_bot_org ) {
                    $bot_or_not = 1; // Classify as a legitimate bot
                } else {
                    // If not a malicious ASN or a legitimate bot org, check for human ISPs/organizations (lowest priority)
                    $is_human_org = false;
                    foreach ( $human_org_patterns as $pattern ) {
                        if ( stripos( $org, $pattern ) !== false ) {
                            $is_human_org = true;
                            $bot_name = ''; // Store empty string for human classification
                            break;
                        }
                    }
    
                    if ( $is_human_org ) {
                        $bot_or_not = 0; // Classify as human
                    }
                    // If bot_or_not is still 2 at this point, it remains Unknown, bot_name remains empty or last set.
                }
            }
        }
    
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
    			'bot_or_not' => $bot_or_not,
    			'bot_name'   => sanitize_text_field( $bot_name ),
    		],
    		[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
    	);
    
    	if ( $wpdb->last_error ) {
    		error_log('[RAR ERROR] DB Insert Error: ' . $wpdb->last_error);
    	}
    }

}