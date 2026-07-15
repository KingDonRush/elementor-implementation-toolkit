<?php
/**
 * Create and remove disposable browser-test records in the local WordPress runtime.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$mode = sanitize_key( getenv( 'EIT_E2E_MODE' ) );
$token = sanitize_key( getenv( 'EIT_E2E_TOKEN' ) );
$username = sanitize_user( getenv( 'EIT_E2E_USER' ), true );
$email = sanitize_email( getenv( 'EIT_E2E_EMAIL' ) );
$password = (string) getenv( 'EIT_E2E_PASSWORD' );

if ( ! $token || ! $username || ! $email || ! $password ) {
	WP_CLI::error( 'Disposable E2E fixture context is incomplete.' );
}

$fixture_key = '_eit_e2e_fixture';

if ( 'cleanup' === $mode ) {
	$pages = get_posts(
		[
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_key'       => $fixture_key,
			'meta_value'     => $token,
		]
	);
	foreach ( $pages as $page ) {
		wp_delete_post( $page->ID, true );
	}

	$user = get_user_by( 'login', $username );
	if ( $user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user->ID );
	}
	WP_CLI::success( 'Disposable E2E fixture removed.' );
	return;
}

$user_id = wp_insert_user(
	[
		'user_login' => $username,
		'user_email' => $email,
		'user_pass'  => $password,
		'role'       => 'administrator',
	]
);
if ( is_wp_error( $user_id ) ) {
	WP_CLI::error( $user_id->get_error_message() );
}

$listing_html = '<div id="eit-e2e-listing" data-eit-listing>'
	. '<article data-eit-item data-eit-client-id="alpha" data-kind="plugin"><h2>Alpha plugin</h2></article>'
	. '<article data-eit-item data-eit-client-id="beta" data-kind="site"><h2>Beta site</h2></article>'
	. '</div>';
$elementor_data = [
	[
		'id'       => 'e2ewrap',
		'elType'   => 'container',
		'settings' => [ 'container_type' => 'flex', 'flex_direction' => 'column' ],
		'elements' => [
			[
				'id'         => 'e2elist',
				'elType'     => 'widget',
				'widgetType' => 'html',
				'settings'   => [ 'html' => $listing_html ],
				'elements'   => [],
			],
			[
				'id'         => 'e2efilter',
				'elType'     => 'widget',
				'widgetType' => 'eit-filter-controller',
				'settings'   => [
					'data_provider'        => 'dom',
					'configuration_source' => 'widget',
					'target_selector'      => '#eit-e2e-listing',
					'item_selector'        => '[data-eit-item]',
					'auto_apply'           => 'yes',
					'sync_url'             => '',
					'per_page'             => 24,
					'show_result_count'    => 'yes',
					'result_count_text'    => '{count} results',
					'show_active_chips'    => 'yes',
					'pagination_type'      => 'numbers',
					'filters'              => [
						[
							'_id'          => 'e2ekind',
							'label'        => 'Kind',
							'type'         => 'select',
							'key'          => 'kind',
							'source'       => 'data_attr',
							'options'      => "plugin | Plugin\nsite | Site",
							'layout_width' => 100,
							'show_label'   => 'yes',
						],
					],
				],
				'elements'   => [],
			],
		],
	],
];

$page_id = wp_insert_post(
	[
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'EIT browser fixture ' . $token,
		'post_content' => '',
		'post_author'  => $user_id,
	],
	true
);
if ( is_wp_error( $page_id ) ) {
	WP_CLI::error( $page_id->get_error_message() );
}

update_post_meta( $page_id, $fixture_key, $token );
update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );

if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->files_manager ) {
	\Elementor\Plugin::$instance->files_manager->clear_cache();
}

WP_CLI::line(
	wp_json_encode(
		[
			'frontendPath' => wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH ),
			'editorPath'   => '/wp-admin/post.php?post=' . $page_id . '&action=elementor',
		]
	)
);
