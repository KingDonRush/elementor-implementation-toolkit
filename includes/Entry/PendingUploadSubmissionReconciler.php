<?php
/**
 * Protects media already referenced by a durably persisted Entry submission.
 */

namespace EIT\Entry;

use EIT\Infrastructure\EntrySubmissionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PendingUploadSubmissionReconciler {

	private $submissions;

	public function __construct( ?EntrySubmissionStore $submissions = null ) {
		$this->submissions = $submissions ?: new EntrySubmissionStore();
	}

	public function reconcile( array $record, callable $consume ) {
		if ( 'promoted' !== ( $record['status'] ?? '' ) || empty( $record['submission_id'] ) || empty( $record['attachment_id'] ) ) {
			return $record;
		}
		$submission = $this->submissions->get( $record['submission_id'] );
		if ( ! $submission || 'persisted' !== ( $submission['status'] ?? '' ) ) {
			return $record;
		}
		$response = is_array( $submission['response'] ?? null ) ? $submission['response'] : [];
		$recovery = is_array( $response['_recovery'] ?? null ) ? $response['_recovery'] : [];
		$attachments = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $recovery['pending_attachment_ids'] ?? [] ) ) ) ) );
		if ( ! in_array( absint( $record['attachment_id'] ), $attachments, true ) ) {
			return new \WP_Error( 'eit_entry_pending_recovery_mismatch', __( 'Persisted submission media does not match its pending upload ledger.', 'elementor-implementation-toolkit' ) );
		}
		$result = call_user_func( $consume, [ absint( $record['attachment_id'] ) ], $record['submission_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$record['status'] = 'consumed';
		return $record;
	}
}
