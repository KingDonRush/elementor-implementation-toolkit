<?php
/**
 * Protects Blueprint-managed CPT writes made through supported WordPress APIs.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WordPressMutationGuard {

	private $guard;
	private $definitions;

	public function __construct( ?StorageMutationGuard $guard = null, ?callable $definitions = null ) {
		$this->guard = $guard ?: StorageMutationGuard::shared();
		$this->definitions = $definitions ?: [ RuntimeDefinitionProvider::class, 'cpt_definitions_result' ];
	}

	public function init_hooks() {
		add_filter( 'wp_insert_post_empty_content', [ $this, 'guard_post_write' ], PHP_INT_MAX, 2 );
		foreach ( [ 'add', 'update' ] as $operation ) {
			add_filter( $operation . '_post_metadata', [ $this, 'guard_meta_write' ], PHP_INT_MAX, 5 );
		}
		add_filter( 'delete_post_metadata', [ $this, 'guard_meta_delete' ], PHP_INT_MAX, 5 );
		add_filter( 'update_post_metadata_by_mid', [ $this, 'guard_meta_write_by_mid' ], PHP_INT_MAX, 4 );
		add_filter( 'delete_post_metadata_by_mid', [ $this, 'guard_meta_write_by_mid' ], PHP_INT_MAX, 2 );
		add_filter( 'pre_delete_post', [ $this, 'guard_post_delete' ], PHP_INT_MAX, 2 );
		add_filter( 'pre_trash_post', [ $this, 'guard_post_status_change' ], PHP_INT_MAX, 3 );
		add_filter( 'pre_untrash_post', [ $this, 'guard_post_status_change' ], PHP_INT_MAX, 3 );
		add_action( 'shutdown', [ $this->guard, 'release_all' ], PHP_INT_MAX );
	}

	public function guard_post_write( $maybe_empty, array $postarr ) {
		if ( $maybe_empty ) {
			return $maybe_empty;
		}
		$post_type = sanitize_key( $postarr['post_type'] ?? 'post' );
		return $this->blocked( $post_type ) ? true : $maybe_empty;
	}

	public function guard_meta_write( $check, $object_id, $meta_key = '', $meta_value = null, $previous_value = null ) {
		if ( null !== $check ) {
			return $check;
		}
		return $this->guard_meta_post_type( $check, $this->post_type_for_object( $object_id ) );
	}

	public function guard_meta_delete( $check, $object_id, $meta_key = '', $meta_value = null, $delete_all = false ) {
		if ( null !== $check ) {
			return $check;
		}
		return $delete_all
			? $this->guard_all_managed_post_types( $check )
			: $this->guard_meta_post_type( $check, $this->post_type_for_object( $object_id ) );
	}

	public function guard_meta_write_by_mid( $check, $meta_id, $meta_value = null, $meta_key = false ) {
		if ( null !== $check ) {
			return $check;
		}
		return $this->guard_meta_post_type( $check, $this->post_type_for_meta_id( $meta_id ) );
	}

	public function guard_post_delete( $delete, $post ) {
		if ( null !== $delete ) {
			return $delete;
		}
		$post_type = sanitize_key( is_object( $post ) ? ( $post->post_type ?? '' ) : '' );
		return $this->blocked( $post_type ) ? false : $delete;
	}

	public function guard_post_status_change( $status, $post, $previous_status = '' ) {
		if ( null !== $status ) {
			return $status;
		}
		$post_type = sanitize_key( is_object( $post ) ? ( $post->post_type ?? '' ) : '' );
		return $this->blocked( $post_type ) ? false : $status;
	}

	protected function post_type_for_object( $object_id ) {
		return sanitize_key( get_post_type( absint( $object_id ) ) );
	}

	protected function post_type_for_meta_id( $meta_id ) {
		$meta = get_metadata_by_mid( 'post', absint( $meta_id ) );
		return is_object( $meta ) ? $this->post_type_for_object( $meta->post_id ?? 0 ) : '';
	}

	private function guard_meta_post_type( $check, $post_type ) {
		return $this->blocked( $post_type ) ? false : $check;
	}

	private function guard_all_managed_post_types( $check ) {
		$definitions = $this->active_definitions();
		if ( is_wp_error( $definitions ) ) {
			$this->authority_unavailable( $definitions );
			return false;
		}
		foreach ( array_keys( $definitions ) as $post_type ) {
			$post_type = sanitize_key( $post_type );
			if ( '' !== $post_type && is_wp_error( $this->enter( $post_type ) ) ) {
				return false;
			}
		}
		return $check;
	}

	private function is_managed( $post_type ) {
		$post_type = sanitize_key( $post_type );
		$definitions = $this->active_definitions();
		return is_wp_error( $definitions )
			? $definitions
			: '' !== $post_type && array_key_exists( $post_type, $definitions );
	}

	private function active_definitions() {
		return call_user_func( $this->definitions );
	}

	private function blocked( $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return false;
		}
		$managed = $this->is_managed( $post_type );
		if ( is_wp_error( $managed ) ) {
			$this->authority_unavailable( $managed );
			return is_wp_error( $this->enter( $post_type ) );
		}
		return $managed && is_wp_error( $this->enter( $post_type ) );
	}

	private function authority_unavailable( \WP_Error $error ) {
		do_action( 'eit_runtime_authority_unavailable', $error->get_error_code() );
	}

	private function enter( $post_type ) {
		$result = $this->guard->enter( 'cpt', $post_type );
		if ( is_wp_error( $result ) ) {
			do_action( 'eit_migration_write_blocked', 'cpt', $post_type, $result->get_error_code() );
		}
		return $result;
	}
}
