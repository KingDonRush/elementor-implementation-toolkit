<?php
/**
 * Executes one immutable Collection contract and builds its public projection.
 */

namespace EIT\Collection;

use EIT\Blueprint\BlueprintModule;
use EIT\Blueprint\Uuid;
use EIT\Registry\ExtensionContract;
use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionQueryService {

	private $registries;
	private $cache;
	private $projector;
	private $hydrator;
	private $renderer;
	private $explainer;
	private $facets;
	private $scope;

	public function __construct( array $dependencies = [] ) {
		$this->registries = $dependencies['registries'] ?? BlueprintModule::registries();
		$this->cache = $dependencies['cache'] ?? new CollectionCache();
		$this->projector = $dependencies['projector'] ?? new CollectionProjector();
		$this->hydrator = $dependencies['hydrator'] ?? new CollectionNormalizedHydrator();
		$this->renderer = $dependencies['renderer'] ?? new CollectionPresentationRenderer();
		$this->explainer = $dependencies['explainer'] ?? new CollectionExplainer();
		$this->facets = $dependencies['facets'] ?? new CollectionFacetPresenter();
		$this->scope = $dependencies['scope'] ?? new CollectionPolicyScope();
	}

	public function execute( array $contract, array $request ) {
		$contract = $this->public_contract( $contract );
		$request = $this->scope->apply( $contract, $request, [ 'user_id' => get_current_user_id() ] );
		$provider = $this->runtime_provider( $contract );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$cached = $this->cache->get( $contract, $request );
		if ( is_array( $cached ) ) {
			$cached['request_id'] = Uuid::v4();
			return $cached;
		}
		$provider_id = $contract['provider']['id'] ?? '';
		try {
			$result = $provider->query( $contract, $request, [ 'user_id' => get_current_user_id() ] );
		} catch ( \Throwable $error ) {
			do_action( 'eit_collection_provider_failed', $provider_id, $contract['collection_id'] ?? '', get_class( $error ) );
			return $this->error( 'eit_collection_provider_failed', __( 'Collection provider could not complete the query.', 'elementor-implementation-toolkit' ), 502 );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) || ! isset( $result['items'], $result['total'], $result['page'], $result['per_page'], $result['pages'] ) ) {
			return $this->error( 'eit_collection_provider_response_invalid', __( 'Collection provider returned an invalid result.', 'elementor-implementation-toolkit' ), 500 );
		}
		$result['items'] = $this->hydrator->hydrate( $result['items'], $contract );
		$public_fields = array_column( $contract['fields'] ?? [], null, 'id' );
		$fields = $this->projector->fields( $contract );
		$items = $this->projector->items( $result['items'], $fields );
		$explain_fields = $this->explain_fields( $contract, $request );
		$explain_items = ! empty( $request['explain'] ) ? $this->projector->items( $result['items'], $explain_fields ) : [];
		$response = [
			'items' => $items,
			'html' => $this->renderer->render( $items, $fields, $contract ),
			'pagination' => $this->pagination( $result ),
			'facets' => $this->facets->present( $result['facets'] ?? [], $request, $contract, $public_fields ),
			'applied' => $this->applied( $request, $public_fields ),
			'explain' => ! empty( $request['explain'] ) ? $this->explainer->explain( $explain_items, $request, $explain_fields, $provider_id ) : [],
			'request_cost' => absint( $request['_cost'] ?? 0 ),
		];
		$this->cache->set( $contract, $request, $response );
		$response['request_id'] = Uuid::v4();
		return $response;
	}

	private function runtime_provider( array $contract ) {
		$provider_contract = $contract['provider'] ?? [];
		$provider = $this->registries->collection_providers()->get( $provider_contract['id'] ?? '' );
		if ( ! $provider ) {
			return $this->error( 'eit_collection_provider_missing', __( 'Collection provider is not registered.', 'elementor-implementation-toolkit' ), 503 );
		}
		$current = ExtensionContract::snapshot( $provider );
		if ( is_wp_error( $current ) && 'eit_extension_unavailable' === $current->get_error_code() ) {
			return $this->error( 'eit_collection_provider_unhealthy', __( 'Collection provider failed its health check.', 'elementor-implementation-toolkit' ), 503 );
		}
		if ( is_wp_error( $current ) || ! hash_equals( (string) ( $provider_contract['id'] ?? '' ), (string) ( $current['id'] ?? '' ) ) ) {
			return $this->error( 'eit_collection_provider_incompatible', __( 'Collection provider metadata is invalid.', 'elementor-implementation-toolkit' ), 503 );
		}
		if ( ! hash_equals( (string) ( $provider_contract['version'] ?? '' ), $current['version'] ) ) {
			return $this->error( 'eit_collection_provider_version_mismatch', __( 'Collection provider version does not match the published contract.', 'elementor-implementation-toolkit' ), 503 );
		}
		$expected = ExtensionContract::capabilities( $provider_contract['capabilities'] ?? null );
		if ( null === $expected || $current['capabilities'] !== $expected ) {
			return $this->error( 'eit_collection_provider_capabilities_mismatch', __( 'Collection provider capabilities do not match the published contract.', 'elementor-implementation-toolkit' ), 503 );
		}
		$required = ExtensionContract::capabilities( $provider_contract['required_capabilities'] ?? null );
		if ( null === $required || array_diff( $required, $current['capabilities'] ) ) {
			return $this->error( 'eit_collection_provider_requirements_invalid', __( 'Collection provider does not satisfy the published query requirements.', 'elementor-implementation-toolkit' ), 503 );
		}
		return $provider;
	}

	private function explain_fields( array $contract, array $request ) {
		$allowed = array_fill_keys( $contract['filter_field_ids'] ?? [], true );
		$requested = array_fill_keys( array_column( $request['filters'] ?? [], 'field_id' ), true );
		$result = [];
		foreach ( $contract['fields'] ?? [] as $field ) {
			$field_id = $field['id'] ?? '';
			if ( isset( $allowed[ $field_id ], $requested[ $field_id ] ) && ! empty( $field['exposure']['public'] ) ) {
				$result[ $field_id ] = $field;
			}
		}
		return $result;
	}

	private function pagination( array $result ) {
		$page = max( 1, (int) $result['page'] );
		$pages = max( 1, (int) $result['pages'] );
		return [
			'page' => $page,
			'per_page' => max( 1, (int) $result['per_page'] ),
			'total' => max( 0, (int) $result['total'] ),
			'pages' => $pages,
			'has_previous' => $page > 1,
			'has_next' => $page < $pages,
		];
	}

	private function applied( array $request, array $fields ) {
		$filters = [];
		foreach ( $request['filters'] ?? [] as $filter ) {
			$field = $fields[ $filter['field_id'] ] ?? null;
			if ( $field ) {
				$filters[] = [ 'field_id' => $field['id'], 'label' => $field['name'], 'operator' => $filter['operator'], 'value' => $filter['value'] ];
			}
		}
		$sort = $request['sort'] ?? [];
		$field = $fields[ $sort['field_id'] ?? '' ] ?? null;
		return [
			'filters' => $filters,
			'search' => (string) ( $request['search'] ?? '' ),
			'sort' => $field ? [ 'field_id' => $field['id'], 'label' => $field['name'], 'direction' => $sort['direction'] ] : null,
		];
	}

	private function public_contract( array $contract ) {
		$fields = array_values( array_filter( $contract['fields'] ?? [], fn( $field ) => ! empty( $field['exposure']['public'] ) ) );
		$allowed = array_fill_keys( array_column( $fields, 'id' ), true );
		$contract['fields'] = $fields;
		foreach ( [ 'projection_field_ids', 'filter_field_ids', 'sort_field_ids', 'search_field_ids' ] as $key ) {
			$contract[ $key ] = array_values( array_filter( $contract[ $key ] ?? [], fn( $field_id ) => isset( $allowed[ $field_id ] ) ) );
		}
		if ( is_array( $contract['filter_surface'] ?? null ) ) {
			$contract['filter_surface']['facet_field_ids'] = array_values( array_filter( $contract['filter_surface']['facet_field_ids'] ?? [], fn( $field_id ) => isset( $allowed[ $field_id ] ) ) );
		}
		return $contract;
	}

	private function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
