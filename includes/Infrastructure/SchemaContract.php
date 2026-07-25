<?php
/**
 * Declares the minimum infrastructure columns required at runtime.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SchemaContract {

	public static function expected_columns() {
		return [
			Tables::BLUEPRINTS => [ 'id', 'draft_document', 'active_version_id' ],
			Tables::VERSIONS => [ 'id', 'blueprint_id', 'version', 'checksum', 'document' ],
			Tables::ARTIFACTS => [ 'id', 'version_id', 'kind', 'payload' ],
			Tables::BINDINGS => [ 'id', 'field_id', 'storage_key', 'aliases', 'payload' ],
			Tables::CHANGE_SETS => [ 'id', 'status', 'impact', 'confirmation_hash' ],
			Tables::STORAGE_CLAIMS => [ 'identity_hash', 'strategy', 'storage_slug', 'blueprint_id', 'change_set_id', 'artifact_checksum', 'existed_before', 'status', 'failure_code' ],
			Tables::MIGRATION_OPERATIONS => [ 'id', 'blueprint_id', 'change_set_id', 'field_id', 'adapter', 'operation_checksum', 'source_identity_hash', 'target_identity_hash', 'operation', 'status', 'resume_status', 'state_revision', 'cursor', 'copied_count', 'source_count', 'transformed_count', 'target_count', 'rejected_count', 'source_checksum', 'target_checksum', 'attempts', 'error_code' ],
			Tables::LOCKS => [ 'resource_key', 'token_hash', 'expires_at' ],
			Tables::RUNS => [ 'id', 'operation', 'status', 'request_id' ],
			Tables::RECONCILIATIONS => [ 'id', 'change_set_id', 'counts', 'checksums' ],
			Tables::ROLLBACKS => [ 'id', 'from_version_id', 'to_version_id', 'reason' ],
			Tables::RELATIONS => [ 'id', 'relation_id', 'source_id', 'target_id' ],
			Tables::MULTIVALUES => [ 'id', 'field_id', 'owner_id', 'row_id', 'value' ],
			Tables::ENTRY_SUBMISSIONS => [ 'id', 'surface_id', 'actor_key', 'idempotency_hash', 'payload_checksum', 'status', 'response' ],
			Tables::PENDING_UPLOADS => [ 'id', 'blueprint_id', 'version_id', 'contract_checksum', 'surface_id', 'field_id', 'actor_key', 'token_hash', 'storage_name', 'file_checksum', 'status', 'submission_id', 'attachment_id', 'promoted_path', 'cleanup_attempts', 'last_error', 'expires_at' ],
			Tables::ACTION_JOBS => [ 'id', 'submission_id', 'action_id', 'action_type', 'status', 'attempts', 'context', 'available_at' ],
			Tables::MIGRATIONS => [ 'id', 'source_type', 'source_key', 'blueprint_id', 'source_checksum', 'draft_checksum', 'status', 'comparison' ],
			Tables::RUN_EVENTS => [ 'id', 'run_id', 'sequence', 'event_type', 'payload', 'duration_ms' ],
			Tables::QA_SCENARIOS => [ 'id', 'blueprint_id', 'name', 'kind', 'request', 'expected', 'last_result', 'status' ],
		];
	}
}
