<?php
/**
 * Read-only compatibility adapter for shadow-imported DOM collections.
 */

namespace EIT\Blueprint;

use EIT\Contracts\StorageAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReadOnlyLegacyStorageAdapter implements StorageAdapterInterface {

	public function get_id() {
		return 'legacy_dom';
	}

	public function get_version() {
		return '1.0.0';
	}

	public function get_capabilities() {
		return [ 'read_only', 'legacy_dom', 'bounded_200_items' ];
	}

	public function compile( array $entity, array $fields, array $context = [] ) {
		return [
			'strategy' => 'adapter',
			'definition' => [
				'slug' => sanitize_key( $entity['config']['slug'] ?? $entity['name'] ),
				'read_only' => true,
				'legacy' => $entity['config']['legacy'] ?? [],
			],
		];
	}

	public function prepare( array $artifact, array $context = [] ) {
		return true;
	}

	public function health_check() {
		return [ 'ok' => true, 'degraded' => true, 'message' => 'Legacy DOM remains bounded and read-only.' ];
	}
}
