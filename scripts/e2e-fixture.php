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

function eit_e2e_cleanup_entry_blueprint( $blueprint_id, $slug ) {
	global $wpdb;
	if ( ! $blueprint_id ) {
		return;
	}
	foreach ( [ \EIT\Infrastructure\Tables::ACTION_JOBS, \EIT\Infrastructure\Tables::ENTRY_SUBMISSIONS, \EIT\Infrastructure\Tables::RELATIONS, \EIT\Infrastructure\Tables::MULTIVALUES, \EIT\Infrastructure\Tables::RECONCILIATIONS, \EIT\Infrastructure\Tables::ROLLBACKS, \EIT\Infrastructure\Tables::RUNS, \EIT\Infrastructure\Tables::BINDINGS, \EIT\Infrastructure\Tables::ARTIFACTS, \EIT\Infrastructure\Tables::CHANGE_SETS, \EIT\Infrastructure\Tables::VERSIONS ] as $table_key ) {
		$wpdb->delete( \EIT\Infrastructure\Tables::name( $table_key ), [ 'blueprint_id' => $blueprint_id ] );
	}
	$wpdb->delete( \EIT\Infrastructure\Tables::name( \EIT\Infrastructure\Tables::BLUEPRINTS ), [ 'id' => $blueprint_id ] );
	if ( $slug ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . \EIT\CCT\SchemaManager::table_name( $slug ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	\EIT\Blueprint\RuntimeDefinitionProvider::invalidate();
}

function eit_e2e_create_entry_blueprint( $token, $user_id ) {
	$uuid = function ( $key ) use ( $token ) {
		return \EIT\Blueprint\Uuid::v5( \EIT\Blueprint\Uuid::LEGACY_NAMESPACE, 'e2e:' . $token . ':' . $key );
	};
	$ids = [ 'blueprint' => $uuid( 'blueprint' ), 'entity' => $uuid( 'entity' ), 'group' => $uuid( 'group' ), 'entry' => $uuid( 'entry' ), 'collection' => $uuid( 'collection' ), 'filters' => $uuid( 'filters' ), 'policy' => $uuid( 'policy' ), 'title' => $uuid( 'title' ), 'tier' => $uuid( 'tier' ), 'quantity' => $uuid( 'quantity' ), 'note' => $uuid( 'note' ), 'repeater' => $uuid( 'repeater' ), 'child' => $uuid( 'child' ), 'total' => $uuid( 'total' ), 'first_step' => $uuid( 'first-step' ), 'second_step' => $uuid( 'second-step' ) ];
	$slug = substr( 'eit_e2e_entry_' . $token, 0, 32 );
	$factory = new \EIT\Blueprint\FieldContractFactory( new \EIT\Blueprint\FieldPrimitiveRegistry() );
	$fields = [
		$factory->make( $ids['title'], 'Listing title', 'short_text', [ 'validation' => [ 'required' => true ] ] ),
		$factory->make( $ids['tier'], 'Service tier', 'single_choice', [ 'validation' => [ 'required' => true, 'options' => [ [ 'value' => 'basic', 'label' => 'Basic' ], [ 'value' => 'premium', 'label' => 'Premium' ] ] ], 'indexing' => [ 'filter' => true ] ] ),
		$factory->make( $ids['quantity'], 'Quantity', 'integer', [ 'validation' => [ 'required' => true, 'min' => 0 ], 'indexing' => [ 'filter' => true, 'sort' => true ] ] ),
		$factory->make( $ids['note'], 'Premium instructions', 'long_text' ),
		$factory->make( $ids['repeater'], 'Delivery rows', 'repeatable_group', [ 'validation' => [ 'children' => [ [ 'id' => $ids['child'], 'name' => 'Row label', 'type' => 'short_text' ] ] ] ] ),
		$factory->make( $ids['total'], 'Calculated total', 'calculated', [ 'validation' => [ 'expression' => '{' . $ids['quantity'] . '} * 2' ] ] ),
	];
	$document = [
		'api_version' => \EIT\Blueprint\BlueprintValidator::API_VERSION,
		'kind' => \EIT\Blueprint\BlueprintValidator::KIND,
		'id' => $ids['blueprint'], 'slug' => 'e2e-entry-' . $token, 'name' => 'E2E Entry workspace', 'version' => 1,
		'nodes' => [
			[ 'id' => $ids['entity'], 'type' => 'entity', 'lane' => 'data', 'name' => 'E2E listing', 'config' => [ 'slug' => $slug, 'mode' => 'structured', 'public' => false, 'high_volume' => true ] ],
			[ 'id' => $ids['group'], 'type' => 'field_group', 'lane' => 'data', 'name' => 'Listing fields', 'config' => [ 'fields' => $fields ] ],
			[ 'id' => $ids['entry'], 'type' => 'entry_surface', 'lane' => 'experience', 'name' => 'Create a listing', 'config' => [
				'operations' => [ 'create', 'update', 'submit_review' ], 'initial_status' => 'draft', 'title_field_id' => $ids['title'],
				'steps' => [ [ 'id' => $ids['first_step'], 'name' => 'Identity', 'field_ids' => [ $ids['title'], $ids['tier'] ] ], [ 'id' => $ids['second_step'], 'name' => 'Delivery details', 'field_ids' => [ $ids['quantity'], $ids['note'], $ids['repeater'], $ids['total'] ] ] ],
				'conditions' => [ [ 'id' => $uuid( 'condition-show' ), 'source_field_id' => $ids['tier'], 'operator' => 'equals', 'value' => 'premium', 'effect' => 'show', 'target_field_id' => $ids['note'] ], [ 'id' => $uuid( 'condition-require' ), 'source_field_id' => $ids['tier'], 'operator' => 'equals', 'value' => 'premium', 'effect' => 'require', 'target_field_id' => $ids['note'] ] ],
				'actions' => [], 'autosave' => [ 'enabled' => false ], 'guest' => [ 'enabled' => false ],
			] ],
			[ 'id' => $ids['collection'], 'type' => 'collection', 'lane' => 'experience', 'name' => 'Published listings', 'config' => [ 'page_size' => 24, 'access' => 'authenticated', 'default_sort' => [ 'field_id' => $ids['quantity'], 'direction' => 'asc' ] ] ],
			[ 'id' => $ids['filters'], 'type' => 'filter_surface', 'lane' => 'experience', 'name' => 'Listing filters', 'config' => [ 'fields' => [ $ids['tier'], $ids['quantity'] ], 'facet_fields' => [ $ids['tier'] ], 'url_state' => true, 'active_chips' => true ] ],
			[ 'id' => $ids['policy'], 'type' => 'policy', 'lane' => 'governance', 'name' => 'Owned authoring', 'config' => [ 'capability' => 'edit_posts', 'ownership' => 'own', 'object_scope' => 'entity' ] ],
		],
		'connections' => [ [ 'id' => $uuid( 'edge-fields' ), 'type' => 'entity_fields', 'from' => $ids['entity'], 'to' => $ids['group'] ], [ 'id' => $uuid( 'edge-entry' ), 'type' => 'entry_for', 'from' => $ids['entity'], 'to' => $ids['entry'] ], [ 'id' => $uuid( 'edge-collection' ), 'type' => 'collection_for', 'from' => $ids['entity'], 'to' => $ids['collection'] ], [ 'id' => $uuid( 'edge-filters' ), 'type' => 'filters', 'from' => $ids['collection'], 'to' => $ids['filters'] ], [ 'id' => $uuid( 'edge-policy' ), 'type' => 'governs_entry', 'from' => $ids['policy'], 'to' => $ids['entry'] ] ],
	];
	wp_set_current_user( $user_id );
	$lifecycle = \EIT\Blueprint\BlueprintModule::lifecycle();
	$saved = $lifecycle->save_draft( $document );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	$prepared = $lifecycle->prepare( $ids['blueprint'], $user_id );
	if ( is_wp_error( $prepared ) ) {
		return $prepared;
	}
	$published = $lifecycle->apply( $prepared['id'], $prepared['confirmation_token'], $user_id );
	if ( is_wp_error( $published ) ) {
		return $published;
	}
	$repository = new \EIT\CCT\Repository();
	foreach ( [ [ 'Basic listing', 'basic', 0 ], [ 'Premium listing', 'premium', 10 ] ] as $record ) {
		$saved_record = $repository->save( $slug, [ 'title' => $record[0], 'status' => 'publish', $fields[0]['storage']['key'] => $record[0], $fields[1]['storage']['key'] => $record[1], $fields[2]['storage']['key'] => $record[2] ] );
		if ( is_wp_error( $saved_record ) ) {
			eit_e2e_cleanup_entry_blueprint( $ids['blueprint'], $slug );
			return $saved_record;
		}
	}
	return [ 'blueprint_id' => $ids['blueprint'], 'surface_id' => $ids['entry'], 'collection_id' => $ids['collection'], 'slug' => $slug ];
}

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
		$blueprint_id = get_post_meta( $page->ID, '_eit_e2e_blueprint_id', true );
		$entry_slug = get_post_meta( $page->ID, '_eit_e2e_entry_slug', true );
		eit_e2e_cleanup_entry_blueprint( $blueprint_id, $entry_slug );
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

$entry_fixture = eit_e2e_create_entry_blueprint( $token, $user_id );
if ( is_wp_error( $entry_fixture ) ) {
	WP_CLI::error( $entry_fixture->get_error_message() );
}
$entry_page_id = wp_insert_post(
	[
		'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'EIT Entry fixture ' . $token,
		'post_content' => '[eit_entry_surface id="' . $entry_fixture['surface_id'] . '"]', 'post_author' => $user_id,
	],
	true
);
if ( is_wp_error( $entry_page_id ) ) {
	WP_CLI::error( $entry_page_id->get_error_message() );
}
update_post_meta( $entry_page_id, $fixture_key, $token );
update_post_meta( $entry_page_id, '_eit_e2e_blueprint_id', $entry_fixture['blueprint_id'] );
update_post_meta( $entry_page_id, '_eit_e2e_entry_slug', $entry_fixture['slug'] );

$collection_page_id = wp_insert_post(
	[
		'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'EIT Collection fixture ' . $token,
		'post_content' => '', 'post_author' => $user_id,
	],
	true
);
if ( is_wp_error( $collection_page_id ) ) {
	WP_CLI::error( $collection_page_id->get_error_message() );
}
$collection_elementor_data = [
	[
		'id' => 'e2ecollectionwrap', 'elType' => 'container', 'settings' => [ 'container_type' => 'flex', 'flex_direction' => 'column' ],
		'elements' => [
			[
				'id' => 'e2ecollection', 'elType' => 'widget', 'widgetType' => 'eit-filter-controller',
				'settings' => [
					'data_provider' => 'collection', 'collection_id' => $entry_fixture['collection_id'], 'show_result_count' => 'yes',
					'result_count_text' => '{count} results', 'pagination_type' => 'numbers', 'empty_text' => 'No matching listings.',
				],
				'elements' => [],
			],
		],
	],
];
update_post_meta( $collection_page_id, $fixture_key, $token );
update_post_meta( $collection_page_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $collection_page_id, '_elementor_template_type', 'wp-page' );
update_post_meta( $collection_page_id, '_elementor_data', wp_slash( wp_json_encode( $collection_elementor_data ) ) );

if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->files_manager ) {
	\Elementor\Plugin::$instance->files_manager->clear_cache();
}

WP_CLI::line(
	wp_json_encode(
		[
			'frontendPath' => wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH ),
			'editorPath'   => '/wp-admin/post.php?post=' . $page_id . '&action=elementor',
			'entryPath'    => wp_parse_url( get_permalink( $entry_page_id ), PHP_URL_PATH ),
			'collectionPath' => wp_parse_url( get_permalink( $collection_page_id ), PHP_URL_PATH ),
		]
	)
);
