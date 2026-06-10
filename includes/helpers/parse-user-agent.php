<?php
/**
 * Parse browser, OS, and device type from a user agent string.
 *
 * @param string $ua User agent string
 * @return array Parsed data: browser, os, device
 */
function cogito_rar_parse_user_agent( $ua ) {
	$browser = 'Unknown';
	$os = 'Unknown';
	$device = 'Desktop';

	if ( stripos( $ua, 'mobile' ) !== false ) {
		$device = 'Mobile';
	}
	if ( stripos( $ua, 'tablet' ) !== false ) {
		$device = 'Tablet';
	}

	if ( preg_match( '/(Chrome|Firefox|Safari|Edge|Opera|MSIE|Trident)/i', $ua, $match ) ) {
		$browser = $match[1];
		if ( $browser === 'Trident' || $browser === 'MSIE' ) {
			$browser = 'Internet Explorer';
		}
	}

	if ( preg_match( '/Windows NT ([\d.]+)/i', $ua, $match ) ) {
		$version = $match[1];
		$os_map = [
			'10.0' => 'Windows 10',
			'6.3'  => 'Windows 8.1',
			'6.2'  => 'Windows 8',
			'6.1'  => 'Windows 7',
			'6.0'  => 'Windows Vista',
			'5.1'  => 'Windows XP'
		];
		$os = $os_map[$version] ?? 'Windows';
	} elseif ( preg_match( '/Mac OS X ([\d_]+)/i', $ua, $match ) ) {
		$version = str_replace('_', '.', $match[1]);
		$os = 'macOS ' . $version;
	} elseif ( stripos($ua, 'Android') !== false ) {
		preg_match('/Android ([\d.]+)/i', $ua, $match);
		$os = isset($match[1]) ? 'Android ' . $match[1] : 'Android';
	} elseif ( stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false ) {
		$os = 'iOS';
	} elseif ( stripos($ua, 'CrOS') !== false ) {
		$os = 'ChromeOS';
	} elseif ( stripos($ua, 'Linux') !== false ) {
		$os = 'Linux';
	}

	return [
		'browser' => $browser,
		'os'      => $os,
		'device'  => $device,
	];
}
