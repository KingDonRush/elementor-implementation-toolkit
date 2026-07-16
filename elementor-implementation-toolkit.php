<?php
/**
 * Plugin Name: Elementor Implementation Toolkit
 * Description: Contract-driven implementation helpers for structured WordPress data and Elementor presentation.
 * Version: 1.0.0-rc.1
 * Author: Guilherme Silva
 * Text Domain: elementor-implementation-toolkit
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EIT_VERSION', '1.0.0-rc.1' );
define( 'EIT_FILE', __FILE__ );
define( 'EIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'EIT_URL', plugin_dir_url( __FILE__ ) );

$eit_composer_autoload = EIT_PATH . 'vendor/autoload.php';

if ( file_exists( $eit_composer_autoload ) ) {
	require_once $eit_composer_autoload;
} else {
	require_once EIT_PATH . 'includes/Core/Autoloader.php';
}

require_once EIT_PATH . 'includes/functions.php';

register_activation_hook( EIT_FILE, [ '\EIT\Infrastructure\SchemaManager', 'install' ] );

use EIT\Core\Plugin;

function eit_run_plugin() {
	$plugin = new Plugin();
	$plugin->run();
}

eit_run_plugin();
