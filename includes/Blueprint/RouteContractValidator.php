<?php
/**
 * Validates executable Route decisions outside the kernel grammar validator.
 */

namespace EIT\Blueprint;

use EIT\Contracts\FieldContractSourceInterface;
use EIT\Registry\RegistryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RouteContractValidator {

	const MAX_PARAMETERS = 4;
	const ROUTE_FIELD_TYPES = [ 'short_text', 'integer', 'single_choice', 'date' ];

	private $registries;

	public function __construct( ?RegistryHub $registries = null ) {
		$this->registries = $registries ?: ( new CoreRegistryFactory() )->create();
	}

	public function validate( array $nodes, array $connections = [] ) {
		$errors = [];
		$patterns = [];
		foreach ( $nodes as $node_id => $node ) {
			if ( 'route' !== ( $node['type'] ?? '' ) ) {
				continue;
			}
			$raw = trim( (string) ( $node['config']['path'] ?? '' ) );
			$exposure = sanitize_key( $node['config']['exposure'] ?? 'public' );
			$path = 'nodes.' . $node_id . '.config';
			if ( '' === $raw || strlen( $raw ) > 2048 ) {
				$errors[] = $this->error( $path . '.path', 'route_path_invalid', 'Route path must be present and at most 2048 bytes.', $node_id );
				continue;
			}
			if ( ! in_array( $exposure, [ 'public', 'internal' ], true ) ) {
				$errors[] = $this->error( $path . '.exposure', 'route_exposure_invalid', 'Route exposure must be public or internal.', $node_id );
			}
			if ( filter_var( $raw, FILTER_VALIDATE_URL ) ) {
				continue;
			}
			$contract = $this->path_contract( $raw );
			if ( ! $contract ) {
				$errors[] = $this->error( $path . '.path', 'route_path_invalid', 'Executable routes use literal segments or whole-segment stable Field ID placeholders.', $node_id );
				continue;
			}
			foreach ( $patterns as $existing ) {
				if ( ! $this->patterns_overlap( $contract['segments'], $existing['segments'] ) ) {
					continue;
				}
				$code = strtolower( trim( $raw, '/' ) ) === $existing['path'] ? 'duplicate_route_path' : 'ambiguous_route_pattern';
				$message = 'duplicate_route_path' === $code
					? 'Executable route paths must be unique inside a Blueprint.'
					: 'Literal and parameterized Route patterns must not match the same request path.';
				$errors[] = $this->error( $path . '.path', $code, $message, $node_id );
			}
			$patterns[] = [ 'path' => strtolower( trim( $raw, '/' ) ), 'segments' => $contract['segments'] ];
			if ( $contract['parameters'] ) {
				$this->validate_parameter_context( $node_id, $contract['parameters'], $nodes, $connections, $errors );
			}
			$this->validate_presentation( $node_id, $nodes, $connections, $errors, (bool) $contract['parameters'] );
		}
		return $errors;
	}

	public static function request_has_native_rewrite( $path ) {
		global $wp_rewrite;
		if ( ! is_object( $wp_rewrite ) || ! is_callable( [ $wp_rewrite, 'wp_rewrite_rules' ] ) ) {
			return false;
		}
		$matches = [];
		foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $regex => $query ) {
			if ( false !== strpos( (string) $query, self::route_query_marker() ) ) {
				continue;
			}
			$pattern = '~^' . str_replace( '~', '\\~', (string) $regex ) . '~';
			if ( 1 === @preg_match( $pattern, trim( (string) $path, '/' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Third-party rewrite regexes can be malformed; malformed owners are ignored.
				$matches[] = [ (string) $regex, (string) $query ];
			}
		}
		if ( ! $matches ) {
			return false;
		}
		foreach ( $matches as [ $regex, $query ] ) {
			$generic_page = '(.?.+?)(?:/([0-9]+))?/?$' === $regex && false !== strpos( $query, 'pagename=$matches[1]' );
			$generic_attachment = '[^/]+/([^/]+)/?$' === $regex && false !== strpos( $query, 'attachment=$matches[1]' );
			if ( $generic_page || $generic_attachment ) {
				$type = $generic_page ? 'page' : 'attachment';
				$owner_path = $generic_page ? trim( (string) $path, '/' ) : basename( (string) $path );
				$owner = function_exists( 'get_page_by_path' ) ? get_page_by_path( $owner_path, OBJECT, $type ) : null;
				if ( $owner && in_array( $owner->post_status ?? '', [ 'publish', 'inherit' ], true ) ) {
					return true;
				}
			}
			if ( ! $generic_page && ! $generic_attachment ) {
				return true;
			}
		}
		return false;
	}

	private static function route_query_marker() {
		return RouteRuntime::QUERY_VAR . '=';
	}

	private function path_contract( $raw ) {
		$relative = trim( (string) $raw, '/' );
		if ( '' === $relative || strpbrk( $relative, '?#' ) ) {
			return null;
		}
		$parameters = [];
		$segments = [];
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( preg_match( '/^[a-zA-Z0-9_-]+$/', $segment ) ) {
				$segments[] = [ 'literal' => strtolower( $segment ) ];
				continue;
			}
			if ( preg_match( '/^\{([0-9a-f-]{36})\}$/i', $segment, $match ) && Uuid::is_valid( $match[1] ) ) {
				$field_id = strtolower( $match[1] );
				if ( isset( $parameters[ $field_id ] ) || count( $parameters ) >= self::MAX_PARAMETERS ) {
					return null;
				}
				$parameters[ $field_id ] = true;
				$segments[] = [ 'field_id' => $field_id ];
				continue;
			}
			return null;
		}
		return [ 'segments' => $segments, 'parameters' => array_keys( $parameters ) ];
	}

	private function patterns_overlap( array $first, array $second ) {
		if ( count( $first ) !== count( $second ) ) {
			return false;
		}
		foreach ( $first as $offset => $segment ) {
			if ( isset( $segment['literal'], $second[ $offset ]['literal'] ) && $segment['literal'] !== $second[ $offset ]['literal'] ) {
				return false;
			}
		}
		return true;
	}

	private function validate_parameter_context( $route_id, array $field_ids, array $nodes, array $connections, array &$errors ) {
		$presentation_id = $this->connected_id( $route_id, 'routes', 'from', 'to', $connections );
		$collection_ids = $this->connected_ids( $presentation_id, 'presents_collection', 'from', 'to', $connections );
		if ( 1 !== count( $collection_ids ) ) {
			$errors[] = $this->error( 'nodes.' . $route_id . '.config.path', 'route_parameter_collection_required', 'A parameterized Route requires exactly one Collection as its query authority.', $route_id );
			return;
		}
		$collection_id = $collection_ids[0];
		$entity_id = $this->connected_id( $collection_id, 'collection_for', 'from', 'to', $connections );
		$entity = $nodes[ $entity_id ] ?? [];
		$access = sanitize_key( $nodes[ $collection_id ]['config']['access'] ?? '' );
		if ( empty( $entity['config']['public'] ) || ( $access && 'public' !== $access ) ) {
			$errors[] = $this->error( 'nodes.' . $route_id . '.config.path', 'route_parameter_context_private', 'A public item Route may resolve only through a public Entity and Collection.', $route_id );
			return;
		}
		$surface_ids = $this->connected_ids( $collection_id, 'filters', 'to', 'from', $connections );
		if ( 1 !== count( $surface_ids ) ) {
			$errors[] = $this->error( 'nodes.' . $route_id . '.config.path', 'route_parameter_filter_required', 'A parameterized Route requires one Filter Surface that authorizes its Field IDs.', $route_id );
			return;
		}
		$fields = $this->entity_fields( $entity_id, $nodes, $connections );
		$selected = $nodes[ $surface_ids[0] ]['config']['fields'] ?? [];
		$selected = $selected ? array_fill_keys( array_map( 'strval', (array) $selected ), true ) : array_fill_keys( array_keys( $fields ), true );
		foreach ( $field_ids as $field_id ) {
			$field = $fields[ $field_id ] ?? null;
			$operators = $field['capabilities']['filter_operators'] ?? [];
			if (
				! $field || ! isset( $selected[ $field_id ] ) || 'scalar' !== ( $field['shape'] ?? '' )
				|| ! in_array( $field['type'] ?? '', self::ROUTE_FIELD_TYPES, true )
				|| empty( $field['exposure']['public'] ) || empty( $field['capabilities']['filter'] ) || empty( $field['indexing']['filter'] )
				|| ( $operators && ! in_array( 'equals', $operators, true ) )
			) {
				$errors[] = $this->error( 'nodes.' . $route_id . '.config.path', 'route_parameter_field_invalid', 'Route placeholders must reference public, scalar, exactly-filterable Field IDs from the owning Collection.', $route_id );
			}
		}
	}

	private function entity_fields( $entity_id, array $nodes, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( 'adapts' !== ( $connection['type'] ?? '' ) || $entity_id !== ( $connection['to'] ?? '' ) ) {
				continue;
			}
			$adapter_id = sanitize_key( $nodes[ $connection['from'] ]['config']['adapter_id'] ?? '' );
			$adapter = $this->registries->storage_adapters()->get( $adapter_id );
			if ( $adapter instanceof FieldContractSourceInterface ) {
				try {
					return array_column( $adapter->get_field_contracts( $nodes[ $entity_id ] ?? [] ), null, 'id' );
				} catch ( \Throwable $error ) {
					return [];
				}
			}
		}
		$fields = [];
		foreach ( $connections as $connection ) {
			if ( 'entity_fields' !== ( $connection['type'] ?? '' ) || $entity_id !== ( $connection['from'] ?? '' ) ) {
				continue;
			}
			foreach ( $nodes[ $connection['to'] ]['config']['fields'] ?? [] as $field ) {
				$fields[ strtolower( (string) ( $field['id'] ?? '' ) ) ] = $field;
			}
		}
		return $fields;
	}

	private function validate_presentation( $route_id, array $nodes, array $connections, array &$errors, $parameterized = false ) {
		$presentation_ids = $this->connected_ids( $route_id, 'routes', 'from', 'to', $connections );
		if ( 1 !== count( $presentation_ids ) || 'presentation' !== ( $nodes[ $presentation_ids[0] ]['type'] ?? '' ) ) {
			$errors[] = $this->unrenderable_error( $route_id );
			return;
		}

		$presentation_id = $presentation_ids[0];
		$config = $nodes[ $presentation_id ]['config'] ?? [];
		$documents = array_filter( [ absint( $config['template_id'] ?? 0 ), absint( $config['document_id'] ?? 0 ) ] );
		$supported = [];
		$unsupported = [];
		foreach ( $connections as $connection ) {
			$type = (string) ( $connection['type'] ?? '' );
			if ( $presentation_id === ( $connection['to'] ?? '' ) && in_array( $type, [ 'presents_entry', 'presents_collection' ], true ) ) {
				$supported[] = $type;
			} elseif ( $presentation_id === ( $connection['to'] ?? '' ) && 'presents_entity' === $type ) {
				$unsupported[] = $type;
			} elseif ( 'composes' === $type && ( $presentation_id === ( $connection['to'] ?? '' ) || $presentation_id === ( $connection['from'] ?? '' ) ) ) {
				$unsupported[] = $type;
			}
		}
		if ( $parameterized ) {
			$valid = ! $unsupported && [ 'presents_collection' ] === $supported && count( $documents ) <= 1;
		} else {
			$valid = ! $unsupported && 1 === count( $documents ) + count( $supported );
		}
		if ( ! $valid ) {
			$errors[] = $this->unrenderable_error( $presentation_id );
		}
	}

	private function connected_ids( $node_id, $type, $side, $match_side, array $connections ) {
		$ids = [];
		foreach ( $connections as $connection ) {
			if ( $type === ( $connection['type'] ?? '' ) && $node_id === ( $connection[ $match_side ] ?? '' ) ) {
				$ids[] = (string) ( $connection[ $side ] ?? '' );
			}
		}
		return array_values( array_filter( $ids ) );
	}

	private function connected_id( $node_id, $type, $side, $match_side, array $connections ) {
		return $this->connected_ids( $node_id, $type, $side, $match_side, $connections )[0] ?? '';
	}

	private function unrenderable_error( $node_id ) {
		return $this->error( 'nodes.' . $node_id . '.config', 'route_presentation_unrenderable', 'Routed Presentation must resolve one executable document or connector with an unambiguous item context.', $node_id );
	}

	private function error( $path, $code, $message, $node_id ) {
		return [ 'path' => $path, 'code' => $code, 'message' => $message, 'node_id' => $node_id ];
	}
}
