<?php
/**
 * Prevents active Blueprints and third-party runtimes from sharing owned identities.
 */

namespace EIT\Blueprint;

use EIT\CCT\SchemaManager as CctSchemaManager;
use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Infrastructure\StorageClaimStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StorageOwnershipValidator {

	private $blueprints;
	private $artifacts;
	private $migrations;
	private $claims;

	public function __construct( ?BlueprintStore $blueprints = null, ?ArtifactStore $artifacts = null, ?MigrationPublicationGuard $migrations = null, $claims = null ) {
		$this->blueprints = $blueprints ?: new BlueprintStore();
		$this->artifacts = $artifacts ?: new ArtifactStore();
		$this->migrations = $migrations ?: new MigrationPublicationGuard();
		$this->claims = $claims;
	}

	public function validate( $blueprint_id, array $artifacts ) {
		$blockers = $this->blockers( $blueprint_id, $artifacts );
		return $blockers
			? new \WP_Error( 'eit_storage_ownership_conflict', __( 'Compiled storage or a public route is already owned by another runtime.', 'elementor-implementation-toolkit' ), [ 'status' => 409, 'blockers' => $blockers ] )
			: true;
	}

	public function blockers( $blueprint_id, array $artifacts ) {
		$active = $this->active_owners( $blueprint_id );
		$proposed = [];
		$route_patterns = [];
		$field_ids = [];
		$node_ids = [];
		$blockers = [];
		foreach ( $artifacts as $artifact ) {
			$artifact_node_id = (string) ( $artifact['node_id'] ?? '' );
			if ( '' !== $artifact_node_id && ! isset( $node_ids[ $artifact_node_id ] ) ) {
				$node_ids[ $artifact_node_id ] = true;
				if ( isset( $active['node_ids'][ $artifact_node_id ] ) ) {
					$blockers[] = [
						'code' => 'node_identity_owned',
						'node_id' => $artifact_node_id,
						'owner' => [ 'blueprint_id' => $active['node_ids'][ $artifact_node_id ] ],
						'message' => 'Another active Blueprint already owns this Node UUID.',
					];
				}
			}
			if ( 'route_contract' === ( $artifact['kind'] ?? '' ) ) {
				$payload = $artifact['payload'] ?? [];
				$route = $this->public_virtual_route( $payload );
				if ( ! $route ) {
					continue;
				}
				$route_path = $route['path'];
				$node_id = (string) ( $payload['route_id'] ?? $artifact['node_id'] ?? '' );
				foreach ( $route_patterns as $owner ) {
					if ( $owner['node_id'] !== $node_id && $this->route_patterns_overlap( $route['segments'], $owner['segments'] ) ) {
						$exact = $route_path === $owner['path'];
						$blockers[] = $this->route_blocker( $exact ? 'blueprint_route_path_duplicate' : 'blueprint_route_pattern_ambiguous', $route_path, $node_id, $exact ? 'Two public Routes in this Blueprint compile to the same virtual path.' : 'Two public Routes in this Blueprint can match the same request path.' );
					}
				}
				$route_patterns[] = [ 'path' => $route_path, 'segments' => $route['segments'], 'node_id' => $node_id ];
				foreach ( $active['route_patterns'] as $owner ) {
					if ( ! $this->route_patterns_overlap( $route['segments'], $owner['segments'] ) ) {
						continue;
					}
					$exact = $route_path === $owner['path'];
					$blockers[] = $this->route_blocker(
						$exact ? 'route_path_owned' : 'route_pattern_owned',
						$route_path,
						$node_id,
						$exact ? 'Another active Blueprint already owns this public virtual path.' : 'Another active Blueprint owns an overlapping public Route pattern.',
						[ 'blueprint_id' => $owner['blueprint_id'], 'route_path' => $owner['path'] ]
					);
				}
				$reserved = $this->reserved_route_owner( $route_path );
				if ( $reserved ) {
					$blockers[] = $this->route_blocker( 'route_path_reserved', $route_path, $node_id, 'WordPress reserves this public path.', [ 'path' => $reserved ] );
				}
				foreach ( $route['parameterized'] ? [] : $this->wordpress_route_owners( $route_path ) as $owner ) {
					$type = in_array( $owner['type'] ?? '', [ 'page', 'post_type', 'taxonomy' ], true ) ? $owner['type'] : 'runtime';
					$blockers[] = $this->route_blocker( 'route_path_wordpress_' . $type, $route_path, $node_id, 'WordPress content or rewrite ownership conflicts with this public path.', $owner );
				}
				continue;
			}
			if ( 'entity_definition' !== ( $artifact['kind'] ?? '' ) ) {
				continue;
			}
			$payload = $artifact['payload'] ?? [];
			$identity = $this->identity( $payload );
			$key = wp_json_encode( $identity );
			$node_id = (string) ( $payload['entity_id'] ?? $artifact['node_id'] ?? '' );
			if ( isset( $proposed[ $key ] ) && $proposed[ $key ] !== $node_id ) {
				$blockers[] = $this->blocker( 'blueprint_storage_identity_duplicate', $identity, $node_id, 'Two Entities in this Blueprint compile to the same storage identity.' );
			}
			$proposed[ $key ] = $node_id;
			$owned_by_other = isset( $active['identities'][ $key ] );
			$claim = $this->claim_owner( $identity );
			$claimed_by_self = is_array( $claim ) && (string) ( $claim['blueprint_id'] ?? '' ) === (string) $blueprint_id;
			if ( $owned_by_other ) {
				$blockers[] = $this->blocker( 'storage_identity_owned', $identity, $node_id, 'Another active Blueprint already owns this storage identity.' );
			}
			if ( is_array( $claim ) && ! $claimed_by_self ) {
				$blocker = $this->blocker( 'storage_identity_claimed', $identity, $node_id, 'Another Blueprint has a durable claim on this storage identity.' );
				$blocker['owner'] = [ 'blueprint_id' => (string) ( $claim['blueprint_id'] ?? '' ), 'status' => (string) ( $claim['status'] ?? '' ) ];
				$blockers[] = $blocker;
			}
			if ( ! $owned_by_other && ! $claimed_by_self && ! isset( $active['own_identities'][ $key ] ) && $this->external_identity_exists( $identity ) ) {
				$blueprint = $this->blueprints->get( $blueprint_id );
				if ( ! is_array( $blueprint ) || ! $this->migrations->allows_handoff( $blueprint, $identity ) ) {
					$blockers[] = $this->blocker( 'storage_identity_external', $identity, $node_id, 'WordPress or another plugin already owns this storage identity.' );
				}
			}
			foreach ( $payload['fields'] ?? [] as $field ) {
				$field_id = (string) ( $field['id'] ?? '' );
				if ( '' === $field_id ) {
					continue;
				}
				if ( isset( $field_ids[ $field_id ] ) && $field_ids[ $field_id ] !== $node_id ) {
					$blockers[] = [ 'code' => 'blueprint_field_identity_duplicate', 'field_id' => $field_id, 'node_id' => $node_id, 'message' => 'Field UUID is duplicated across compiled Entities.' ];
				}
				$field_ids[ $field_id ] = $node_id;
				if ( isset( $active['field_ids'][ $field_id ] ) ) {
					$blockers[] = [ 'code' => 'field_identity_owned', 'field_id' => $field_id, 'node_id' => $node_id, 'message' => 'Another active Blueprint already owns this Field UUID.' ];
				}
			}
		}
		return $this->unique_blockers( $blockers );
	}

	private function active_owners( $blueprint_id ) {
		$result = [ 'identities' => [], 'own_identities' => [], 'field_ids' => [], 'node_ids' => [], 'route_patterns' => [] ];
		foreach ( $this->blueprints->all() as $blueprint ) {
			if ( empty( $blueprint['active_version_id'] ) ) {
				continue;
			}
			$own = (string) $blueprint_id === (string) $blueprint['id'];
			$artifacts = $this->artifacts->for_version( $blueprint['active_version_id'] );
			foreach ( $artifacts as $artifact ) {
				if ( ! $own && ! empty( $artifact['node_id'] ) ) {
					$result['node_ids'][ (string) $artifact['node_id'] ] = (string) $blueprint['id'];
				}
				if ( 'entity_definition' !== ( $artifact['kind'] ?? '' ) ) {
					continue;
				}
				$payload = $artifact['payload'] ?? [];
				$key = wp_json_encode( $this->identity( $payload ) );
				if ( $own ) {
					$result['own_identities'][ $key ] = true;
				} else {
					$result['identities'][ $key ] = (string) $blueprint['id'];
					foreach ( $payload['fields'] ?? [] as $field ) {
						if ( ! empty( $field['id'] ) ) {
							$result['field_ids'][ $field['id'] ] = (string) $blueprint['id'];
						}
					}
				}
			}
			foreach ( $own ? [] : $artifacts as $artifact ) {
				if ( 'route_contract' !== ( $artifact['kind'] ?? '' ) ) {
					continue;
				}
				$route = $this->public_virtual_route( $artifact['payload'] ?? [] );
				if ( $route ) {
					$result['route_patterns'][] = [ 'path' => $route['path'], 'segments' => $route['segments'], 'blueprint_id' => (string) $blueprint['id'] ];
				}
			}
		}
		return $result;
	}

	private function identity( array $payload ) {
		return [
			'strategy' => sanitize_key( $payload['strategy'] ?? '' ),
			'adapter' => sanitize_key( $payload['adapter']['id'] ?? '' ),
			'slug' => sanitize_key( $payload['definition']['slug'] ?? '' ),
		];
	}

	protected function external_identity_exists( array $identity ) {
		if ( 'cpt' === $identity['strategy'] ) {
			return function_exists( 'post_type_exists' ) && post_type_exists( $identity['slug'] );
		}
		return 'cct' === $identity['strategy'] && CctSchemaManager::table_exists( $identity['slug'] );
	}

	private function claim_owner( array $identity ) {
		if ( ! $this->claims instanceof StorageClaimStore || ! in_array( $identity['strategy'] ?? '', [ 'cpt', 'cct' ], true ) ) {
			return null;
		}
		return $this->claims->owner( $identity['strategy'], $identity['slug'] ?? '' );
	}

	private function public_virtual_route( array $payload ) {
		$raw = trim( (string) ( $payload['path'] ?? '' ) );
		$kind = sanitize_key( $payload['kind'] ?? ( filter_var( $raw, FILTER_VALIDATE_URL ) ? 'existing_document' : 'virtual' ) );
		$exposure = sanitize_key( $payload['exposure'] ?? 'public' );
		if ( 'virtual' !== $kind || 'public' !== $exposure ) {
			return null;
		}
		$path = strtolower( trim( trim( $raw ), '/' ) );
		$segments = [];
		$parameterized = false;
		foreach ( explode( '/', $path ) as $segment ) {
			if ( preg_match( '/^[a-z0-9_-]+$/', $segment ) ) {
				$segments[] = [ 'literal' => $segment ];
				continue;
			}
			if ( preg_match( '/^\{([0-9a-f-]{36})\}$/', $segment, $match ) && Uuid::is_valid( $match[1] ) ) {
				$segments[] = [ 'field_id' => $match[1] ];
				$parameterized = true;
				continue;
			}
			return null;
		}
		return $segments ? [ 'path' => $path, 'segments' => $segments, 'parameterized' => $parameterized ] : null;
	}

	private function route_patterns_overlap( array $first, array $second ) {
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

	private function normalize_route_path( $path ) {
		$path = strtolower( trim( trim( (string) $path ), '/' ) );
		return preg_match( '#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$#', $path ) ? $path : '';
	}

	private function reserved_route_owner( $path ) {
		foreach ( [ 'wp-admin', 'wp-json', 'feed', 'author', 'search' ] as $reserved ) {
			if ( $path === $reserved || 0 === strpos( $path, $reserved . '/' ) ) {
				return $reserved;
			}
		}
		return '';
	}

	protected function wordpress_route_owners( $path ) {
		$owners = [];
		$page = $this->wordpress_page( $path );
		if ( $page && 'publish' === $this->owner_value( $page, 'post_status' ) ) {
			$owners['page:' . $this->owner_value( $page, 'ID', $path )] = [ 'type' => 'page', 'id' => $this->owner_value( $page, 'ID' ) ];
		}
		foreach ( $this->public_post_types() as $post_type ) {
			$name = (string) $this->owner_value( $post_type, 'name' );
			$rewrite = $this->owner_value( $post_type, 'rewrite', [] );
			$rewrite_slug = is_array( $rewrite ) ? (string) ( $rewrite['slug'] ?? '' ) : '';
			$archive = $this->owner_value( $post_type, 'has_archive', false );
			$paths = $rewrite_slug ? [ $rewrite_slug ] : [];
			if ( is_string( $archive ) && '' !== $archive ) {
				$paths[] = $archive;
			} elseif ( true === $archive ) {
				$paths[] = $rewrite_slug ?: $name;
			}
			if ( in_array( $path, array_filter( array_map( [ $this, 'normalize_route_path' ], $paths ) ), true ) ) {
				$owners['post_type:' . $name] = [ 'type' => 'post_type', 'id' => $name ];
			}
		}
		foreach ( $this->public_taxonomies() as $taxonomy ) {
			$name = (string) $this->owner_value( $taxonomy, 'name' );
			$rewrite = $this->owner_value( $taxonomy, 'rewrite', [] );
			$rewrite_slug = is_array( $rewrite ) ? (string) ( $rewrite['slug'] ?? '' ) : '';
			if ( $path === $this->normalize_route_path( $rewrite_slug ) ) {
				$owners['taxonomy:' . $name] = [ 'type' => 'taxonomy', 'id' => $name ];
			}
		}
		return array_values( $owners );
	}

	protected function wordpress_page( $path ) {
		return function_exists( 'get_page_by_path' )
			? get_page_by_path( $path, defined( 'OBJECT' ) ? OBJECT : 'OBJECT', 'page' )
			: null;
	}

	protected function public_post_types() {
		return function_exists( 'get_post_types' ) ? (array) get_post_types( [ 'public' => true ], 'objects' ) : [];
	}

	protected function public_taxonomies() {
		return function_exists( 'get_taxonomies' ) ? (array) get_taxonomies( [ 'public' => true ], 'objects' ) : [];
	}

	private function owner_value( $owner, $key, $default = '' ) {
		if ( is_array( $owner ) ) {
			return $owner[ $key ] ?? $default;
		}
		return is_object( $owner ) && isset( $owner->{$key} ) ? $owner->{$key} : $default;
	}

	private function blocker( $code, array $identity, $node_id, $message ) {
		return [ 'code' => $code, 'identity' => $identity, 'node_id' => $node_id, 'message' => $message ];
	}

	private function route_blocker( $code, $path, $node_id, $message, array $owner = [] ) {
		$blocker = [ 'code' => $code, 'route_path' => $path, 'node_id' => $node_id, 'message' => $message ];
		if ( $owner ) {
			$blocker['owner'] = $owner;
		}
		return $blocker;
	}

	private function unique_blockers( array $blockers ) {
		$result = [];
		foreach ( $blockers as $blocker ) {
			$result[ hash( 'sha256', wp_json_encode( $blocker ) ) ] = $blocker;
		}
		return array_values( $result );
	}
}
