<?php
/**
 * Executes one immutable Collection contract and builds its public projection.
 */

namespace EIT\Collection;

use EIT\Blueprint\BlueprintModule;
use EIT\Blueprint\Uuid;
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

	public function __construct( array $dependencies = [] ) {
		$this->registries = $dependencies['registries'] ?? BlueprintModule::registries();
		$this->cache = $dependencies['cache'] ?? new CollectionCache();
		$this->projector = $dependencies['projector'] ?? new CollectionProjector();
		$this->hydrator = $dependencies['hydrator'] ?? new CollectionNormalizedHydrator();
		$this->renderer = $dependencies['renderer'] ?? new CollectionHtmlRenderer();
		$this->explainer = $dependencies['explainer'] ?? new CollectionExplainer();
		$this->facets = $dependencies['facets'] ?? new CollectionFacetPresenter();
	}

	public function execute( array $contract, array $request ) {
		$cached = $this->cache->get( $contract, $request );
		if ( is_array( $cached ) ) {
			$cached['request_id'] = Uuid::v4();
			return $cached;
		}
		$provider_id = $contract['provider']['id'] ?? '';
		$provider = $this->registries->collection_providers()->get( $provider_id );
		if ( ! $provider || ! hash_equals( (string) ( $contract['provider']['version'] ?? '' ), (string) $provider->get_version() ) ) {
			return $this->error( 'eit_collection_provider_version_mismatch', __( 'Collection provider version does not match the published contract.', 'elementor-implementation-toolkit' ), 503 );
		}
		$health = $provider->health_check();
		if ( ! is_array( $health ) || empty( $health['ok'] ) ) {
			return $this->error( 'eit_collection_provider_unhealthy', __( 'Collection provider failed its health check.', 'elementor-implementation-toolkit' ), 503 );
		}
		$result = $provider->query( $contract, $request, [ 'user_id' => get_current_user_id() ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) || ! isset( $result['items'], $result['total'], $result['page'], $result['per_page'], $result['pages'] ) ) {
			return $this->error( 'eit_collection_provider_response_invalid', __( 'Collection provider returned an invalid result.', 'elementor-implementation-toolkit' ), 500 );
		}
		$result['items'] = $this->hydrator->hydrate( $result['items'], $contract );
		$fields = $this->projector->fields( $contract );
		$items = $this->projector->items( $result['items'], $fields );
		$response = [
			'items' => $items,
			'html' => $this->renderer->render( $items, $fields ),
			'pagination' => $this->pagination( $result ),
			'facets' => $this->facets->present( $result['facets'] ?? [], $request, $contract, $fields ),
			'applied' => $this->applied( $request, $fields ),
			'explain' => ! empty( $request['explain'] ) ? $this->explainer->explain( $items, $request, $fields, $provider_id ) : [],
			'request_cost' => absint( $request['_cost'] ?? 0 ),
		];
		$this->cache->set( $contract, $request, $response );
		$response['request_id'] = Uuid::v4();
		return $response;
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

	private function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
