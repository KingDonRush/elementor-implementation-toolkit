<?php
/**
 * Validates executable Route decisions outside the kernel grammar validator.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RouteContractValidator {

	public function validate( array $nodes, array $connections = [] ) {
		$errors = [];
		$paths = [];
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
			$relative = trim( $raw, '/' );
			if ( '' === $relative || ! preg_match( '#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$#', $relative ) ) {
				$errors[] = $this->error( $path . '.path', 'route_path_invalid', 'Executable routes use literal URL path segments without query strings, fragments or raw regular expressions.', $node_id );
				continue;
			}
			$key = strtolower( $relative );
			if ( isset( $paths[ $key ] ) ) {
				$errors[] = $this->error( $path . '.path', 'duplicate_route_path', 'Executable route paths must be unique inside a Blueprint.', $node_id );
			}
			$paths[ $key ] = $node_id;
			$this->validate_presentation( $node_id, $nodes, $connections, $errors );
		}
		return $errors;
	}

	private function validate_presentation( $route_id, array $nodes, array $connections, array &$errors ) {
		$presentation_ids = [];
		foreach ( $connections as $connection ) {
			if ( 'routes' === ( $connection['type'] ?? '' ) && $route_id === ( $connection['to'] ?? '' ) ) {
				$presentation_ids[] = (string) ( $connection['from'] ?? '' );
			}
		}
		$presentation_ids = array_values( array_filter( $presentation_ids ) );
		if ( 1 !== count( $presentation_ids ) ) {
			$errors[] = $this->unrenderable_error( $route_id );
			return;
		}

		$presentation_id = $presentation_ids[0];
		$presentation = $nodes[ $presentation_id ] ?? [];
		if ( 'presentation' !== ( $presentation['type'] ?? '' ) ) {
			$errors[] = $this->unrenderable_error( $route_id );
			return;
		}
		$config = $presentation['config'] ?? [];
		$documents = array_filter(
			[
				'template' => absint( $config['template_id'] ?? 0 ),
				'document' => absint( $config['document_id'] ?? 0 ),
			]
		);
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
		$source_count = count( $documents ) + count( $supported );
		if ( $unsupported || 1 !== $source_count ) {
			$errors[] = $this->unrenderable_error( $presentation_id );
		}
	}

	private function unrenderable_error( $node_id ) {
		return $this->error(
			'nodes.' . $node_id . '.config',
			'route_presentation_unrenderable',
			'Routed Presentation must resolve exactly one executable document, Collection Surface or Entry Surface.',
			$node_id
		);
	}

	private function error( $path, $code, $message, $node_id ) {
		return [ 'path' => $path, 'code' => $code, 'message' => $message, 'node_id' => $node_id ];
	}
}
