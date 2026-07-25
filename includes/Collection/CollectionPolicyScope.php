<?php
/**
 * Derives server-owned query constraints from a compiled Collection Policy.
 */

namespace EIT\Collection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CollectionPolicyScope {

	private $assigned_resolver;

	public function __construct( ?callable $assigned_resolver = null ) {
		$this->assigned_resolver = $assigned_resolver;
	}

	public function apply( array $contract, array $request, array $context = [] ) {
		if ( ! empty( $request['_policy_resolved'] ) ) {
			return $request;
		}
		$request['_policy_resolved'] = true;
		$policy = $contract['policy'] ?? [];
		$user_id = absint( $context['user_id'] ?? 0 );
		$ownership = $policy['ownership'] ?? 'any';
		$object_scope = $policy['object_scope'] ?? 'entity';

		if ( 'own' === $ownership ) {
			if ( ! $user_id ) {
				$request['_policy_deny'] = true;
			} else {
				$request['_policy_author_id'] = $user_id;
			}
		}
		if ( 'assigned' !== $object_scope ) {
			return $request;
		}
		if ( ! $user_id ) {
			$request['_policy_deny'] = true;
			return $request;
		}

		$ids = $this->assigned_resolver
			? call_user_func( $this->assigned_resolver, $contract, $user_id )
			: apply_filters( 'eit_collection_object_scope_ids', [], $contract, $user_id );
		$ids = is_array( $ids ) ? $this->identities( $ids ) : [];
		if ( ! $ids ) {
			$request['_policy_deny'] = true;
		} else {
			$request['_policy_include'] = $ids;
		}
		return $request;
	}

	private function identities( array $ids ) {
		$result = [];
		foreach ( array_slice( $ids, 0, 10000 ) as $id ) {
			$id = trim( (string) $id );
			if ( '' !== $id && preg_match( '/^[a-zA-Z0-9:_-]{1,191}$/', $id ) ) {
				$result[] = $id;
			}
		}
		$result = array_values( array_unique( $result ) );
		sort( $result, SORT_STRING );
		return $result;
	}
}
