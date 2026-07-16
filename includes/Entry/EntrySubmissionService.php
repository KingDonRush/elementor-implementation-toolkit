<?php
/**
 * Executes idempotent Entry mutations and isolates side effects as jobs.
 */

namespace EIT\Entry;

use EIT\Infrastructure\EntrySubmissionStore;
use EIT\Infrastructure\RunStore;
use EIT\Infrastructure\Transaction;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntrySubmissionService {

	private $resolver;
	private $policy;
	private $guard;
	private $processor;
	private $references;
	private $storage;
	private $submissions;
	private $actions;
	private $runs;
	private $transaction;
	private $pending;

	public function __construct( array $dependencies = [] ) {
		$this->resolver = $dependencies['resolver'] ?? new EntrySurfaceResolver();
		$this->storage = $dependencies['storage'] ?? new EntryStorageGateway();
		$this->policy = $dependencies['policy'] ?? new EntryPolicyEngine( $this->storage );
		$this->guard = $dependencies['guard'] ?? new GuestIntakeGuard();
		$this->processor = $dependencies['processor'] ?? new EntryValueProcessor();
		$this->references = $dependencies['references'] ?? new EntryReferenceValidator();
		$this->submissions = $dependencies['submissions'] ?? new EntrySubmissionStore();
		$this->actions = $dependencies['actions'] ?? new EntryActionDispatcher();
		$this->runs = $dependencies['runs'] ?? new RunStore();
		$this->transaction = $dependencies['transaction'] ?? new Transaction();
		$this->pending = $dependencies['pending'] ?? new PendingUploadStore();
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
		$references = $this->references->validate( $contract, $processed['values'], $guest, $item_id );
		if ( is_wp_error( $references ) ) {
			return $references;
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
			return 'persisted' === $claim['record']['status']
				? $this->recover_persisted( $claim['record'], $contract )
				: $this->replay( $claim['record'] );
		}

		$submission = $claim['record'];
		$media = $this->claim_media_ownership( $contract, $processed['values'], $guest, $submission['id'] );
		if ( is_wp_error( $media ) ) {
			$this->submissions->fail( $submission['id'], $media );
			return $media;
		}
		$run = $this->runs->start( $contract['blueprint_id'], 'entry_submission', null, [ 'surface_id' => $contract['surface_id'], 'submission_id' => $submission['id'], 'operation' => $operation, 'item_id' => $item_id ?: null ] );
		if ( is_wp_error( $run ) ) {
			$this->submissions->fail( $submission['id'], $run );
			return $run;
		}
		$materialized = $this->materialize_media( $contract, $processed['values'], $submission['id'] );
		if ( is_wp_error( $materialized ) ) {
			$this->submissions->fail( $submission['id'], $materialized );
			$this->runs->finish( $run['id'], 'failed', $materialized );
			return $materialized;
		}
		$processed['values'] = $materialized['values'];
		$event = $this->event( $operation, $item_id );
		$persisted = $this->transaction->run(
			function () use ( $contract, $processed, $item_id, $status, $guest, $request, $submission, $run, $event, $materialized ) {
				$saved = $this->storage->save( $contract, $processed['values'], $item_id, $status, $guest ? 0 : get_current_user_id(), $request['content'] ?? null );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				$response = [
					'submission_id' => $submission['id'],
					'request_id' => $run['request_id'],
					'item_id' => $saved,
					'status' => $status,
					'event' => $event,
					'_recovery' => [
						'run_id' => $run['id'],
						'actor_id' => $guest ? 0 : get_current_user_id(),
						'is_guest' => $guest,
						'pending_attachment_ids' => $materialized['attachment_ids'],
					],
				];
				$recorded = $this->submissions->persist( $submission['id'], $saved, $response );
				return is_wp_error( $recorded ) ? $recorded : $recorded['response'];
			}
		);
		if ( is_wp_error( $persisted ) ) {
			$this->submissions->fail( $submission['id'], $persisted );
			$this->runs->finish( $run['id'], 'failed', $persisted );
			return $persisted;
		}
		$consumed = $this->pending->consume( $materialized['attachment_ids'], $submission['id'] );
		if ( is_wp_error( $consumed ) ) {
			return $consumed;
		}

		$saved = $persisted['item_id'];
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
		$response = array_merge( $persisted, [ 'actions' => $action_results, 'replayed' => false ] );
		unset( $response['_recovery'] );
		$recorded = $this->submissions->succeed( $submission['id'], $saved, $response );
		if ( is_wp_error( $recorded ) ) {
			$this->runs->finish( $run['id'], 'failed', $recorded );
			return $recorded;
		}
		$this->runs->finish( $run['id'], 'succeeded' );
		return $response;
	}

	private function recover_persisted( array $record, array $contract ) {
		$response = is_array( $record['response'] ?? null ) ? $record['response'] : [];
		$recovery = is_array( $response['_recovery'] ?? null ) ? $response['_recovery'] : [];
		$consumed = $this->pending->consume( (array) ( $recovery['pending_attachment_ids'] ?? [] ), $record['id'] );
		if ( is_wp_error( $consumed ) ) {
			return $consumed;
		}
		$event = sanitize_key( $response['event'] ?? '' );
		$actions = 'autosaved' === $event ? [] : $this->actions->dispatch(
			$contract,
			$record['id'],
			$event,
			[
				'request_id' => $response['request_id'] ?? '',
				'surface_id' => $contract['surface_id'],
				'surface_name' => $contract['name'],
				'item_id' => $response['item_id'] ?? $record['item_id'],
				'status' => $response['status'] ?? '',
				'actor_id' => absint( $recovery['actor_id'] ?? 0 ),
				'is_guest' => ! empty( $recovery['is_guest'] ),
			]
		);
		$response['actions'] = $actions;
		$response['replayed'] = true;
		unset( $response['_recovery'] );
		$finished = $this->submissions->succeed( $record['id'], $response['item_id'] ?? $record['item_id'], $response );
		if ( is_wp_error( $finished ) ) {
			return $finished;
		}
		if ( ! empty( $recovery['run_id'] ) ) {
			$this->runs->finish( $recovery['run_id'], 'succeeded' );
		}
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
		if ( 'autosave' === $intent ) {
			return $existing_status ?: 'draft';
		}
		$map = [ 'submit_review' => 'review', 'publish' => 'publish', 'archive' => 'archived', 'restore' => 'draft', 'save_draft' => 'draft' ];
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

	private function claim_media_ownership( array $contract, array $values, $guest, $submission_id ) {
		foreach ( $contract['fields'] as $field ) {
			if ( ! isset( $values[ $field['id'] ] ) || ! in_array( $field['type'], [ 'image', 'gallery', 'file' ], true ) ) {
				continue;
			}
			$items = 'gallery' === $field['type'] ? (array) $values[ $field['id'] ] : [ $values[ $field['id'] ] ];
			foreach ( $items as $item ) {
				$pending_token = is_array( $item ) ? (string) ( $item['pending_token'] ?? '' ) : '';
				if ( '' !== $pending_token ) {
					$allowed = $this->pending->claim(
						$pending_token,
						$this->pending_identity( $contract, $field['id'], $submission_id )
					);
					if ( is_wp_error( $allowed ) ) {
						return $allowed;
					}
					continue;
				}
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

	private function materialize_media( array $contract, array $values, $submission_id ) {
		$attachment_ids = [];
		foreach ( $contract['fields'] as $field ) {
			$field_id = $field['id'];
			if ( ! isset( $values[ $field_id ] ) || ! in_array( $field['type'], [ 'image', 'gallery', 'file' ], true ) ) {
				continue;
			}
			$multiple = 'gallery' === $field['type'];
			$items = $multiple ? (array) $values[ $field_id ] : [ $values[ $field_id ] ];
			foreach ( $items as &$item ) {
				$token = is_array( $item ) ? (string) ( $item['pending_token'] ?? '' ) : '';
				if ( '' === $token ) {
					continue;
				}
				$item = $this->pending->promote(
					$token,
					$this->pending_identity( $contract, $field_id, $submission_id )
				);
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				$attachment_ids[] = absint( $item['id'] ?? 0 );
			}
			unset( $item );
			$values[ $field_id ] = $multiple ? $items : ( $items[0] ?? null );
		}
		return [ 'values' => $values, 'attachment_ids' => array_values( array_filter( $attachment_ids ) ) ];
	}

	private function pending_identity( array $contract, $field_id, $submission_id = '' ) {
		$identity = [
			'blueprint_id' => $contract['blueprint_id'],
			'version_id' => $contract['version_id'],
			'contract_checksum' => $contract['artifact_checksum'],
			'surface_id' => $contract['surface_id'],
			'field_id' => $field_id,
			'actor_key' => $this->guard->actor_key(),
		];
		if ( $submission_id ) {
			$identity['submission_id'] = $submission_id;
		}
		return $identity;
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
