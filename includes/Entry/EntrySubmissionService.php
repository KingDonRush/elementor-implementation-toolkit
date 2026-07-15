<?php
/**
 * Executes idempotent Entry mutations and isolates side effects as jobs.
 */

namespace EIT\Entry;

use EIT\Infrastructure\EntrySubmissionStore;
use EIT\Infrastructure\RunStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntrySubmissionService {

	private $resolver;
	private $policy;
	private $guard;
	private $processor;
	private $storage;
	private $submissions;
	private $actions;
	private $runs;

	public function __construct( array $dependencies = [] ) {
		$this->resolver = $dependencies['resolver'] ?? new EntrySurfaceResolver();
		$this->storage = $dependencies['storage'] ?? new EntryStorageGateway();
		$this->policy = $dependencies['policy'] ?? new EntryPolicyEngine( $this->storage );
		$this->guard = $dependencies['guard'] ?? new GuestIntakeGuard();
		$this->processor = $dependencies['processor'] ?? new EntryValueProcessor();
		$this->submissions = $dependencies['submissions'] ?? new EntrySubmissionStore();
		$this->actions = $dependencies['actions'] ?? new EntryActionDispatcher();
		$this->runs = $dependencies['runs'] ?? new RunStore();
	}

	public function submit( array $request ) {
		$contract = $this->resolver->get( $request['surface_id'] ?? '' );
		if ( ! $contract ) {
			return $this->error( 'eit_entry_surface_not_found', 'Entry Surface was not found.', 404 );
		}
		$item_id = absint( $request['item_id'] ?? 0 );
		$intent = sanitize_key( $request['intent'] ?? 'default' );
		$operation = $this->operation( $intent, $item_id );
		if ( in_array( $operation, [ 'archive', 'restore' ], true ) && ! $item_id ) {
			return $this->error( 'eit_entry_transition_item_required', 'Archive and restore require an existing item.', 400 );
		}
		$mutation_operation = $item_id ? 'update' : 'create';
		$requested_operation = 'autosave' === $operation ? $mutation_operation : $operation;
		$authorized = $this->policy->authorize( $contract, $requested_operation, $item_id );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		if ( in_array( $operation, [ 'submit_review', 'publish' ], true ) && $mutation_operation !== $operation ) {
			$authorized = $this->policy->authorize( $contract, $mutation_operation, $item_id );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}
		}
		$guest = ! is_user_logged_in();
		if ( $guest ) {
			$guarded = $this->guard->verify( $contract, $request );
			if ( is_wp_error( $guarded ) ) {
				return $guarded;
			}
		}
		$idempotency_key = trim( (string) ( $request['idempotency_key'] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/', $idempotency_key ) ) {
			return $this->error( 'eit_entry_idempotency_key_invalid', 'A valid idempotency key is required.', 400 );
		}

		$loaded = $item_id ? $this->storage->load_values( $contract, $item_id ) : [ 'values' => [] ];
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		if ( in_array( $operation, [ 'archive', 'restore' ], true ) ) {
			$processed = [ 'values' => $loaded['values'] ];
		} else {
			$processed = $this->processor->process(
				$contract,
				$request['values'] ?? [],
				[ 'partial' => 'autosave' === $operation, 'existing' => $loaded['values'] ]
			);
		}
		if ( is_wp_error( $processed ) ) {
			return $processed;
		}
		$media = $this->validate_media_ownership( $contract, $processed['values'], $guest );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		$status = $this->status( $contract, $intent, $guest, $loaded['item']['status'] ?? null );
		if ( ! $guest && 'publish' === $status && 'publish' !== $operation ) {
			$authorized = $this->policy->authorize( $contract, 'publish', $item_id );
			if ( is_wp_error( $authorized ) ) {
				return $authorized;
			}
		}
		$payload_checksum = $this->checksum( [ 'item_id' => $item_id, 'intent' => $intent, 'values' => $processed['values'], 'content' => $request['content'] ?? null ] );
		$actor_key = $this->guard->actor_key();
		$claim = $this->submissions->claim(
			[
				'blueprint_id' => $contract['blueprint_id'],
				'surface_id' => $contract['surface_id'],
				'actor_key' => $actor_key,
				'idempotency_hash' => hash( 'sha256', $contract['surface_id'] . '|' . $actor_key . '|' . $idempotency_key ),
				'payload_checksum' => $payload_checksum,
				'operation' => $operation,
			]
		);
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}
		if ( ! $claim['claimed'] ) {
			return $this->replay( $claim['record'] );
		}

		$submission = $claim['record'];
		$run = $this->runs->start( $contract['blueprint_id'], 'entry_submission', null, [ 'surface_id' => $contract['surface_id'], 'submission_id' => $submission['id'], 'operation' => $operation, 'item_id' => $item_id ?: null ] );
		if ( is_wp_error( $run ) ) {
			$this->submissions->fail( $submission['id'], $run );
			return $run;
		}
		$saved = $this->storage->save( $contract, $processed['values'], $item_id, $status, $guest ? 0 : get_current_user_id(), $request['content'] ?? null );
		if ( is_wp_error( $saved ) ) {
			$this->submissions->fail( $submission['id'], $saved );
			$this->runs->finish( $run['id'], 'failed', $saved );
			return $saved;
		}

		$event = $this->event( $operation, $item_id );
		$action_results = 'autosave' === $operation ? [] : $this->actions->dispatch(
			$contract,
			$submission['id'],
			$event,
			[
				'request_id' => $run['request_id'],
				'surface_id' => $contract['surface_id'],
				'surface_name' => $contract['name'],
				'item_id' => $saved,
				'status' => $status,
				'actor_id' => $guest ? 0 : get_current_user_id(),
				'is_guest' => $guest,
			]
		);
		$response = [ 'submission_id' => $submission['id'], 'request_id' => $run['request_id'], 'item_id' => $saved, 'status' => $status, 'event' => $event, 'actions' => $action_results, 'replayed' => false ];
		$recorded = $this->submissions->succeed( $submission['id'], $saved, $response );
		if ( is_wp_error( $recorded ) ) {
			$this->runs->finish( $run['id'], 'failed', $recorded );
			return $recorded;
		}
		$this->runs->finish( $run['id'], 'succeeded' );
		return $response;
	}

	private function replay( array $record ) {
		if ( 'succeeded' === $record['status'] ) {
			return array_merge( $record['response'], [ 'replayed' => true ] );
		}
		$message = 'processing' === $record['status'] ? 'This identical submission is still processing.' : 'This idempotency key belongs to a failed submission. Use a new key after correcting the problem.';
		return $this->error( 'eit_entry_idempotency_' . $record['status'], $message, 409 );
	}

	private function operation( $intent, $item_id ) {
		$map = [ 'submit_review' => 'submit_review', 'publish' => 'publish', 'archive' => 'archive', 'restore' => 'restore', 'autosave' => 'autosave' ];
		return $map[ $intent ] ?? ( $item_id ? 'update' : 'create' );
	}

	private function status( array $contract, $intent, $guest, $existing_status = null ) {
		if ( $guest ) {
			return $contract['guest']['moderation_status'];
		}
		$map = [ 'submit_review' => 'review', 'publish' => 'publish', 'archive' => 'archived', 'restore' => 'draft', 'autosave' => 'draft', 'save_draft' => 'draft' ];
		return $map[ $intent ] ?? ( $existing_status ?: $contract['workflow']['initial_status'] );
	}

	private function event( $operation, $item_id ) {
		$events = [ 'submit_review' => 'submitted_for_review', 'publish' => 'published', 'archive' => 'archived', 'restore' => 'restored', 'autosave' => 'autosaved' ];
		return $events[ $operation ] ?? ( $item_id ? 'updated' : 'created' );
	}

	private function checksum( $value ) {
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				ksort( $value );
			}
			$value = array_map( [ $this, 'checksum_value' ], $value );
		}
		return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private function validate_media_ownership( array $contract, array $values, $guest ) {
		foreach ( $contract['fields'] as $field ) {
			if ( ! isset( $values[ $field['id'] ] ) || ! in_array( $field['type'], [ 'image', 'gallery', 'file' ], true ) ) {
				continue;
			}
			$items = 'gallery' === $field['type'] ? (array) $values[ $field['id'] ] : [ $values[ $field['id'] ] ];
			foreach ( $items as $item ) {
				$attachment_id = absint( is_array( $item ) ? ( $item['id'] ?? 0 ) : $item );
				$pending_surface = (string) get_post_meta( $attachment_id, '_eit_entry_pending_surface', true );
				$pending_actor = (string) get_post_meta( $attachment_id, '_eit_entry_pending_actor', true );
				$allowed = $guest ? hash_equals( $contract['surface_id'], $pending_surface ) && hash_equals( $this->guard->actor_key(), $pending_actor ) : current_user_can( 'edit_post', $attachment_id );
				if ( ! $attachment_id || ! $allowed ) {
					return $this->error( 'eit_entry_media_forbidden', 'An uploaded file is outside this Entry Surface.', 403 );
				}
			}
		}
		return true;
	}

	private function checksum_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return array_map( [ $this, 'checksum_value' ], $value );
	}

	private function error( $code, $message, $status ) {
		return new \WP_Error( $code, __( $message, 'elementor-implementation-toolkit' ), [ 'status' => $status ] ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
