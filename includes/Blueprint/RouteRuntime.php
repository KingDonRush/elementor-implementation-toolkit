<?php
/**
 * Executes active virtual Route contracts without creating hidden WordPress pages.
 */

namespace EIT\Blueprint;

use EIT\CCT\CurrentItemContext;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Registry\ExtensionContract;
use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RouteRuntime {

	const QUERY_VAR = 'eit_route';
	const CHECKSUM_OPTION = 'eit_route_runtime_checksum';

	private static $current;
	private $contracts;
	private $blueprints;
	private $artifacts;
	private $registries;

	public function __construct( ?BlueprintStore $blueprints = null, ?ArtifactStore $artifacts = null, ?RegistryHub $registries = null ) {
		$this->blueprints = $blueprints ?: new BlueprintStore();
		$this->artifacts = $artifacts ?: new ArtifactStore();
		$this->registries = $registries ?: BlueprintModule::registries();
	}

	public function init_hooks() {
		add_action( 'init', [ $this, 'register_routes' ], 20 );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'template_redirect', [ $this, 'prepare_current' ], 1 );
		add_filter( 'template_include', [ $this, 'template' ], 99 );
		add_filter( 'document_title_parts', [ $this, 'document_title' ] );
		add_filter( 'redirect_canonical', [ $this, 'redirect_canonical' ], 10, 2 );
		add_action( 'wp_head', [ $this, 'canonical_link' ], 1 );
	}

	public function contracts() {
		if ( null !== $this->contracts ) {
			return $this->contracts;
		}
		$this->contracts = [];
		$public = [];
		foreach ( $this->blueprints->all() as $blueprint ) {
			$version_id = absint( $blueprint['active_version_id'] ?? 0 );
			if ( ! $version_id ) {
				continue;
			}
			foreach ( $this->artifacts->for_version( $version_id, 'route_contract' ) as $artifact ) {
				$contract = $artifact['payload'];
				$contract['blueprint_id'] = $blueprint['id'];
				$contract['version_id'] = $version_id;
				$contract['collision'] = false;
				$contract['invalid'] = false;
				$id = strtolower( (string) ( $contract['route_id'] ?? $artifact['node_id'] ) );
				$segments = $this->path_segments( $contract['path'] ?? '' );
				if ( 'virtual' === ( $contract['kind'] ?? '' ) && 'public' === ( $contract['exposure'] ?? '' ) ) {
					$contract['invalid'] = null === $segments;
					foreach ( $contract['invalid'] ? [] : $public as $owner_id => $owner ) {
						if ( $this->patterns_overlap( $segments, $owner['segments'] ) ) {
							$contract['collision'] = true;
							$this->contracts[ $owner_id ]['collision'] = true;
							do_action( 'eit_route_collision', (string) $contract['path'], $owner_id, $id );
						}
					}
					$public[ $id ] = [ 'segments' => $segments ];
				}
				$this->contracts[ $id ] = $contract;
			}
		}
		return $this->contracts;
	}

	public function register_routes() {
		$registered = [];
		foreach ( $this->contracts() as $route_id => $contract ) {
			if ( 'virtual' !== ( $contract['kind'] ?? '' ) || 'public' !== ( $contract['exposure'] ?? '' ) || ! empty( $contract['collision'] ) || ! empty( $contract['invalid'] ) ) {
				continue;
			}
			$regex = $this->rewrite_pattern( $contract['path'] ?? '' );
			if ( '' === $regex ) {
				continue;
			}
			add_rewrite_rule( '^' . $regex . '/?$', 'index.php?' . self::QUERY_VAR . '=' . rawurlencode( $route_id ), 'top' );
			$registered[ strtolower( trim( (string) $contract['path'], '/' ) ) ] = $route_id;
		}
		ksort( $registered );
		$checksum = hash( 'sha256', wp_json_encode( $registered ) );
		if ( ! hash_equals( (string) get_option( self::CHECKSUM_OPTION, '' ), $checksum ) ) {
			update_option( self::CHECKSUM_OPTION, $checksum, false );
			flush_rewrite_rules( false );
		}
	}

	public function query_vars( array $variables ) {
		$variables[] = self::QUERY_VAR;
		return array_values( array_unique( $variables ) );
	}

	public function prepare_current() {
		self::$current = null;
		$route_id = strtolower( sanitize_key( (string) get_query_var( self::QUERY_VAR ) ) );
		$contract = $this->contracts()[ $route_id ] ?? null;
		if ( ! $contract || 'public' !== ( $contract['exposure'] ?? '' ) || ! empty( $contract['collision'] ) || ! empty( $contract['invalid'] ) ) {
			return;
		}
		$matched = $this->match_route( $contract['path'] ?? '', $this->request_path() );
		if ( is_wp_error( $matched ) ) {
			$this->reject( $contract, $matched );
			return;
		}
		if ( $matched['parameters'] && RouteContractValidator::request_has_native_rewrite( $this->request_path() ) ) {
			$this->reject( $contract, new \WP_Error( 'eit_route_native_collision' ) );
			return;
		}
		$presentation = $this->presentation( $contract );
		$context = $matched['parameters'] ? $this->resolve_parameter_context( $contract, $presentation, $matched['parameters'] ) : [];
		if ( is_wp_error( $context ) ) {
			$this->reject( $contract, $context );
			return;
		}
		$adapter = $presentation ? $this->registries->presentation_adapters()->get( $presentation['adapter']['id'] ?? '' ) : null;
		$verified = $adapter ? ExtensionContract::verify( $adapter, $presentation['adapter'] ?? [] ) : new \WP_Error( 'eit_extension_missing' );
		if ( is_wp_error( $verified ) ) {
			do_action( 'eit_route_presentation_unavailable', $contract, $presentation );
			return;
		}
		$content = $this->render( $adapter, $presentation, $contract, $context );
		if ( is_wp_error( $content ) || '' === trim( (string) $content ) ) {
			$this->reject( $contract, is_wp_error( $content ) ? $content : new \WP_Error( 'eit_route_empty' ) );
			return;
		}
		$canonical_path = $this->canonical_path( $contract['path'], $context['parameters'] ?? [] );
		self::$current = [
			'contract' => $contract,
			'content' => (string) $content,
			'context' => $context,
			'canonical_url' => esc_url_raw( home_url( user_trailingslashit( '/' . ltrim( $canonical_path, '/' ) ) ) ),
		];
		status_header( 200 );
		global $wp_query;
		if ( is_object( $wp_query ) ) {
			$wp_query->is_404 = false;
			$wp_query->is_page = true;
		}
	}

	protected function resolve_parameter_context( array $route, $presentation, array $parameters ) {
		$all_sources = array_values( $presentation['sources'] ?? [] );
		$sources = array_values( array_filter( $all_sources, fn( $source ) => 'presents_collection' === ( $source['connection'] ?? '' ) ) );
		if ( 1 !== count( $sources ) || 1 !== count( $all_sources ) ) {
			return new \WP_Error( 'eit_route_collection_missing' );
		}
		$collection = $this->artifact_payload( $route['version_id'], 'collection_contract', $sources[0]['node_id'] ?? '' );
		$policy = $collection['policy'] ?? [];
		if (
			! $collection || 'public' !== ( $collection['access'] ?? '' ) || empty( $collection['entity']['definition']['public'] )
			|| 'any' !== ( $policy['ownership'] ?? 'any' ) || 'entity' !== ( $policy['object_scope'] ?? 'entity' )
		) {
			return new \WP_Error( 'eit_route_context_forbidden' );
		}
		$fields = array_column( $collection['fields'] ?? [], null, 'id' );
		$filters = [];
		foreach ( $parameters as $field_id => $value ) {
			$field = $fields[ $field_id ] ?? null;
			if (
				! $field || ! in_array( $field_id, $collection['filter_field_ids'] ?? [], true ) || empty( $field['exposure']['public'] )
				|| 'scalar' !== ( $field['shape'] ?? '' ) || ! in_array( $field['type'] ?? '', RouteContractValidator::ROUTE_FIELD_TYPES, true )
				|| empty( $field['capabilities']['filter'] ) || empty( $field['indexing']['filter'] )
			) {
				return new \WP_Error( 'eit_route_field_forbidden' );
			}
			$value = $this->typed_value( $value, $field );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$parameters[ $field_id ] = $value;
			$filters[] = [ 'field_id' => $field_id, 'operator' => 'equals', 'value' => $value ];
		}
		$provider = $this->registries->collection_providers()->get( $collection['provider']['id'] ?? '' );
		if ( ! $provider || is_wp_error( ExtensionContract::verify( $provider, $collection['provider'] ?? [] ) ) ) {
			return new \WP_Error( 'eit_route_provider_unavailable' );
		}
		$collection['blueprint_id'] = $route['blueprint_id'];
		$collection['version_id'] = $route['version_id'];
		try {
			$result = $provider->query( $collection, [ 'filters' => $filters, 'page' => 1, 'per_page' => 2, 'facets' => [] ], [ 'user_id' => get_current_user_id() ] );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'eit_route_provider_failed' );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = is_array( $result['items'] ?? null ) ? $result['items'] : [];
		$total = absint( $result['total'] ?? count( $items ) );
		if ( 1 !== $total || 1 !== count( $items ) || empty( $items[0]['id'] ) ) {
			return new \WP_Error( $total > 1 ? 'eit_route_item_ambiguous' : 'eit_route_item_not_found' );
		}
		$item_key = (string) $items[0]['id'];
		return [
			'entity_id' => (string) ( $collection['entity_id'] ?? '' ),
			'item_id' => ctype_digit( $item_key ) ? absint( $item_key ) : 0,
			'item_key' => $item_key,
			'item' => $items[0],
			'entity' => $collection['entity'],
			'parameters' => $parameters,
		];
	}

	protected function match_route( $pattern, $request_path ) {
		$segments = $this->path_segments( $pattern );
		if ( null === $segments || ! preg_match( '#^' . $this->rewrite_pattern( $pattern ) . '/?$#', trim( (string) $request_path, '/' ), $matches ) ) {
			return new \WP_Error( 'eit_route_request_mismatch' );
		}
		$parameters = [];
		$position = 1;
		foreach ( $segments as $segment ) {
			if ( empty( $segment['field_id'] ) ) {
				continue;
			}
			$value = (string) ( $matches[ $position++ ] ?? '' );
			if ( preg_match( '/%(?![0-9a-f]{2})/i', $value ) ) {
				return new \WP_Error( 'eit_route_parameter_invalid' );
			}
			$value = rawurldecode( $value );
			if ( '' === $value || strlen( $value ) > 191 || preg_match( '/[\/\\\\\x00-\x1f\x7f]/', $value ) || sanitize_text_field( $value ) !== $value ) {
				return new \WP_Error( 'eit_route_parameter_invalid' );
			}
			$parameters[ $segment['field_id'] ] = $value;
		}
		return [ 'parameters' => $parameters ];
	}

	protected function request_path() {
		global $wp;
		return is_object( $wp ) ? (string) ( $wp->request ?? '' ) : '';
	}

	private function render( $adapter, array $presentation, array $route, array $context ) {
		$pushed = false;
		$previous = $GLOBALS['post'] ?? null;
		$entity = $context['entity'] ?? [];
		$strategy = $entity['strategy'] ?? '';
		try {
			if ( ! empty( $context['item_id'] ) && ( 'cpt' === $strategy || 'woocommerce' === ( $entity['adapter']['id'] ?? '' ) ) ) {
				$post = get_post( $context['item_id'] );
				$expected_type = 'woocommerce' === ( $entity['adapter']['id'] ?? '' ) ? 'product' : (string) ( $entity['definition']['slug'] ?? '' );
				if ( ! $post || 'publish' !== $post->post_status || $expected_type !== $post->post_type ) {
					return new \WP_Error( 'eit_route_item_forbidden' );
				}
				$GLOBALS['post'] = $post;
				setup_postdata( $post );
			} elseif ( ! empty( $context['item'] ) ) {
				CurrentItemContext::push( $entity['definition']['slug'] ?? '', $context['item'] );
				$pushed = true;
			}
			return $adapter->render( $presentation, [ 'route' => $route, 'item_id' => $context['item_id'] ?? 0, 'entity_id' => $context['entity_id'] ?? '', 'item' => $context['item'] ?? null ] );
		} catch ( \Throwable $error ) {
			do_action( 'eit_route_presentation_failed', $route, get_class( $error ) );
			return new \WP_Error( 'eit_route_presentation_failed' );
		} finally {
			if ( $pushed ) {
				CurrentItemContext::pop();
			}
			if ( ! empty( $context['item_id'] ) && ( 'cpt' === $strategy || 'woocommerce' === ( $entity['adapter']['id'] ?? '' ) ) ) {
				$GLOBALS['post'] = $previous;
				$previous ? setup_postdata( $previous ) : wp_reset_postdata();
			}
		}
	}

	private function typed_value( $value, array $field ) {
		$type = $field['type'] ?? '';
		if ( 'integer' === $type ) {
			return preg_match( '/^-?(?:0|[1-9][0-9]*)$/', $value ) ? (int) $value : new \WP_Error( 'eit_route_parameter_type_invalid' );
		}
		return $value;
	}

	private function path_segments( $path ) {
		$relative = trim( (string) $path, '/' );
		if ( '' === $relative || strpbrk( $relative, '?#' ) ) {
			return null;
		}
		$segments = [];
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( preg_match( '/^[a-zA-Z0-9_-]+$/', $segment ) ) {
				$segments[] = [ 'literal' => $segment ];
			} elseif ( preg_match( '/^\{([0-9a-f-]{36})\}$/i', $segment, $match ) && Uuid::is_valid( $match[1] ) ) {
				$segments[] = [ 'field_id' => strtolower( $match[1] ) ];
			} else {
				return null;
			}
		}
		return $segments;
	}

	private function rewrite_pattern( $path ) {
		$segments = $this->path_segments( $path );
		if ( null === $segments ) {
			return '';
		}
		return implode( '/', array_map( fn( $segment ) => isset( $segment['literal'] ) ? preg_quote( $segment['literal'], '#' ) : '([A-Za-z0-9._~%-]{1,200})', $segments ) );
	}

	private function patterns_overlap( array $first, array $second ) {
		if ( count( $first ) !== count( $second ) ) {
			return false;
		}
		foreach ( $first as $offset => $segment ) {
			if ( isset( $segment['literal'], $second[ $offset ]['literal'] ) && strtolower( $segment['literal'] ) !== strtolower( $second[ $offset ]['literal'] ) ) {
				return false;
			}
		}
		return true;
	}

	private function canonical_path( $path, array $parameters ) {
		$parts = [];
		foreach ( $this->path_segments( $path ) ?: [] as $segment ) {
			$parts[] = isset( $segment['literal'] ) ? $segment['literal'] : rawurlencode( (string) ( $parameters[ $segment['field_id'] ] ?? '' ) );
		}
		return implode( '/', $parts );
	}

	private function reject( array $contract, \WP_Error $error ) {
		status_header( 404 );
		nocache_headers();
		do_action( 'eit_route_request_rejected', $contract, $error->get_error_code() );
	}

	public function redirect_canonical( $redirect_url, $requested_url = '' ) {
		return self::$current && $redirect_url ? self::$current['canonical_url'] : $redirect_url;
	}

	public function canonical_link() {
		if ( self::$current ) {
			printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( self::$current['canonical_url'] ) );
		}
	}

	public function template( $template ) {
		return self::$current ? dirname( __DIR__, 2 ) . '/templates/route.php' : $template;
	}

	public function document_title( array $parts ) {
		if ( self::$current ) {
			$parts['title'] = self::$current['context']['item']['title'] ?? self::$current['contract']['name'];
		}
		return $parts;
	}

	public static function current_content() {
		return (string) ( self::$current['content'] ?? '' );
	}

	public static function current_context() {
		$context = self::$current['context'] ?? [];
		return [
			'route_id' => (string) ( self::$current['contract']['route_id'] ?? '' ),
			'entity_id' => (string) ( $context['entity_id'] ?? '' ),
			'item_id' => (string) ( $context['item_key'] ?? $context['item_id'] ?? '' ),
			'parameters' => $context['parameters'] ?? [],
		];
	}

	private function presentation( array $route ) {
		return $this->artifact_payload( $route['version_id'], 'presentation_contract', $route['presentation_id'] ?? '' );
	}

	private function artifact_payload( $version_id, $kind, $node_id ) {
		foreach ( $this->artifacts->for_version( $version_id, $kind ) as $artifact ) {
			if ( (string) $node_id === (string) $artifact['node_id'] ) {
				return $artifact['payload'];
			}
		}
		return null;
	}
}
