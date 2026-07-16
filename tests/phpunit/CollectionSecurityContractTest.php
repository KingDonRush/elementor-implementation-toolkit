<?php
/**
 * Pure security contracts for Collection exposure and server-owned scoping.
 */

use EIT\Collection\CollectionPolicyScope;
use EIT\Collection\CollectionProjector;
use PHPUnit\Framework\TestCase;

class CollectionSecurityContractTest extends TestCase {

	public function test_authenticated_projection_still_excludes_non_public_fields(): void {
		$contract = [
			'access' => 'authenticated',
			'projection_field_ids' => [ 'public', 'private' ],
			'fields' => [
				[ 'id' => 'public', 'exposure' => [ 'public' => true ] ],
				[ 'id' => 'private', 'exposure' => [ 'public' => false, 'roles' => [ 'administrator' ] ] ],
			],
		];

		self::assertSame( [ 'public' ], array_keys( ( new CollectionProjector() )->fields( $contract ) ) );
	}

	public function test_policy_scope_uses_server_actor_and_assigned_ids(): void {
		$scope = new CollectionPolicyScope( fn( $contract, $user_id ) => 17 === $user_id ? [ 8, 9, 'bad id' ] : [] );
		$contract = [ 'policy' => [ 'ownership' => 'own', 'object_scope' => 'assigned' ] ];

		$anonymous = $scope->apply( $contract, [], [ 'user_id' => 0 ] );
		$authorized = $scope->apply( $contract, [], [ 'user_id' => 17 ] );

		self::assertTrue( $anonymous['_policy_deny'] );
		self::assertSame( 17, $authorized['_policy_author_id'] );
		self::assertSame( [ '8', '9' ], $authorized['_policy_include'] );
		self::assertArrayNotHasKey( '_policy_deny', $authorized );
	}
}
