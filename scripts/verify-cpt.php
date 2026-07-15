<?php
/**
 * Destructive verification against a disposable Toolkit CPT definition.
 *
 * Run with:
 * wp eval-file wp-content/plugins/elementor-implementation-toolkit/scripts/verify-cpt.php
 */

use EIT\CPT\CptManager;
use EIT\CPT\DefinitionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eit_cpt_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$suffix = substr( md5( microtime( true ) . wp_rand() ), 0, 6 );
$type = 'eitqa_' . $suffix;
$taxonomy = 'eitqa_tax_' . $suffix;
$runtime = new CptManager();

$created = DefinitionManager::save(
	[
		'slug'         => $type,
		'singular'     => 'QA Record',
		'plural'       => 'QA Records',
		'public'       => true,
		'show_in_rest' => true,
		'supports'     => [ 'title' ],
		'taxonomies'   => [
			[
				'slug'         => $taxonomy,
				'singular'     => 'QA Group',
				'plural'       => 'QA Groups',
				'public'       => true,
				'show_in_rest' => true,
			],
		],
		'meta_fields'  => [
			[
				'key'          => 'quantity',
				'label'        => 'Quantity',
				'type'         => 'number',
				'required'     => true,
				'show_in_rest' => true,
			],
			[
				'key'          => 'reference',
				'label'        => 'Reference',
				'type'         => 'text',
				'show_in_rest' => true,
			],
		],
	]
);
eit_cpt_assert( ! is_wp_error( $created ), 'Could not publish the disposable CPT definition.' );

$runtime->register_definitions();
eit_cpt_assert( post_type_exists( $type ), 'The disposable post type was not registered.' );
eit_cpt_assert( ! post_type_supports( $type, 'editor' ), 'Structured mode enabled the WordPress editor unexpectedly.' );

$published = DefinitionManager::get( $type );
$duplicate = $published;
$duplicate['original_slug'] = $type;
$duplicate['meta_fields'][] = [ 'key' => 'quantity', 'label' => 'Duplicate', 'type' => 'number' ];
eit_cpt_assert( is_wp_error( DefinitionManager::save( $duplicate ) ), 'A duplicate meta key was accepted.' );
eit_cpt_assert( $published === DefinitionManager::get( $type ), 'A rejected CPT definition changed the published option.' );

$reserved_taxonomy = $published;
$reserved_taxonomy['original_slug'] = $type;
$reserved_taxonomy['taxonomies'][] = [ 'slug' => 'category', 'singular' => 'Category', 'plural' => 'Categories' ];
eit_cpt_assert( is_wp_error( DefinitionManager::save( $reserved_taxonomy ) ), 'A reserved taxonomy slug was accepted.' );

$type_change = $published;
$type_change['original_slug'] = $type;
foreach ( $type_change['meta_fields'] as &$field ) {
	if ( 'quantity' === $field['key'] ) {
		$field['original_key'] = 'quantity';
		$field['type'] = 'text';
	}
}
unset( $field );
eit_cpt_assert( is_wp_error( DefinitionManager::save( $type_change ) ), 'A published meta type changed without a migration plan.' );

$slug_change = $published;
$slug_change['original_slug'] = $type;
$slug_change['slug'] = $type . '_new';
eit_cpt_assert( is_wp_error( DefinitionManager::save( $slug_change ) ), 'A published CPT slug changed without a migration plan.' );

$missing_request = new WP_REST_Request( 'POST', '/wp/v2/' . $type );
$missing_request->set_param( 'meta', [] );
$missing_result = apply_filters( 'rest_pre_insert_' . $type, (object) [ 'post_status' => 'publish', 'ID' => 0 ], $missing_request );
eit_cpt_assert( is_wp_error( $missing_result ), 'REST publishing bypassed a required field.' );
eit_cpt_assert( [ 'quantity' ] === ( $missing_result->get_error_data()['missing_fields'] ?? [] ), 'REST validation did not identify the missing field.' );

$zero_request = new WP_REST_Request( 'POST', '/wp/v2/' . $type );
$zero_request->set_param( 'meta', [ 'quantity' => '0' ] );
$zero_result = apply_filters( 'rest_pre_insert_' . $type, (object) [ 'post_status' => 'publish', 'ID' => 0 ], $zero_request );
eit_cpt_assert( ! is_wp_error( $zero_result ), 'REST publishing treated the required string zero as empty.' );

$post_id = wp_insert_post(
	[
		'post_type'   => $type,
		'post_title'  => 'Existing zero',
		'post_status' => 'draft',
		'meta_input'  => [ 'quantity' => '0' ],
	],
	true
);
eit_cpt_assert( ! is_wp_error( $post_id ), 'Could not create the disposable CPT record.' );

$update_request = new WP_REST_Request( 'POST', '/wp/v2/' . $type . '/' . $post_id );
$update_result = apply_filters( 'rest_pre_insert_' . $type, (object) [ 'post_status' => 'publish', 'ID' => $post_id ], $update_request );
eit_cpt_assert( ! is_wp_error( $update_result ), 'REST update ignored an existing required zero value.' );

$draft_request = new WP_REST_Request( 'POST', '/wp/v2/' . $type );
$draft_result = apply_filters( 'rest_pre_insert_' . $type, (object) [ 'post_status' => 'draft', 'ID' => 0 ], $draft_request );
eit_cpt_assert( ! is_wp_error( $draft_result ), 'Required validation blocked a draft.' );

wp_delete_post( $post_id, true );
eit_cpt_assert( DefinitionManager::delete( $type ), 'Could not remove the disposable CPT definition.' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( 'CPT stable identities, reserved slugs, Structured mode, REST required fields, and zero values verified.' );
}
