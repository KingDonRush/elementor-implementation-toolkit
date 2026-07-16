<?php
/**
 * Server-side capability, ownership and object-scope authorization.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryPolicyEngine {

	private $storage;

	public function __construct( EntryStorageGateway $storage = null ) {
		$this->storage = $storage ?: new EntryStorageGateway();
	}

	public function authorize( array $contract, $operation, $item_id = 0 ) {
		$operation = sanitize_key( $operation );
		$item_id = absint( $item_id );
		if ( ! in_array( $operation, $contract['workflow']['operations'] ?? [], true ) && ! in_array( $operation, [ 'autosave' ], true ) ) {
			return $this->error( 'eit_entry_operation_forbidden', 'This Entry Surface does not permit the requested operation.' );
		}
		if ( ! is_user_logged_in() ) {
			return 'create' === $operation && ! $item_id && ! empty( $contract['guest']['enabled'] )
				? true
				: $this->error( 'eit_entry_authentication_required', 'Sign in to perform this Entry operation.' );
		}

		$policy = $contract['policy'] ?? [];
		$capability_key = in_array( $operation, [ 'publish' ], true ) ? 'publish' : ( in_array( $operation, [ 'archive', 'restore' ], true ) ? 'archive' : ( $item_id ? 'update' : 'create' ) );
		$capability = $policy['capabilities'][ $capability_key ] ?? 'edit_posts';
		if ( ! current_user_can( $capability ) ) {
			return $this->error( 'eit_entry_capability_forbidden', 'Your account cannot perform this Entry operation.' );
		}
		if ( 'woocommerce' === ( $contract['entity']['adapter']['id'] ?? '' ) ) {
			$product_capability = 'publish' === $operation ? 'publish_products' : 'edit_products';
			if ( ! current_user_can( $product_capability ) ) {
				return $this->error( 'eit_entry_adapter_capability_forbidden', 'Your account cannot modify WooCommerce products.' );
			}
		}
		if ( ! $item_id ) {
			return true;
		}

		$item = $this->storage->item_context( $contract, $item_id );
		if ( ! $item ) {
			return $this->error( 'eit_entry_item_forbidden', 'The requested item is outside this Entry Surface.' );
		}
		$is_post_object = 'cpt' === ( $contract['entity']['strategy'] ?? '' ) || 'woocommerce' === ( $contract['entity']['adapter']['id'] ?? '' );
		if ( $is_post_object && ! current_user_can( 'edit_post', $item_id ) ) {
			return $this->error( 'eit_entry_item_forbidden', 'Your account cannot edit this item.' );
		}
		if ( 'own' === ( $policy['ownership'] ?? 'own' ) && get_current_user_id() !== (int) $item['author_id'] && ! current_user_can( 'manage_options' ) ) {
			return $this->error( 'eit_entry_ownership_forbidden', 'This Entry Surface is limited to content owned by your account.' );
		}
		if ( 'assigned' === ( $policy['object_scope'] ?? 'entity' ) && ! apply_filters( 'eit_entry_object_scope_allowed', false, $contract, $item, get_current_user_id() ) ) {
			return $this->error( 'eit_entry_scope_forbidden', 'This item is outside your assigned object scope.' );
		}
		return true;
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, __( $message, 'elementor-implementation-toolkit' ), [ 'status' => 403 ] ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
