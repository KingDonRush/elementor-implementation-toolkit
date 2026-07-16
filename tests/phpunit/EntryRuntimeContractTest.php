<?php
/**
 * Pure contracts for safe calculations, conditions and Field-ID validation.
 */

use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\CoreRegistryFactory;
use EIT\Blueprint\Uuid;
use EIT\Entry\CoreFormAction;
use EIT\Entry\EntryValueProcessor;
use EIT\Entry\EntryReferenceValidator;
use EIT\Entry\EntryMediaService;
use EIT\Entry\EntryMediaPolicy;
use EIT\Entry\EntrySubmissionService;
use EIT\Entry\PendingUploadStore;
use EIT\Entry\PendingUploadSubmissionReconciler;
use EIT\Entry\EntryActionDispatcher;
use EIT\Infrastructure\EntrySubmissionStore;
use EIT\Infrastructure\RunStore;
use EIT\Entry\ConditionEvaluator;
use EIT\Entry\SafeExpression;
use PHPUnit\Framework\TestCase;

class EntryRuntimeContractTest extends TestCase {

	public function test_autosave_preserves_existing_status_and_new_items_start_as_drafts(): void {
		$service = new EntrySubmissionService();
		$status = new ReflectionMethod( $service, 'status' );
		$contract = [ 'workflow' => [ 'initial_status' => 'publish' ], 'guest' => [ 'moderation_status' => 'review' ] ];

		self::assertSame( 'publish', $status->invoke( $service, $contract, 'autosave', false, 'publish' ) );
		self::assertSame( 'review', $status->invoke( $service, $contract, 'autosave', false, 'review' ) );
		self::assertSame( 'draft', $status->invoke( $service, $contract, 'autosave', false, null ) );
	}

	public function test_core_does_not_advertise_a_notification_action_without_an_engine(): void {
		self::assertNull( ( new CoreRegistryFactory() )->create()->form_actions()->get( 'notification' ) );
		$this->expectException( InvalidArgumentException::class );
		new CoreFormAction( 'notification' );
	}

	public function test_safe_expression_resolves_only_numeric_field_placeholders(): void {
		$price = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:price' );
		$quantity = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:quantity' );
		$expression = "{{$price}} * {{$quantity}} + 2";

		$result = ( new SafeExpression() )->evaluate( $expression, [ $price => 10, $quantity => 3 ] );
		self::assertSame( 32.0, $result );
		self::assertTrue( is_wp_error( ( new SafeExpression() )->evaluate( 'phpinfo()', [] ) ) );
		self::assertTrue( is_wp_error( ( new SafeExpression() )->evaluate( '10 / 0', [] ) ) );
	}

	public function test_required_zero_is_valid_and_calculated_values_ignore_browser_input(): void {
		$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
		$quantity = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:required-zero' );
		$total = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:calculated' );
		$fields = [
			$factory->make( $quantity, 'Quantity', 'integer', [ 'validation' => [ 'required' => true ] ] ),
			$factory->make( $total, 'Total', 'calculated', [ 'validation' => [ 'expression' => "{{$quantity}} * 2" ] ] ),
		];
		$contract = [ 'fields' => $fields, 'conditions' => [], 'calculations' => [ [ 'field_id' => $total, 'expression' => "{{$quantity}} * 2" ] ] ];

		$result = ( new EntryValueProcessor() )->process( $contract, [ $quantity => '0', $total => 999 ] );
		self::assertFalse( is_wp_error( $result ) );
		self::assertSame( 0, $result['values'][ $quantity ] );
		self::assertSame( 0.0, $result['values'][ $total ] );
	}

	public function test_hidden_condition_discards_browser_value_and_dynamic_required_is_enforced(): void {
		$factory = new FieldContractFactory( new FieldPrimitiveRegistry() );
		$source = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:condition-source' );
		$target = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:condition-target' );
		$condition = [ 'source_field_id' => $source, 'target_field_id' => $target, 'operator' => 'equals', 'value' => 'yes', 'effect' => 'show' ];
		$contract = [ 'fields' => [ $factory->make( $source, 'Toggle', 'short_text' ), $factory->make( $target, 'Details', 'short_text', [ 'validation' => [ 'required' => true ] ] ) ], 'conditions' => [ $condition ], 'calculations' => [] ];

		$hidden = ( new EntryValueProcessor() )->process( $contract, [ $source => 'no', $target => 'must not persist' ] );
		$shown = ( new EntryValueProcessor() )->process( $contract, [ $source => 'yes', $target => '' ] );
		self::assertArrayNotHasKey( $target, $hidden['values'] );
		self::assertTrue( is_wp_error( $shown ) );
		self::assertArrayHasKey( $target, $shown->get_error_data()['fields'] );
	}

	public function test_conditions_reject_empty_numeric_values_and_compare_relation_ids(): void {
		$source = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:condition-parity-source' );
		$target = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:condition-parity-target' );
		$evaluator = new ConditionEvaluator();
		$numeric = $evaluator->state(
			[ [ 'source_field_id' => $source, 'target_field_id' => $target, 'operator' => 'gte', 'value' => 0, 'effect' => 'show' ] ],
			[ $source => '' ],
			[ $source, $target ]
		);
		$relation = $evaluator->state(
			[ [ 'source_field_id' => $source, 'target_field_id' => $target, 'operator' => 'in', 'value' => [ '42' ], 'effect' => 'show' ] ],
			[ $source => [ [ 'id' => 42 ] ] ],
			[ $source, $target ]
		);

		self::assertFalse( $numeric[ $target ]['visible'] );
		self::assertTrue( $relation[ $target ]['visible'] );
	}

	public function test_relation_and_taxonomy_targets_are_resolved_before_persistence(): void {
		$taxonomy_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:taxonomy' );
		$relation_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:relation' );
		$target_entity_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:target-entity' );
		$contract = [
			'fields' => [
				[ 'id' => $taxonomy_id, 'type' => 'taxonomy', 'taxonomy' => [ 'slug' => 'specialty' ] ],
				[ 'id' => $relation_id, 'type' => 'relation', 'relation' => [ 'target_entity_id' => $target_entity_id, 'target' => [ 'strategy' => 'cpt' ] ] ],
			],
		];
		$validator = new EntryReferenceValidator(
			fn( $entry, $field, $id ) => 'specialty' === $field['taxonomy']['slug'] && '7' === (string) $id,
			fn( $entry, $field, $id ) => $target_entity_id === $field['relation']['target_entity_id'] && '42' === (string) $id
		);

		$allowed = $validator->validate( $contract, [ $taxonomy_id => [ 7 ], $relation_id => [ [ 'id' => 42 ] ] ] );
		$foreign_term = $validator->validate( $contract, [ $taxonomy_id => [ 8 ], $relation_id => [ [ 'id' => 42 ] ] ] );
		$foreign_entity = $validator->validate( $contract, [ $taxonomy_id => [ 7 ], $relation_id => [ [ 'id' => 99 ] ] ] );

		self::assertTrue( $allowed );
		self::assertSame( 'eit_entry_reference_forbidden', $foreign_term->get_error_code() );
		self::assertSame( 403, $foreign_term->get_error_data()['status'] );
		self::assertArrayHasKey( $taxonomy_id, $foreign_term->get_error_data()['fields'] );
		self::assertArrayHasKey( $relation_id, $foreign_entity->get_error_data()['fields'] );
	}

	public function test_to_one_relation_rejects_multiple_targets_before_persistence(): void {
		$field_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:to-one' );
		$contract = [
			'fields' => [
				[
					'id' => $field_id,
					'type' => 'relation',
					'relation' => [ 'cardinality' => 'many_to_one', 'target_entity_id' => Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:target' ), 'target' => [ 'strategy' => 'cpt' ] ],
				],
			],
		];
		$validator = new EntryReferenceValidator( null, fn() => true );

		$result = $validator->validate( $contract, [ $field_id => [ [ 'id' => 41 ], [ 'id' => 42 ] ] ] );

		self::assertSame( 'eit_entry_reference_forbidden', $result->get_error_code() );
		self::assertArrayHasKey( $field_id, $result->get_error_data()['fields'] );
	}

	public function test_file_accept_contract_restricts_authenticated_upload_types(): void {
		$method = new ReflectionMethod( EntryMediaService::class, 'field_accepts' );
		$service = new EntryMediaService();
		$field = [ 'type' => 'file', 'validation' => [ 'accept' => '.pdf,application/json' ] ];

		self::assertTrue( $method->invoke( $service, $field, 'application/pdf', 'pdf' ) );
		self::assertTrue( $method->invoke( $service, $field, 'application/json', 'json' ) );
		self::assertFalse( $method->invoke( $service, $field, 'image/png', 'png' ) );
	}

	public function test_pending_media_is_revalidated_against_current_guest_policy(): void {
		$policy = new EntryMediaPolicy();
		$contract = [ 'guest' => [ 'upload_max_bytes' => 1024, 'upload_mime_types' => [ 'image/png' ] ] ];
		$field = [ 'type' => 'image', 'validation' => [] ];
		$record = [ 'original_name' => 'photo.png', 'mime_type' => 'image/png', 'size_bytes' => 512 ];

		self::assertTrue( $policy->validate_pending( $contract, $field, $record ) );
		self::assertSame( 'eit_entry_pending_policy_changed', $policy->validate_pending( $contract, [ 'type' => 'file', 'validation' => [ 'accept' => 'application/pdf' ] ], $record )->get_error_code() );
		$record['size_bytes'] = 2048;
		self::assertSame( 'eit_entry_pending_policy_changed', $policy->validate_pending( $contract, $field, $record )->get_error_code() );
	}

	public function test_persisted_recovery_consumes_media_before_dispatching_actions(): void {
		$order = [];
		$pending = new class( $order ) extends PendingUploadStore {
			private $order;
			public function __construct( array &$order ) {
				$this->order = &$order;
			}
			public function consume( array $attachment_ids, $submission_id ) {
				$this->order[] = 'consume:' . $submission_id . ':' . implode( ',', $attachment_ids );
				return true;
			}
		};
		$actions = new class( $order ) extends EntryActionDispatcher {
			private $order;
			public function __construct( array &$order ) {
				$this->order = &$order;
			}
			public function dispatch( array $contract, $submission_id, $event, array $context ) {
				$this->order[] = 'dispatch';
				return [];
			}
		};
		$submissions = new class( $order ) extends EntrySubmissionStore {
			private $order;
			public function __construct( array &$order ) {
				$this->order = &$order;
			}
			public function succeed( $id, $item_id, array $response ) {
				$this->order[] = 'succeed';
				return [ 'response' => $response ];
			}
		};
		$runs = new class( $order ) extends RunStore {
			private $order;
			public function __construct( array &$order ) {
				$this->order = &$order;
			}
			public function finish( $id, $status, $error = null ) {
				$this->order[] = 'finish';
				return true;
			}
		};
		$service = new EntrySubmissionService( [ 'pending' => $pending, 'actions' => $actions, 'submissions' => $submissions, 'runs' => $runs ] );
		$recover = new ReflectionMethod( $service, 'recover_persisted' );
		$submission_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:recovery-submission' );
		$record = [
			'id' => $submission_id,
			'item_id' => 42,
			'response' => [
				'item_id' => 42,
				'event' => 'created',
				'_recovery' => [ 'pending_attachment_ids' => [ 7 ], 'run_id' => 'run-1' ],
			],
		];
		$result = $recover->invoke( $service, $record, [ 'surface_id' => 'surface', 'name' => 'Surface' ] );

		self::assertFalse( is_wp_error( $result ) );
		self::assertSame( [ 'consume:' . $submission_id . ':7', 'dispatch', 'succeed', 'finish' ], $order );
	}

	public function test_expiry_reconciles_durably_persisted_media_before_cleanup(): void {
		$submission_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:sweep-recovery' );
		$submissions = new class( $submission_id ) extends EntrySubmissionStore {
			private $submission_id;

			public function __construct( $submission_id ) {
				$this->submission_id = $submission_id;
			}

			public function get( $id ) {
				return hash_equals( $this->submission_id, (string) $id )
					? [ 'status' => 'persisted', 'response' => [ '_recovery' => [ 'pending_attachment_ids' => [ 71 ] ] ] ]
					: null;
			}
		};
		$consumed = [];
		$record = [ 'status' => 'promoted', 'submission_id' => $submission_id, 'attachment_id' => 71 ];

		$result = ( new PendingUploadSubmissionReconciler( $submissions ) )->reconcile(
			$record,
			static function ( $attachments, $claim ) use ( &$consumed ) {
				$consumed = [ $attachments, $claim ];
				return true;
			}
		);

		self::assertSame( 'consumed', $result['status'] );
		self::assertSame( [ [ 71 ], $submission_id ], $consumed );
	}

	public function test_expiry_never_adopts_media_without_matching_persisted_recovery(): void {
		$submission_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'entry-test:sweep-mismatch' );
		$submissions = new class() extends EntrySubmissionStore {
			public function get( $id ) {
				return [ 'status' => 'persisted', 'response' => [ '_recovery' => [ 'pending_attachment_ids' => [ 99 ] ] ] ];
			}
		};
		$called = false;

		$result = ( new PendingUploadSubmissionReconciler( $submissions ) )->reconcile(
			[ 'status' => 'promoted', 'submission_id' => $submission_id, 'attachment_id' => 71 ],
			static function () use ( &$called ) {
				$called = true;
				return true;
			}
		);

		self::assertSame( 'eit_entry_pending_recovery_mismatch', $result->get_error_code() );
		self::assertFalse( $called );
	}
}
