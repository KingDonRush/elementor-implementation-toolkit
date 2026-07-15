<?php
/**
 * WP-CLI smoke harness for Filter Controller robustness contracts.
 *
 * Usage:
 * docker compose run --rm wpcli eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-filter-controller-robustness.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$modules = __DIR__ . '/verification/filter-controller/';

require $modules . 'helpers.php';
require $modules . 'editor-contracts.php';
require $modules . 'frontend-contracts.php';
require $modules . 'renderer-contracts.php';
require $modules . 'fixture-contracts.php';
require $modules . 'sort-contracts.php';
require $modules . 'report.php';
