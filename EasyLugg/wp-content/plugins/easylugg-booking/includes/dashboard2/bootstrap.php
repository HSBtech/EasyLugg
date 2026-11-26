<?php
/**
 * Dashboard 2 bootstrap — loads the D2 classes and registers hooks.
 *
 * Loaded by a single require_once in easylugg-booking.php (D2-09).
 * No other file outside includes/dashboard2/ and assets/dashboard2/
 * is touched by Dashboard 2.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-d2.php';
require_once plugin_dir_path( __FILE__ ) . 'class-d2-metrics.php';
require_once plugin_dir_path( __FILE__ ) . 'class-d2-data.php';
require_once plugin_dir_path( __FILE__ ) . 'class-d2-render.php';
require_once plugin_dir_path( __FILE__ ) . 'class-d2-ajax.php';

EasyLugg_D2::hooks();
EasyLugg_D2_Ajax::hooks();
