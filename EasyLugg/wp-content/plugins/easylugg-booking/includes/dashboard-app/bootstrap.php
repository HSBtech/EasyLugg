<?php
/**
 * Dashboard bootstrap — loads the dashboard classes and registers hooks.
 *
 * Loaded by a single require_once in easylugg-booking.php.
 * No other file outside includes/dashboard-app/ and assets/dashboard-app/
 * is touched by the dashboard app.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-app.php';
require_once plugin_dir_path( __FILE__ ) . 'class-app-metrics.php';
require_once plugin_dir_path( __FILE__ ) . 'class-app-data.php';
require_once plugin_dir_path( __FILE__ ) . 'class-app-render.php';
require_once plugin_dir_path( __FILE__ ) . 'class-app-ajax.php';

EasyLugg_App::hooks();
EasyLugg_App_Ajax::hooks();
