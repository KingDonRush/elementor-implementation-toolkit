<?php
/**
 * Recompiles a locked draft and proves its prepared compiler authority is fresh.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PreparedCompilationFreshness {

	private $compiler;

	public function __construct( $compiler ) {
		$this->compiler = $compiler;
	}

	public function validate( array $change_set, array $blueprint ) {
		$draft = $blueprint['draft_document'] ?? null;
		if ( ! is_array( $draft ) ) {
			return $this->error( 'eit_change_set_recompile_failed', __( 'The locked Blueprint draft cannot be recompiled safely.', 'elementor-implementation-toolkit' ), 'draft' );
		}

		try {
			$compiled = $this->compiler->compile( $draft );
		} catch ( \Throwable $error ) {
			return $this->error( 'eit_change_set_recompile_failed', __( 'The locked Blueprint draft failed during recompilation.', 'elementor-implementation-toolkit' ), 'compiler' );
		}
		if ( ! is_object( $compiled ) || ! method_exists( $compiled, 'is_valid' ) || ! $compiled->is_valid() ) {
			$errors = is_object( $compiled ) && method_exists( $compiled, 'errors' ) ? $compiled->errors() : [];
			return $this->error( 'eit_change_set_recompile_failed', __( 'The locked Blueprint draft no longer compiles against the current runtime.', 'elementor-implementation-toolkit' ), 'compiler', [ 'errors' => is_array( $errors ) ? $errors : [] ] );
		}

		$prepared = $change_set['compiled_artifacts'] ?? null;
		$current_checksum = method_exists( $compiled, 'checksum' ) ? (string) $compiled->checksum() : '';
		$prepared_checksum = is_array( $prepared ) ? (string) ( $prepared['compiler_checksum'] ?? '' ) : '';
		if ( ! $this->same_checksum( $prepared_checksum, $current_checksum ) ) {
			return $this->error(
				'eit_change_set_compiler_drift',
				__( 'The compiler output changed after this impact plan was prepared.', 'elementor-implementation-toolkit' ),
				'compiler_checksum',
				[ 'prepared_checksum' => $prepared_checksum, 'current_checksum' => $current_checksum ]
			);
		}

		$current_artifacts = method_exists( $compiled, 'artifacts' ) ? $compiled->artifacts() : null;
		$prepared_artifacts = is_array( $prepared ) ? ( $prepared['artifacts'] ?? null ) : null;
		$artifact_match = $this->compare_record_sets( $prepared_artifacts, $current_artifacts, 'id' );
		if ( true !== $artifact_match ) {
			return $this->record_drift_error( 'artifact', $prepared_artifacts, $current_artifacts, $artifact_match );
		}

		$current_bindings = method_exists( $compiled, 'bindings' ) ? $compiled->bindings() : null;
		$prepared_bindings = is_array( $prepared ) ? ( $prepared['bindings'] ?? null ) : null;
		$binding_match = $this->compare_record_sets( $prepared_bindings, $current_bindings, 'field_id' );
		if ( true !== $binding_match ) {
			return $this->record_drift_error( 'binding', $prepared_bindings, $current_bindings, $binding_match );
		}

		return true;
	}

	private function compare_record_sets( $prepared, $current, $identity_key ) {
		if ( ! is_array( $prepared ) || ! is_array( $current ) ) {
			return 'invalid_set';
		}
		$prepared_set = $this->record_set( $prepared, $identity_key );
		$current_set = $this->record_set( $current, $identity_key );
		if ( null === $prepared_set || null === $current_set ) {
			return 'invalid_identity';
		}
		return $prepared_set === $current_set ? true : 'content_mismatch';
	}

	private function record_set( array $records, $identity_key ) {
		$set = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || ! array_key_exists( $identity_key, $record ) || '' === (string) $record[ $identity_key ] ) {
				return null;
			}
			$identity = (string) $record[ $identity_key ];
			if ( array_key_exists( $identity, $set ) ) {
				return null;
			}
			$set[ $identity ] = $this->sort_recursive( $record );
		}
		ksort( $set );
		return $set;
	}

	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( [ $this, 'sort_recursive' ], $value );
		}
		ksort( $value );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->sort_recursive( $child );
		}
		return $value;
	}

	private function record_drift_error( $component, $prepared, $current, $reason ) {
		$is_artifact = 'artifact' === $component;
		return $this->error(
			$is_artifact ? 'eit_change_set_artifact_drift' : 'eit_change_set_binding_drift',
			$is_artifact
				? __( 'Compiled artifacts changed after this impact plan was prepared.', 'elementor-implementation-toolkit' )
				: __( 'Compiled field bindings changed after this impact plan was prepared.', 'elementor-implementation-toolkit' ),
			$component,
			[
				'reason' => $reason,
				'prepared_count' => is_array( $prepared ) ? count( $prepared ) : null,
				'current_count' => is_array( $current ) ? count( $current ) : null,
			]
		);
	}

	private function same_checksum( $prepared, $current ) {
		return (bool) preg_match( '/^[a-f0-9]{64}$/', $prepared )
			&& (bool) preg_match( '/^[a-f0-9]{64}$/', $current )
			&& hash_equals( $prepared, $current );
	}

	private function error( $code, $message, $component, array $data = [] ) {
		return new \WP_Error( $code, $message, array_merge( [ 'status' => 409, 'component' => $component ], $data ) );
	}
}
