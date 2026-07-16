<?php
/**
 * Proves that public SDK primitives and actions govern their runtime paths.
 */

use EIT\Blueprint\BlueprintValidator;
use EIT\Blueprint\Compiler;
use EIT\Blueprint\CoreRegistryFactory;
use EIT\Blueprint\FieldContractFactory;
use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Blueprint\Uuid;
use EIT\Contracts\FieldPrimitiveInterface;
use EIT\Contracts\FormActionInterface;
use EIT\Entry\EntryActionDispatcher;
use EIT\Entry\EntryValueProcessor;
use EIT\Infrastructure\EntryActionStore;
use EIT\Infrastructure\PayloadRedactor;
use EIT\Infrastructure\RunStore;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = [], $wp_error = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['eit_test_scheduled_entry_actions'][] = compact( 'timestamp', 'hook', 'args', 'wp_error' );
		return true;
	}
}

class SdkExtensionRuntimeContractTest extends TestCase {

	public function test_custom_primitive_normalizes_and_maps_validation_errors_to_field_id(): void {
		$registry = new FieldPrimitiveRegistry( false );
		$primitive = new class() implements FieldPrimitiveInterface {
			public $normalizations = 0;
			public $validations = 0;

			public function get_id() {
				return 'normalized_code';
			}

			public function get_version() {
				return '1.2.0';
			}

			public function get_definition() {
				return [
					'shape' => 'scalar',
					'components' => [ 'text' ],
					'elementor' => [ 'text' ],
					'capabilities' => [ 'search' => false, 'filter' => false, 'sort' => false ],
				];
			}

			public function normalize( $value, array $contract = [] ) {
				++$this->normalizations;
				$value = strtoupper( trim( (string) $value ) );
				return 'NORMALIZE-ERROR' === $value ? new WP_Error( 'custom_normalization_failed', 'This custom code cannot be normalized.' ) : $value;
			}

			public function validate( $value, array $contract = [] ) {
				++$this->validations;
				return 'BLOCKED' === $value ? new WP_Error( 'custom_code_blocked', 'This custom code is blocked.' ) : [];
			}

			public function health_check() {
				return [ 'ok' => true, 'version' => $this->get_version() ];
			}
		};
		$registry->register( $primitive );
		$field_id = Uuid::v5( Uuid::LEGACY_NAMESPACE, 'sdk:custom-primitive' );
		$field = ( new FieldContractFactory( $registry ) )->make( $field_id, 'Custom code', 'normalized_code' );
		$processor = new EntryValueProcessor( null, null, null, $registry );

		$allowed = $processor->process( [ 'fields' => [ $field ] ], [ $field_id => ' <b>ab</b> ' ] );
		$blocked = $processor->process( [ 'fields' => [ $field ] ], [ $field_id => 'blocked' ] );
		$normalization_error = $processor->process( [ 'fields' => [ $field ] ], [ $field_id => 'normalize-error' ] );

		self::assertSame( 'AB', $allowed['values'][ $field_id ] );
		self::assertSame( [ 'id' => 'normalized_code', 'version' => '1.2.0' ], $field['primitive'] );
		self::assertSame( 3, $primitive->normalizations );
		self::assertSame( 2, $primitive->validations );
		self::assertSame( 'eit_entry_validation_failed', $blocked->get_error_code() );
		self::assertSame( 'This custom code is blocked.', $blocked->get_error_data()['fields'][ $field_id ] );
		self::assertSame( 'This custom code cannot be normalized.', $normalization_error->get_error_data()['fields'][ $field_id ] );

		$field['primitive']['version'] = '9.9.9';
		$version_mismatch = $processor->process( [ 'fields' => [ $field ] ], [ $field_id => 'allowed' ] );
		self::assertSame( 'The field type version differs from the published contract.', $version_mismatch->get_error_data()['fields'][ $field_id ] );
	}

	public function test_registered_action_compiles_metadata_and_executes_only_matching_runtime_version(): void {
		$registries = ( new CoreRegistryFactory() )->create();
		$action = $this->action( 'crm.sync', '2.1.0', [ 'retryable', 'durable_job', 'redacted_diagnostics' ] );
		$registries->form_actions()->register( $action );
		$result = ( new Compiler( null, null, null, $registries ) )->compile( $this->blueprint( 'crm.sync' ) );
		$entry = current( array_filter( $result->artifacts(), fn( $artifact ) => 'entry_contract' === $artifact['kind'] ) );
		$compiled_action = $entry['payload']['actions'][0];

		self::assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		self::assertSame( '2.1.0', $compiled_action['extension']['version'] );
		self::assertSame( [ 'durable_job', 'redacted_diagnostics', 'retryable' ], $compiled_action['extension']['capabilities'] );

		$store = new SdkExtensionActionStore( $this->job( $compiled_action ) );
		$dispatcher = new EntryActionDispatcher( $store, new SdkExtensionRunStore(), new PayloadRedactor(), $registries );
		$executed = $dispatcher->process( 'job-sdk-action' );

		self::assertSame( 'succeeded', $executed['status'] );
		self::assertSame( 1, $action->executions );

		$compiled_action['extension']['version'] = '9.9.9';
		$mismatch_store = new SdkExtensionActionStore( $this->job( $compiled_action ) );
		$mismatch = ( new EntryActionDispatcher( $mismatch_store, new SdkExtensionRunStore(), new PayloadRedactor(), $registries ) )->process( 'job-sdk-action' );

		self::assertSame( 'retryable_failure', $mismatch['status'] );
		self::assertSame( 'eit_entry_action_version_mismatch', $mismatch_store->finished->get_error_code() );
		self::assertSame( 1, $action->executions );
	}

	public function test_action_completion_failure_fails_run_without_scheduling_retry(): void {
		$GLOBALS['eit_test_scheduled_entry_actions'] = [];
		$registries = ( new CoreRegistryFactory() )->create();
		$action = $this->action( 'crm.finish', '1.0.0', [ 'retryable', 'durable_job', 'redacted_diagnostics' ] );
		$registries->form_actions()->register( $action );
		$action_contract = [
			'id' => $this->id( 'action:crm.finish' ),
			'type' => 'crm.finish',
			'events' => [ 'created' ],
			'config' => [],
			'extension' => [
				'id' => 'crm.finish',
				'version' => '1.0.0',
				'capabilities' => [ 'durable_job', 'redacted_diagnostics', 'retryable' ],
			],
		];
		$store = new SdkCompletionFailureActionStore( $this->job( $action_contract ) );
		$runs = new SdkRecordingRunStore();

		$result = ( new EntryActionDispatcher( $store, $runs, new PayloadRedactor(), $registries ) )->process( 'job-sdk-action' );

		self::assertSame( 'completion_unknown', $result['status'] );
		self::assertSame( 'failed', $runs->finished_status );
		self::assertSame( 'eit_test_action_finish_failed', $runs->finished_error->get_error_code() );
		self::assertSame( [], $GLOBALS['eit_test_scheduled_entry_actions'] );
		self::assertSame( 1, $action->executions );
	}

	public function test_queued_operational_context_preserves_extension_payload_and_redacts_diagnostics(): void {
		$payload = [
			'external_id' => 'crm-record-42',
			'attributes' => [ 'private_note' => 'Operational payload must reach the extension.' ],
		];
		$action = [
			'id' => $this->id( 'action:crm.payload' ),
			'type' => 'crm.payload',
			'events' => [ 'created' ],
			'config' => [ 'payload' => $payload ],
		];
		$execution = [
			'request_id' => 'request-payload-42',
			'payload' => [ 'email' => 'private@example.test' ],
		];
		$store = new SdkCapturingEnqueueActionStore();
		$contract = [
			'blueprint_id' => $this->id( 'blueprint:crm.payload' ),
			'surface_id' => $this->id( 'surface:crm.payload' ),
			'actions' => [ $action ],
		];

		$result = ( new EntryActionDispatcher( $store, new SdkExtensionRunStore(), new PayloadRedactor(), ( new CoreRegistryFactory() )->create() ) )
			->dispatch( $contract, $this->id( 'submission:crm.payload' ), 'created', $execution );
		$queued = $store->enqueued;

		self::assertSame( 'succeeded', $result[0]['status'] );
		self::assertSame( $payload, $queued['context']['action']['config']['payload'] );
		self::assertSame( $execution['payload'], $queued['context']['execution']['payload'] );
		self::assertTrue( $queued['context']['diagnostic']['action']['config']['payload']['redacted'] );
		self::assertTrue( $queued['context']['diagnostic']['execution']['payload']['redacted'] );
		self::assertSame(
			hash( 'sha256', wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			$queued['context']['diagnostic']['action']['config']['payload']['sha256']
		);
		self::assertArrayNotHasKey( 'external_id', $queued['context']['diagnostic']['action']['config']['payload'] );
	}

	public function test_unregistered_or_non_durable_actions_are_rejected(): void {
		$registries = ( new CoreRegistryFactory() )->create();
		$missing = ( new Compiler( null, null, null, $registries ) )->compile( $this->blueprint( 'missing.action' ) );
		$registries->form_actions()->register( $this->action( 'unsafe.action', '1.0.0', [ 'retryable' ] ) );
		$unsafe = ( new Compiler( null, null, null, $registries ) )->compile( $this->blueprint( 'unsafe.action' ) );

		self::assertContains( 'entry_action_missing', array_column( $missing->errors(), 'code' ) );
		self::assertContains( 'entry_action_durability_required', array_column( $unsafe->errors(), 'code' ) );
	}

	private function action( $id, $version, array $capabilities ) {
		return new class( $id, $version, $capabilities ) implements FormActionInterface {
			private $id;
			private $version;
			private $capabilities;
			public $executions = 0;

			public function __construct( $id, $version, array $capabilities ) {
				$this->id = $id;
				$this->version = $version;
				$this->capabilities = $capabilities;
			}

			public function get_id() {
				return $this->id;
			}

			public function get_version() {
				return $this->version;
			}

			public function get_capabilities() {
				return $this->capabilities;
			}

			public function execute( array $action, array $context = [] ) {
				++$this->executions;
				return [ 'executed' => true, 'event' => $context['event'] ?? '' ];
			}

			public function health_check() {
				return [ 'ok' => true, 'version' => $this->version ];
			}
		};
	}

	private function blueprint( $action_type ) {
		$entity_id = $this->id( 'entity' );
		$entry_id = $this->id( 'entry' );
		$policy_id = $this->id( 'policy' );
		return [
			'api_version' => BlueprintValidator::API_VERSION,
			'kind' => BlueprintValidator::KIND,
			'id' => $this->id( 'blueprint:' . $action_type ),
			'name' => 'SDK action fixture',
			'version' => 1,
			'nodes' => [
				[ 'id' => $entity_id, 'type' => 'entity', 'lane' => 'data', 'name' => 'Records', 'config' => [ 'mode' => 'structured' ] ],
				[
					'id' => $entry_id,
					'type' => 'entry_surface',
					'lane' => 'experience',
					'name' => 'Record workspace',
					'config' => [
						'operations' => [ 'create' ],
						'initial_status' => 'draft',
						'steps' => [],
						'conditions' => [],
						'actions' => [ [ 'id' => $this->id( 'action:' . $action_type ), 'type' => $action_type, 'events' => [ 'created' ], 'config' => [] ] ],
						'guest' => [ 'enabled' => false ],
					],
				],
				[ 'id' => $policy_id, 'type' => 'policy', 'lane' => 'governance', 'name' => 'Record editors', 'config' => [ 'capability' => 'edit_posts', 'ownership' => 'own', 'object_scope' => 'entity' ] ],
			],
			'connections' => [
				[ 'id' => $this->id( 'entry-for:' . $action_type ), 'type' => 'entry_for', 'from' => $entity_id, 'to' => $entry_id ],
				[ 'id' => $this->id( 'entry-policy:' . $action_type ), 'type' => 'governs_entry', 'from' => $policy_id, 'to' => $entry_id ],
			],
		];
	}

	private function job( array $action ) {
		return [
			'id' => 'job-sdk-action',
			'blueprint_id' => $this->id( 'runtime-blueprint' ),
			'surface_id' => $this->id( 'runtime-surface' ),
			'submission_id' => $this->id( 'runtime-submission' ),
			'action_id' => $action['id'],
			'action_type' => $action['type'],
			'event' => 'created',
			'attempts' => 1,
			'context' => [ 'action' => $action, 'execution' => [] ],
		];
	}

	private function id( $seed ) {
		return Uuid::v5( Uuid::LEGACY_NAMESPACE, 'sdk-extension:' . $seed );
	}
}

class SdkExtensionActionStore extends EntryActionStore {

	private $job;
	public $finished;

	public function __construct( array $job ) {
		$this->job = $job;
	}

	public function claim( $id ) {
		return $this->job;
	}

	public function finish( $id, $result ) {
		$this->finished = $result;
		return [ 'attempts' => 5 ];
	}
}

class SdkExtensionRunStore extends RunStore {

	public function start( $blueprint_id, $operation, $change_set_id = null, array $context = [] ) {
		return [ 'id' => 'sdk-run', 'request_id' => 'sdk-request' ];
	}

	public function finish( $id, $status, $error = null ) {
		return true;
	}
}

class SdkCompletionFailureActionStore extends SdkExtensionActionStore {

	public function finish( $id, $result ) {
		$this->finished = $result;
		return new WP_Error( 'eit_test_action_finish_failed', 'The action result could not be persisted.' );
	}
}

class SdkRecordingRunStore extends RunStore {

	public $finished_status;
	public $finished_error;

	public function start( $blueprint_id, $operation, $change_set_id = null, array $context = [] ) {
		return [ 'id' => 'sdk-recording-run', 'request_id' => 'sdk-recording-request' ];
	}

	public function finish( $id, $status, $error = null ) {
		$this->finished_status = $status;
		$this->finished_error = $error;
		return true;
	}
}

class SdkCapturingEnqueueActionStore extends EntryActionStore {

	public $enqueued;

	public function enqueue( array $job ) {
		$this->enqueued = $job;
		return [
			'id' => 'job-captured-action',
			'action_id' => $job['action_id'],
			'status' => 'succeeded',
			'result' => [],
		];
	}
}
