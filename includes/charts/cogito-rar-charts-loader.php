<?php
// Chart loader file – enqueues Chart.js and loads chart classes

if ( ! defined( 'ABSPATH' ) ) exit;

class Cogito_RAR_Charts_Loader {

	public static function init() {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_chartjs' ] );
	}

	public static function enqueue_chartjs( $hook ) {
		// Only enqueue on the RAR dashboard page
		if ( $hook !== 'rar_redirect_page_rar_dashboard' ) {
			return;
		}

		// Enqueue Chart.js from CDN
		wp_enqueue_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js',
			[],
			null,
			true
		);

		// Enqueue our chart rendering script, dependent on Chart.js
		wp_enqueue_script(
			'rar-charts-js',
			plugin_dir_url( __FILE__ ) . 'js/rar-charts.js',
			[ 'chartjs' ],
			'1.0',
			true
		);
	}
}

// Load chart data classes
require_once __DIR__ . '/class-cogito-rar-line-chart.php';
require_once __DIR__ . '/class-cogito-rar-doughnut-chart.php';

Cogito_RAR_Charts_Loader::init();