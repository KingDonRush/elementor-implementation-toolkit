<?php
/**
 * Plugin Name: Elementor Implementation Toolkit
 * Description: Practical Elementor implementation helpers, starting with a parasitic AJAX filter controller for existing listings.
 * Version: 0.2.8
 * Author: Guilherme Silva
 * Text Domain: elementor-implementation-toolkit
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EIT_VERSION', '0.2.8' );
define( 'EIT_FILE', __FILE__ );
define( 'EIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'EIT_URL', plugin_dir_url( __FILE__ ) );

$eit_composer_autoload = EIT_PATH . 'vendor/autoload.php';

if ( file_exists( $eit_composer_autoload ) ) {
	require_once $eit_composer_autoload;
} else {
	require_once EIT_PATH . 'includes/Core/Autoloader.php';
}

use EIT\Core\Plugin;

function eit_run_plugin() {
	$plugin = new Plugin();
	$plugin->run();
}

eit_run_plugin();
