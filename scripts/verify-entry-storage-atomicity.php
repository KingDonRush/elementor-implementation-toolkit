<?php
/**
 * WordPress integration proof for failure-atomic CPT and media mutations.
 */

use EIT\Entry\EntryStorageGateway;
use EIT\Infrastructure\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$assertions = 0;
$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
$post_type = 'eit_entry_atomic';
$post_id = 0;
$attachment_id = 0;
$cct_table = $wpdb->prefix . 'eit_entry_atomic_cct';
$meta_failure = null;
$post_failure = null;
$delete_failure = null;

$remove_failure_filters = function () use ( &$meta_failure, &$post_failure, &$delete_failure ) {
	if ( $meta_failure ) {
		remove_filter( 'update_post_metadata', $meta_failure, 10 );
		$meta_failure = null;
	}
	if ( $post_failure ) {
		remove_filter( 'wp_insert_post_empty_content', $post_failure, 10 );
		$post_failure = null;
	}
	if ( $delete_failure ) {
		remove_filter( 'delete_post_metadata', $delete_failure, 10 );
		$delete_failure = null;
	}
};

$cleanup = function () use ( &$post_id, &$attachment_id, $remove_failure_filters, $cct_table ) {
	global $wpdb;
	$remove_failure_filters();
	if ( $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
		$attachment_id = 0;
	}
	if ( $post_id ) {
		wp_delete_post( $post_id, true );
		$post_id = 0;
	}
	$wpdb->query( "DROP TABLE IF EXISTS `{$cct_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed integration-only table name.
};

try {
	$assert( true === SchemaManager::install(), 'Toolkit infrastructure must install for Entry atomicity verification.' );
	register_post_type( $post_type, [ 'public' => false, 'show_ui' => false, 'supports' => [ 'title' ] ] );
	$cleanup();
	$created_cct_table = $wpdb->query( "CREATE TABLE `{$cct_table}` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, title text NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB " . $wpdb->get_charset_collate() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed integration-only table name.
	$assert( false !== $created_cct_table, 'Transactional CCT fixture table could not be created.' );

	$post_id = wp_insert_post(
		[
			'post_type' => $post_type,
			'post_status' => 'publish',
			'post_title' => 'Original item',
			'meta_input' => [
				'_eit_atomic_title' => 'Original item',
				'_eit_atomic_note' => 'Stable value',
			],
		],
		true
	);
	$assert( ! is_wp_error( $post_id ) && 0 < $post_id, 'Atomicity CPT fixture could not be created.' );
	$attachment_id = wp_insert_attachment(
		[
			'post_title' => 'Atomicity attachment',
			'post_status' => 'inherit',
			'post_mime_type' => 'image/png',
			'post_parent' => 0,
		],
		false,
		0,
		true
	);
	$assert( ! is_wp_error( $attachment_id ) && 0 < $attachment_id, 'Atomicity attachment fixture could not be created.' );
	update_post_meta( $attachment_id, '_eit_entry_pending_surface', 'atomic-surface' );
	update_post_meta( $attachment_id, '_eit_entry_pending_actor', 'atomic-actor' );
	update_post_meta( $attachment_id, '_eit_entry_pending_at', '1' );

	$contract = [
		'blueprint_id' => '11111111-1111-5111-8111-111111111111',
		'entity_id' => '22222222-2222-5222-8222-222222222222',
		'entity' => [ 'strategy' => 'cpt', 'mode' => 'structured', 'definition' => [ 'slug' => $post_type ] ],
		'title_field_id' => 'title',
		'fields' => [
			[ 'id' => 'title', 'type' => 'short_text', 'storage' => [ 'key' => '_eit_atomic_title' ] ],
			[ 'id' => 'note', 'type' => 'short_text', 'storage' => [ 'key' => '_eit_atomic_note' ] ],
			[ 'id' => 'media', 'type' => 'image', 'storage' => [ 'key' => '_eit_atomic_media' ] ],
		],
	];
	$gateway = new EntryStorageGateway();
	$unchanged = $gateway->save(
		$contract,
		[ 'title' => 'Original item', 'note' => 'Stable value' ],
		$post_id,
		'publish',
		get_current_user_id()
	);
	$assert( $post_id === $unchanged, 'An unchanged meta value was misclassified as a storage failure.' );

	$assert_pristine = function ( $message ) use ( $assert, $wpdb, $post_id, $attachment_id ) {
		$db_title = $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
		$db_parent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d", $attachment_id ) );
		$db_meta = function ( $key ) use ( $wpdb, $post_id ) {
			return $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", $post_id, $key ) );
		};
		$assert( 'Original item' === $db_title, $message . ': post title escaped rollback.' );
		$assert( 'Original item' === $db_meta( '_eit_atomic_title' ), $message . ': prior field write escaped rollback.' );
		$assert( 'Stable value' === $db_meta( '_eit_atomic_note' ), $message . ': blocked field changed in storage.' );
		$assert( null === $db_meta( '_eit_atomic_media' ), $message . ': media field escaped rollback.' );
		$assert( 0 === $db_parent, $message . ': attachment parent escaped rollback.' );
		$assert( 'Original item' === get_post( $post_id )->post_title, $message . ': post cache retained rolled-back state.' );
		$assert( 'atomic-surface' === get_post_meta( $attachment_id, '_eit_entry_pending_surface', true ), $message . ': pending media cache retained rolled-back state.' );
	};

	$meta_failure = function ( $check, $object_id, $meta_key ) use ( $post_id ) {
		return $post_id === (int) $object_id && '_eit_atomic_note' === $meta_key ? false : $check;
	};
	add_filter( 'update_post_metadata', $meta_failure, 10, 3 );
	$meta_result = $gateway->save(
		$contract,
		[ 'title' => 'Changed before meta failure', 'note' => 'Blocked value' ],
		$post_id,
		'publish',
		get_current_user_id()
	);
	$remove_failure_filters();
	$assert( is_wp_error( $meta_result ) && 'eit_entry_meta_update_failed' === $meta_result->get_error_code(), 'A real update_post_meta failure was not propagated.' );
	$assert_pristine( 'update_post_meta failure' );

	$cct_repository = new class( $cct_table ) extends \EIT\CCT\Repository {
		private $table;

		public function __construct( $table ) {
			$this->table = $table;
		}

		public function get( $type, $id ) {
			return null;
		}

		public function save( $type, array $values, $id = 0 ) {
			global $wpdb;
			$result = $wpdb->insert( $this->table, [ 'title' => $values['title'] ?? '' ] );
			return false === $result ? new WP_Error( 'eit_atomic_cct_insert_failed', 'Atomicity fixture insert failed.' ) : (int) $wpdb->insert_id;
		}
	};
	$cct_contract = $contract;
	$cct_contract['entity']['strategy'] = 'cct';
	$cct_contract['entity']['definition']['slug'] = 'entry_atomic_cct';
	$cct_gateway = new EntryStorageGateway( $cct_repository );
	$meta_failure = function ( $check, $object_id, $meta_key ) use ( $attachment_id ) {
		return $attachment_id === (int) $object_id && '_eit_entry_cct_owner' === $meta_key ? false : $check;
	};
	add_filter( 'update_post_metadata', $meta_failure, 10, 3 );
	$cct_result = $cct_gateway->save(
		$cct_contract,
		[ 'title' => 'Rolled-back CCT', 'media' => [ 'id' => $attachment_id ] ],
		0,
		'publish',
		get_current_user_id()
	);
	$remove_failure_filters();
	$cct_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$cct_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed integration-only table name.
	$assert( is_wp_error( $cct_result ) && 'eit_entry_meta_update_failed' === $cct_result->get_error_code(), 'A CCT media-owner update_post_meta failure was not propagated.' );
	$assert( 0 === $cct_rows, 'CCT content survived a failed media-owner mutation.' );
	$assert( ! metadata_exists( 'post', $attachment_id, '_eit_entry_cct_owner' ), 'A failed CCT media-owner mutation left partial metadata.' );

	$post_failure = function ( $maybe_empty, $postarr ) use ( $attachment_id ) {
		return $attachment_id === absint( $postarr['ID'] ?? 0 ) ? true : $maybe_empty;
	};
	add_filter( 'wp_insert_post_empty_content', $post_failure, 10, 2 );
	$post_result = $gateway->save(
		$contract,
		[ 'title' => 'Changed before attachment failure', 'note' => 'Changed note', 'media' => [ 'id' => $attachment_id ] ],
		$post_id,
		'publish',
		get_current_user_id()
	);
	$remove_failure_filters();
	$assert( is_wp_error( $post_result ) && 'empty_content' === $post_result->get_error_code(), 'An attachment wp_update_post error was not propagated.' );
	$assert_pristine( 'attachment wp_update_post failure' );

	$delete_failure = function ( $check, $object_id, $meta_key ) use ( $attachment_id ) {
		return $attachment_id === (int) $object_id && '_eit_entry_pending_actor' === $meta_key ? false : $check;
	};
	add_filter( 'delete_post_metadata', $delete_failure, 10, 3 );
	$delete_result = $gateway->save(
		$contract,
		[ 'title' => 'Changed before delete failure', 'note' => 'Changed note', 'media' => [ 'id' => $attachment_id ] ],
		$post_id,
		'publish',
		get_current_user_id()
	);
	$remove_failure_filters();
	$assert( is_wp_error( $delete_result ) && 'eit_entry_meta_delete_failed' === $delete_result->get_error_code(), 'A pending-media delete_post_meta failure was not propagated.' );
	$assert_pristine( 'pending-media delete_post_meta failure' );
	$assert( 'atomic-actor' === get_post_meta( $attachment_id, '_eit_entry_pending_actor', true ), 'Failed pending-state cleanup removed a protected actor marker.' );

	echo 'Entry storage atomicity verification passed: ' . esc_html( (string) $assertions ) . " assertions.\n";
} finally {
	$cleanup();
	unregister_post_type( $post_type );
}
