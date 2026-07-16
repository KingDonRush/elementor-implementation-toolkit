<?php
/**
 * Exercises the protected pending-upload state machine before Entry compilation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return static function ( callable $assert, array $ids, \EIT\Entry\GuestIntakeGuard $guest_guard ) {
	global $wpdb;

	$source = wp_tempnam( 'eit-pending-upload.png' );
	$assert( $source && false !== file_put_contents( $source, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' ) ), 'Pending upload fixture could not be created.' );
	$actor = $guest_guard->actor_key();
	$checksum = hash( 'sha256', 'entry-pending-contract-v1' );
	$identity = [ 'blueprint_id' => $ids['blueprint'], 'version_id' => 1, 'contract_checksum' => $checksum, 'surface_id' => $ids['entry'], 'field_id' => $ids['title'], 'actor_key' => $actor ];
	$field = [ 'id' => $ids['title'], 'type' => 'image', 'validation' => [] ];
	$contract = [ 'version_id' => 1, 'artifact_checksum' => $checksum, 'fields' => [ $field ], 'guest' => [ 'upload_max_bytes' => 1048576, 'upload_mime_types' => [ 'image/png' ] ] ];
	$mover = static fn( $from, $to ) => rename( $from, $to );
	$store = new \EIT\Entry\PendingUploadStore( '', $mover, null, fn() => $contract );
	$pending = $store->quarantine( $identity, [ 'tmp_name' => $source, 'original_name' => 'identity-card.png', 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => filesize( $source ) ] );
	$assert( ! is_wp_error( $pending ) && ! isset( $pending['id'], $pending['url'] ) && preg_match( '/^[a-f0-9]{64}$/', $pending['pending_token'] ?? '' ), 'Guest upload response exposed a public media identity instead of an opaque token.' );
	$table = \EIT\Infrastructure\Tables::name( \EIT\Infrastructure\Tables::PENDING_UPLOADS );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE token_hash = %s", hash( 'sha256', $pending['pending_token'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$protected_path = trailingslashit( get_option( 'eit_private_upload_directory_path' ) ) . $row['storage_name'];
	$assert( $row && is_file( $protected_path ) && ! str_starts_with( realpath( $protected_path ), realpath( ABSPATH ) ), 'Guest upload did not remain outside public WordPress paths.' );
	$foreign = $store->authorize( $pending['pending_token'], array_merge( $identity, [ 'actor_key' => hash( 'sha256', 'another-actor' ) ] ) );
	$assert( is_wp_error( $foreign ) && 'eit_entry_pending_upload_forbidden' === $foreign->get_error_code(), 'Opaque pending token bypassed actor ownership.' );
	$submission_id = \EIT\Blueprint\Uuid::v4();
	$claimed_identity = array_merge( $identity, [ 'submission_id' => $submission_id ] );
	$stale = $store->claim( $pending['pending_token'], array_merge( $claimed_identity, [ 'version_id' => 2 ] ) );
	$assert( is_wp_error( $stale ) && 'eit_entry_pending_upload_forbidden' === $stale->get_error_code(), 'Pending upload crossed its immutable Blueprint version binding.' );
	$changed_contract = $contract;
	$changed_contract['fields'][0] = [ 'id' => $ids['title'], 'type' => 'file', 'validation' => [ 'accept' => 'application/pdf' ] ];
	$changed = ( new \EIT\Entry\PendingUploadStore( '', $mover, null, fn() => $changed_contract ) )->claim( $pending['pending_token'], $claimed_identity );
	$assert( is_wp_error( $changed ) && 'eit_entry_pending_policy_changed' === $changed->get_error_code(), 'Pending upload bypassed the current field MIME policy.' );
	$assert( ! is_wp_error( $store->claim( $pending['pending_token'], $claimed_identity ) ), 'Pending upload could not acquire its submission fence.' );
	$assert( ! $store->cleanup( $row['id'], $ids['entry'] ) && is_file( $protected_path ), 'A stale scheduled cleanup ignored the renewed submission lease.' );
	$rival = $store->claim( $pending['pending_token'], array_merge( $identity, [ 'submission_id' => \EIT\Blueprint\Uuid::v4() ] ) );
	$assert( is_wp_error( $rival ) && 'eit_entry_pending_upload_claimed' === $rival->get_error_code(), 'A second submission acquired an already fenced pending token.' );

	$uploads = wp_upload_dir();
	$crash_filename = 'eit-pending-' . str_replace( '-', '', $row['id'] ) . '.png';
	$crash_relative = ltrim( trailingslashit( $uploads['subdir'] ) . $crash_filename, '/\\' );
	$crash_destination = trailingslashit( $uploads['basedir'] ) . $crash_relative;
	wp_mkdir_p( dirname( $crash_destination ) );
	copy( $protected_path, $crash_destination );
	$wpdb->update( $table, [ 'status' => 'promoting', 'promoted_path' => $crash_relative, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $row['id'], 'submission_id' => $submission_id ] );
	$crash_attachment = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'Interrupted promotion', 'post_name' => 'eit-pending-' . str_replace( '-', '', $row['id'] ), 'post_status' => 'private' ], $crash_destination, 0, true );
	$assert( ! is_wp_error( $crash_attachment ), 'Interrupted promotion attachment fixture could not be created.' );
	$promoted = $store->promote( $pending['pending_token'], $claimed_identity );
	$attachment_id = absint( $promoted['id'] ?? 0 );
	$assert( $attachment_id === absint( $crash_attachment ) && 'private' === get_post_status( $attachment_id ) && $row['id'] === get_post_meta( $attachment_id, '_eit_entry_pending_upload_id', true ), 'Interrupted promotion was not reconciled to its deterministic private attachment.' );
	$assert( true === $store->consume( [ $attachment_id ], $submission_id ), 'Pending attachment consumption failed its submission fence.' );
	$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id = %s", $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$assert( 'consumed' === $status && $store->cleanup( $row['id'], $ids['entry'], true ) && 'attachment' === get_post_type( $attachment_id ), 'Consumed pending media was not reconciled without deleting attached content.' );
	wp_delete_attachment( $attachment_id, true );

	$race_source = wp_tempnam( 'eit-pending-race.png' );
	file_put_contents( $race_source, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' ) );
	$race = $store->quarantine( $identity, [ 'tmp_name' => $race_source, 'original_name' => 'race.png', 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => filesize( $race_source ) ] );
	$race_submission = \EIT\Blueprint\Uuid::v4();
	$race_identity = array_merge( $identity, [ 'submission_id' => $race_submission ] );
	$store->claim( $race['pending_token'], $race_identity );
	$race_attachment = absint( $store->promote( $race['pending_token'], $race_identity )['id'] ?? 0 );
	$race_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE token_hash = %s", hash( 'sha256', $race['pending_token'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$race_destination = trailingslashit( $uploads['basedir'] ) . $race_row['promoted_path'];
	$race_derivative = dirname( $race_destination ) . DIRECTORY_SEPARATOR . pathinfo( $race_destination, PATHINFO_FILENAME ) . '-99x99.webp';
	file_put_contents( $race_derivative, 'interrupted derivative' );
	$assert( $store->cleanup( $race_row['id'], $ids['entry'], true ) && 'attachment' !== get_post_type( $race_attachment ) && ! is_file( $race_derivative ), 'Cleanup did not win its conditional claim or remove unconsumed media derivatives.' );
	$lost = $store->consume( [ $race_attachment ], $race_submission );
	$assert( is_wp_error( $lost ) && 'eit_entry_pending_consume_race' === $lost->get_error_code(), 'Consumption succeeded after cleanup had already won the pending state.' );
	$store->cleanup( $race_row['id'], $ids['entry'], true );
	$before_mismatch = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE blueprint_id = %s", $ids['blueprint'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$mismatch_source = wp_tempnam( 'eit-pending-mismatch.png' );
	file_put_contents( $mismatch_source, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' ) );
	$mismatch = $store->quarantine( $identity, [ 'tmp_name' => $mismatch_source, 'original_name' => 'mismatch.png', 'mime_type' => 'application/pdf', 'extension' => 'png', 'size_bytes' => filesize( $mismatch_source ) ] );
	$after_mismatch = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE blueprint_id = %s", $ids['blueprint'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$assert( is_wp_error( $mismatch ) && 'eit_entry_pending_file_mismatch' === $mismatch->get_error_code() && $before_mismatch === $after_mismatch, 'Protected storage trusted a spoofed MIME identity or orphaned its receiving ledger.' );

	$public_source = wp_tempnam( 'eit-public-pending.png' );
	file_put_contents( $public_source, 'not-moved' );
	$public = ( new \EIT\Entry\PendingUploadStore( $uploads['basedir'], $mover ) )->quarantine( $identity, [ 'tmp_name' => $public_source, 'original_name' => 'public.png', 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => 9 ] );
	$assert( is_wp_error( $public ) && 'eit_entry_pending_directory_public' === $public->get_error_code() && is_file( $public_source ), 'Pending storage accepted a directory under public uploads.' );
	wp_delete_file( $public_source );
	$previous_document_root = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ?? '' ) );
	$document_root = trailingslashit( get_temp_dir() ) . 'eit-document-root';
	wp_mkdir_p( $document_root . '/pending' );
	$_SERVER['DOCUMENT_ROOT'] = $document_root;
	$document = ( new \EIT\Entry\PendingUploadStore( $document_root . '/pending', $mover ) )->quarantine( $identity, [ 'tmp_name' => '/missing', 'original_name' => 'public.png', 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => 9 ] );
	$assert( is_wp_error( $document ) && 'eit_entry_pending_directory_public' === $document->get_error_code(), 'Pending storage accepted a directory under DOCUMENT_ROOT.' );
	$_SERVER['DOCUMENT_ROOT'] = $previous_document_root;
	rmdir( $document_root . '/pending' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only empty fixture cleanup.
	rmdir( $document_root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only empty fixture cleanup.
	$assert( wp_next_scheduled( \EIT\Entry\PendingUploadStore::SWEEP_HOOK ), 'Recurring pending upload sweeper was not scheduled.' );
	wp_clear_scheduled_hook( \EIT\Entry\PendingUploadStore::SWEEP_HOOK );
	$schedule_failure = static fn( $pre, $event ) => \EIT\Entry\PendingUploadStore::SWEEP_HOOK === $event->hook ? new \WP_Error( 'qa_schedule_failure', 'QA schedule failure.' ) : $pre;
	add_filter( 'pre_schedule_event', $schedule_failure, 10, 2 );
	$failed_schedule = $store->ensure_sweeper();
	remove_filter( 'pre_schedule_event', $schedule_failure, 10 );
	$assert( is_wp_error( $failed_schedule ) && 'eit_entry_pending_sweeper_failed' === $failed_schedule->get_error_code(), 'Pending upload admission ignored a cleanup scheduling failure.' );
	$assert( true === $store->ensure_sweeper() && wp_next_scheduled( \EIT\Entry\PendingUploadStore::SWEEP_HOOK ), 'Pending upload sweeper did not recover after a scheduling failure.' );

	return $table;
};
