<?php
/**
 * Removes storage and action internals from browser-facing Entry contracts.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryContractPresenter {

	private $policy;
	private $guard;

	public function __construct( EntryPolicyEngine $policy = null, GuestIntakeGuard $guard = null ) {
		$this->policy = $policy ?: new EntryPolicyEngine();
		$this->guard = $guard ?: new GuestIntakeGuard();
	}

	public function present( array $contract, array $loaded = null ) {
		$item_id = (int) ( $loaded['item']['id'] ?? 0 );
		$fields = [];
		foreach ( $contract['fields'] ?? [] as $field ) {
			unset( $field['storage'], $field['indexing'], $field['capabilities'], $field['exposure']['roles'] );
			$fields[] = $field;
		}
		$permissions = [];
		foreach ( $contract['workflow']['operations'] ?? [] as $operation ) {
			$permissions[ $operation ] = ! is_wp_error( $this->policy->authorize( $contract, $operation, $item_id ) );
		}
		return [
			'surface_id' => $contract['surface_id'],
			'name' => $contract['name'],
			'version_id' => $contract['version_id'],
			'entity' => [ 'mode' => $contract['entity']['mode'] ?? 'structured', 'name' => $contract['entity']['definition']['singular'] ?? '' ],
			'fields' => $fields,
			'title_field_id' => $contract['title_field_id'],
			'groups' => $contract['groups'],
			'steps' => $contract['steps'],
			'conditions' => $contract['conditions'],
			'workflow' => $contract['workflow'],
			'autosave' => $contract['autosave'],
			'guest_enabled' => ! empty( $contract['guest']['enabled'] ),
			'authenticated' => is_user_logged_in(),
			'form_token' => ! is_user_logged_in() ? $this->guard->issue_token( $contract['surface_id'] ) : '',
			'permissions' => $permissions,
			'item' => $loaded['item'] ?? null,
			'values' => $this->media_projection( $loaded['values'] ?? [], $contract['fields'] ?? [] ),
			'content' => $loaded['content'] ?? '',
		];
	}

	private function media_projection( array $values, array $fields ) {
		foreach ( $fields as $field ) {
			$id = $field['id'];
			if ( ! isset( $values[ $id ] ) || ! in_array( $field['type'], [ 'image', 'file', 'gallery' ], true ) ) {
				continue;
			}
			$items = 'gallery' === $field['type'] ? (array) $values[ $id ] : [ $values[ $id ] ];
			$projected = [];
			foreach ( $items as $item ) {
				$attachment_id = absint( is_array( $item ) ? ( $item['id'] ?? 0 ) : $item );
				if ( $attachment_id ) {
					$projected[] = [ 'id' => $attachment_id, 'url' => wp_get_attachment_url( $attachment_id ), 'name' => get_the_title( $attachment_id ) ];
				}
			}
			$values[ $id ] = 'gallery' === $field['type'] ? $projected : ( $projected[0] ?? null );
		}
		return $values;
	}
}
