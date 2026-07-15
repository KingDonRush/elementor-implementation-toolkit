<?php

use EIT\CPT\CptManager;
use EIT\Elementor\FilterController\FieldBindingResolver;
use EIT\Elementor\FilterController\FilterSettings;
use EIT\Support\FilterPresets;
use EIT\Support\FilterResolver;
use EIT\Support\ToolkitFieldCatalog;

$original_definitions = get_option( CptManager::OPTION, [] );
$original_presets     = get_option( FilterPresets::OPTION, [] );
$post_id              = 0;
$term_id              = 0;

try {
	$definitions = is_array( $original_definitions ) ? $original_definitions : [];
	$post_type = isset( $definitions['_portfolio_item'] ) ? '_portfolio_item' : '';
	$created_disposable_cpt = false;

	if ( '' === $post_type ) {
		foreach ( $definitions as $candidate_slug => $definition ) {
			if ( ! empty( $definition['public'] ) && post_type_exists( $candidate_slug ) ) {
				$post_type = $candidate_slug;
				break;
			}
		}
	}

	if ( '' === $post_type ) {
		$post_type = 'eit_qa_cpt';
		$taxonomy = 'eit_qa_tax';
		$created_disposable_cpt = true;
		$definitions[ $post_type ] = [
			'slug'         => $post_type,
			'singular'     => 'QA Item',
			'plural'       => 'QA Items',
			'description'  => 'Disposable verification CPT.',
			'menu_icon'    => 'dashicons-admin-tools',
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => false,
			'rewrite'      => false,
			'supports'     => [ 'title', 'editor', 'excerpt' ],
			'taxonomies'   => [
				[
					'slug'         => $taxonomy,
					'singular'     => 'QA Type',
					'plural'       => 'QA Types',
					'public'       => true,
					'show_in_rest' => true,
					'hierarchical' => false,
				],
			],
			'meta_fields'  => [],
		];
		update_option( CptManager::OPTION, $definitions, false );
		register_post_type( $post_type, [ 'public' => true, 'show_in_rest' => true, 'supports' => [ 'title', 'editor', 'excerpt' ] ] );
		register_taxonomy( $taxonomy, [ $post_type ], [ 'public' => true, 'show_in_rest' => true ] );
		eit_fc_pass( 'TEST-FC-ROBUSTNESS-004', 'Toolkit public CPT fixture available', [ 'fixture' => $post_type ] );
	}

	if ( '' !== $post_type ) {
		$taxonomy = '';

		foreach ( $definitions[ $post_type ]['taxonomies'] ?? [] as $taxonomy_definition ) {
			if ( ! empty( $taxonomy_definition['public'] ) && ! empty( $taxonomy_definition['show_in_rest'] ) && taxonomy_exists( $taxonomy_definition['slug'] ) ) {
				$taxonomy = $taxonomy_definition['slug'];
				break;
			}
		}

		$definitions[ $post_type ]['meta_fields'] = [
			[
				'key'          => 'qa_text',
				'label'        => 'QA Text',
				'type'         => 'text',
				'default'      => '',
				'options'      => '',
				'required'     => false,
				'show_in_rest' => true,
			],
			[
				'key'          => 'qa_budget',
				'label'        => 'QA Budget',
				'type'         => 'number',
				'default'      => '',
				'options'      => '',
				'required'     => false,
				'show_in_rest' => true,
			],
			[
				'key'          => 'qa_date',
				'label'        => 'QA Date',
				'type'         => 'date',
				'default'      => '',
				'options'      => '',
				'required'     => false,
				'show_in_rest' => true,
			],
			[
				'key'          => 'qa_select',
				'label'        => 'QA Select',
				'type'         => 'select',
				'default'      => '',
				'options'      => "standard|Standard\npremium|Premium",
				'required'     => false,
				'show_in_rest' => true,
			],
			[
				'key'          => 'qa_private',
				'label'        => 'QA Private',
				'type'         => 'number',
				'default'      => '',
				'options'      => '',
				'required'     => false,
				'show_in_rest' => false,
			],
		];

		update_option( CptManager::OPTION, $definitions, false );

		$options = ToolkitFieldCatalog::select_options();
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog includes text field', isset( $options['qa_text'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog includes number field', isset( $options['qa_budget'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog includes date field', isset( $options['qa_date'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog includes select field', isset( $options['qa_select'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog excludes private meta', ! isset( $options['qa_private'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit dynamic tag catalog includes taxonomy field', '' === $taxonomy || isset( $options[ $taxonomy ] ), [ 'taxonomy' => $taxonomy ] );

		$dynamic_tags = \Elementor\Plugin::$instance->dynamic_tags;
		$tags = $dynamic_tags->get_tags();
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-004', 'Toolkit field key dynamic tag registers', isset( $tags['eit-toolkit-field-key'] ) );

		$dynamic_text = $dynamic_tags->tag_data_to_tag_text( 'eit-qa', 'eit-toolkit-field-key', [ 'key' => 'qa_budget' ] );
		$resolved = FieldBindingResolver::resolve_filter( [ 'field_binding_dynamic' => $dynamic_text ] );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Serialized Toolkit dynamic tag resolves key', 'qa_budget' === $resolved['key'] && FieldBindingResolver::SOURCE_DYNAMIC_BINDING === $resolved['key_source'], $resolved );

		$preset_id = FilterPresets::save(
			[
				'name'    => 'QA Dynamic Binding',
				'slug'    => 'qa-dynamic-binding',
				'search_debounce_ms' => 375,
				'filters' => [
					FilterPresets::blank_filter(
						[
							'enabled'               => true,
							'label'                 => 'Budget',
							'type'                  => 'range',
							'field_binding_dynamic' => $dynamic_text,
							'range_min'             => 0,
							'range_max'             => 500,
						]
					),
				],
			]
		);
		$preset = FilterPresets::get( $preset_id );
		$filter = $preset['filters'][0] ?? [];
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Preset stores raw dynamic binding', ! empty( $filter['field_binding_dynamic'] ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Preset stores resolved dynamic key', 'qa_budget' === ( $filter['resolved_key'] ?? '' ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Preset stores dynamic key source', FieldBindingResolver::SOURCE_DYNAMIC_BINDING === ( $filter['key_source'] ?? '' ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-011', 'Preset stores Search debounce setting', 375 === ( $preset['search_debounce_ms'] ?? 0 ) );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-011', 'Preset import restores Search debounce setting', 375 === ( FilterSettings::preset_to_widget_settings( $preset )['search_debounce_ms'] ?? 0 ) );

		$mapped = FilterSettings::map_preset_filters_to_widget_filters( $preset['filters'] ?? [] );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-003', 'Preset import restores Elementor __dynamic__ field binding', ! empty( $mapped[0]['__dynamic__']['field_binding'] ) );

		$radio_preset_id = FilterPresets::save(
			[
				'name'    => 'QA Radio All Option',
				'slug'    => 'qa-radio-all-option',
				'filters' => [
					FilterPresets::blank_filter(
						[
							'enabled'         => true,
							'label'           => 'Tier',
							'type'            => 'radio',
							'key'             => 'qa_select',
							'options'         => "premium|Premium\nstandard|Standard",
							'radio_show_all'  => true,
							'radio_all_label' => 'All tiers',
						]
					),
				],
			]
		);
		$radio_preset = FilterPresets::get( $radio_preset_id );
		$radio_filter = $radio_preset['filters'][0] ?? [];
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-014', 'Preset stores Radio all option settings', ! empty( $radio_filter['radio_show_all'] ) && 'All tiers' === ( $radio_filter['radio_all_label'] ?? '' ) );
		$radio_mapped = FilterSettings::map_preset_filters_to_widget_filters( $radio_preset['filters'] ?? [] );
		eit_fc_assert( 'TEST-FC-ROBUSTNESS-014', 'Preset import restores Radio all option settings', 'yes' === ( $radio_mapped[0]['radio_show_all'] ?? '' ) && 'All tiers' === ( $radio_mapped[0]['radio_all_label'] ?? '' ) );

		$post_id = wp_insert_post(
			[
				'post_type'   => $post_type,
				'post_title'  => 'QA Alpha',
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			eit_fc_fail( 'TEST-FC-ROBUSTNESS-005', 'Fixture post insert', [ 'error' => $post_id->get_error_message() ] );
		} else {
			update_post_meta( $post_id, 'qa_text', 'blue cotton' );
			update_post_meta( $post_id, 'qa_budget', '125' );
			update_post_meta( $post_id, 'qa_date', '2026-03-15' );
			update_post_meta( $post_id, 'qa_select', 'premium' );
			update_post_meta( $post_id, 'qa_private', '125' );

			$term_slug = '';

			if ( '' !== $taxonomy ) {
				$term_slug = 'qa-term-' . wp_generate_password( 6, false, false );
				$term = wp_insert_term( 'QA Term', $taxonomy, [ 'slug' => $term_slug ] );

				if ( ! is_wp_error( $term ) ) {
					$term_id = absint( $term['term_id'] );
					wp_set_object_terms( $post_id, [ $term_slug ], $taxonomy );
				}
			}

			$resolver = new FilterResolver();
			$base_payload = [
				'items'   => [
					[
						'clientId'      => 'qa-item',
						'originalIndex' => 0,
						'postId'        => $post_id,
						'title'         => 'QA Alpha',
						'text'          => 'visible red sample',
						'classes'       => [ 'qa-card' ],
						'data'          => [
							'color'       => 'blue',
							'sort'        => '2',
							'price'       => '10',
							'post_type'   => $post_type,
							'qa_text'     => 'blue cotton',
							'qa_budget'   => '125',
							'qa_date'     => '2026-03-15',
							'qa_select'   => 'premium',
							$taxonomy     => $term_slug,
						],
					],
				],
				'perPage' => 12,
			];

			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Visible text filter matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'search', 'key' => '', 'value' => 'visible red' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Data attribute filter matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'radio', 'key' => 'color', 'value' => 'blue' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Registered meta range matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'range', 'key' => 'qa_budget', 'value' => [ 'min' => '100', 'max' => '150' ] ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Private meta is not auto-enriched', 0 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'range', 'key' => 'qa_private', 'value' => [ 'min' => '100', 'max' => '150' ] ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Registered meta date matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'date', 'key' => 'qa_date', 'value' => [ 'from' => '2026-03-01', 'to' => '2026-03-31' ] ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-018', 'Registered meta date normalizes inverted range', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'date', 'key' => 'qa_date', 'value' => [ 'from' => '2026-03-31', 'to' => '2026-03-01' ] ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Post field post_type matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'radio', 'key' => 'post_type', 'value' => $post_type ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit meta equals compare matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'select', 'key' => 'qa_select', 'source' => 'meta', 'compare' => 'equals', 'dataType' => 'string', 'value' => 'premium' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit meta equals compare rejects mismatch', 0 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'select', 'key' => 'qa_select', 'source' => 'meta', 'compare' => 'equals', 'dataType' => 'string', 'value' => 'standard' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit numeric between compare matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'range', 'key' => 'qa_budget', 'source' => 'meta', 'compare' => 'between', 'dataType' => 'number', 'value' => [ 'min' => '100', 'max' => '150' ] ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit numeric gte compare matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'range', 'key' => 'qa_budget', 'source' => 'meta', 'compare' => 'gte', 'dataType' => 'number', 'value' => '100' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit numeric lte compare rejects high value', 0 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'range', 'key' => 'qa_budget', 'source' => 'meta', 'compare' => 'lte', 'dataType' => 'number', 'value' => '100' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit exists compare matches populated meta', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'select', 'key' => 'qa_text', 'source' => 'meta', 'compare' => 'exists', 'dataType' => 'string', 'value' => '' ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Explicit date between compare matches', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'date', 'key' => 'qa_date', 'source' => 'meta', 'compare' => 'between', 'dataType' => 'date', 'value' => [ 'from' => '2026-03-01', 'to' => '2026-03-31' ] ] ] ] )['total'] );
			eit_fc_assert( 'TEST-FC-ROBUSTNESS-018', 'Explicit date between compare normalizes inverted range', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'date', 'key' => 'qa_date', 'source' => 'meta', 'compare' => 'between', 'dataType' => 'date', 'value' => [ 'from' => '2026-03-31', 'to' => '2026-03-01' ] ] ] ] )['total'] );

			if ( '' !== $term_slug ) {
				eit_fc_assert( 'TEST-FC-ROBUSTNESS-005', 'Taxonomy filter matches registered term', 1 === $resolver->resolve( $base_payload + [ 'filters' => [ [ 'type' => 'radio', 'key' => $taxonomy, 'value' => $term_slug ] ] ] )['total'], [ 'taxonomy' => $taxonomy, 'term' => $term_slug ] );
			} else {
				eit_fc_skip( 'TEST-FC-ROBUSTNESS-005', 'Taxonomy filter fixture', [ 'reason' => 'No registered Toolkit taxonomy fixture available.' ] );
			}
		}
	}
} finally {
	if ( $term_id && ! empty( $taxonomy ) ) {
		wp_delete_term( $term_id, $taxonomy );
	}

	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}

	update_option( CptManager::OPTION, $original_definitions, false );
	update_option( FilterPresets::OPTION, $original_presets, false );

	if ( ! empty( $created_disposable_cpt ) ) {
		if ( post_type_exists( 'eit_qa_cpt' ) && function_exists( 'unregister_post_type' ) ) {
			unregister_post_type( 'eit_qa_cpt' );
		}
		if ( taxonomy_exists( 'eit_qa_tax' ) && function_exists( 'unregister_taxonomy' ) ) {
			unregister_taxonomy( 'eit_qa_tax' );
		}
	}
}

