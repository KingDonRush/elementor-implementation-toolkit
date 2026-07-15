<?php
/**
 * Typed CRUD and query access for CCT tables.
 */

namespace EIT\CCT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Repository {

	const MAX_PER_PAGE = 100;

	public function get( $type, $id ) {
		global $wpdb;

		$definition = DefinitionManager::get( $type );
		$id = absint( $id );
		if ( ! $definition || ! $id ) {
			return null;
		}

		$table = SchemaManager::table_name( $type );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $this->hydrate( $row, $definition ) : null;
	}

	public function save( $type, array $values, $id = 0 ) {
		global $wpdb;

		$definition = DefinitionManager::get( $type, false );
		if ( ! $definition ) {
			return new \WP_Error( 'eit_cct_missing', __( 'Content type not found.', 'elementor-implementation-toolkit' ) );
		}
		if ( $this->missing_required_value( $values['title'] ?? null ) ) {
			return new \WP_Error( 'eit_cct_title_required', __( 'A title is required.', 'elementor-implementation-toolkit' ) );
		}
		foreach ( DefinitionManager::fields( $type ) as $key => $field ) {
			if ( ! empty( $field['required'] ) && $this->missing_required_value( $values[ $key ] ?? null ) ) {
				return new \WP_Error( 'eit_cct_required_field', sprintf( __( 'The field "%s" is required.', 'elementor-implementation-toolkit' ), $field['label'] ?: $key ) );
			}
		}

		$schema_result = SchemaManager::sync_definition( $definition );
		if ( is_wp_error( $schema_result ) ) {
			return $schema_result;
		}
		$table = SchemaManager::table_name( $type );
		$now = current_time( 'mysql' );
		$data = [
			'title'      => sanitize_text_field( $values['title'] ?? '' ),
			'status'     => $this->sanitize_status( $values['status'] ?? 'publish' ),
			'menu_order' => intval( $values['menu_order'] ?? 0 ),
			'updated_at' => $now,
		];

		foreach ( DefinitionManager::fields( $type ) as $key => $field ) {
			$value = array_key_exists( $key, $values ) ? $values[ $key ] : ( $field['default'] ?? '' );
			$data[ SchemaManager::column_name( $key ) ] = FieldTypes::sanitize( $value, $field );
		}

		$id = absint( $id );
		if ( $id ) {
			$updated = $wpdb->update( $table, $data, [ 'id' => $id ] );
			return false === $updated ? new \WP_Error( 'eit_cct_update_failed', $wpdb->last_error ) : $id;
		}

		$data['created_at'] = $now;
		$inserted = $wpdb->insert( $table, $data );

		return false === $inserted
			? new \WP_Error( 'eit_cct_insert_failed', $wpdb->last_error )
			: absint( $wpdb->insert_id );
	}

	public function delete( $type, $id ) {
		global $wpdb;

		if ( ! DefinitionManager::get( $type ) ) {
			return false;
		}

		return false !== $wpdb->delete( SchemaManager::table_name( $type ), [ 'id' => absint( $id ) ], [ '%d' ] );
	}

	public function query( $type, array $args = [] ) {
		global $wpdb;

		$definition = DefinitionManager::get( $type );
		if ( ! $definition ) {
			return $this->empty_result();
		}

		$table = SchemaManager::table_name( $type );
		$fields = DefinitionManager::fields( $type );
		$page = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( self::MAX_PER_PAGE, absint( $args['per_page'] ?? 20 ) ) );
		$base_offset = max( 0, absint( $args['offset'] ?? 0 ) );
		$where = [ '1=1' ];
		$params = [];

		$statuses = $this->normalize_statuses( $args['status'] ?? [ 'publish' ] );
		if ( ! empty( $statuses ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$where[] = "status IN ({$placeholders})";
			$params = array_merge( $params, $statuses );
		}

		$this->append_id_filters( $where, $params, $args );
		$this->append_search( $where, $params, $args['search'] ?? '', $fields );
		$this->append_field_filters( $where, $params, $args['filters'] ?? [], $fields );

		$order_key = sanitize_key( $args['orderby'] ?? 'menu_order' );
		$order_column = $this->resolve_order_column( $order_key, $fields );
		$order = 'DESC' === strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) ? 'DESC' : 'ASC';
		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
		$count_prepared = empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total_matches = (int) $wpdb->get_var( $count_prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = max( 0, $total_matches - $base_offset );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page = min( $page, $pages );
		$offset = $base_offset + ( ( $page - 1 ) * $per_page );

		$query_params = array_merge( $params, [ $per_page, $offset ] );
		$sql = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY `{$order_column}` {$order}, id ASC LIMIT %d OFFSET %d";
		$prepared = $wpdb->prepare( $sql, $query_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return [
			'items'    => array_map(
				function ( $row ) use ( $definition ) {
					return $this->hydrate( $row, $definition );
				},
				$rows ?: []
			),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
		];
	}

	public function query_public( $type, array $args = [] ) {
		$args['status'] = [ 'publish' ];
		return $this->query( $type, $args );
	}

	private function append_id_filters( array &$where, array &$params, array $args ) {
		foreach ( [ 'include' => 'IN', 'exclude' => 'NOT IN' ] as $key => $operator ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) ( $args[ $key ] ?? [] ) ) ) );
			if ( empty( $ids ) ) {
				continue;
			}
			$where[] = 'id ' . $operator . ' (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $ids );
		}
	}

	private function append_search( array &$where, array &$params, $search, array $fields ) {
		$search = sanitize_text_field( $search );
		if ( '' === $search ) {
			return;
		}

		global $wpdb;
		$search_columns = [ 'title' ];
		foreach ( $fields as $key => $field ) {
			if ( ! empty( $field['filterable'] ) && in_array( $field['type'], [ 'text', 'textarea', 'email', 'url', 'select' ], true ) ) {
				$search_columns[] = SchemaManager::column_name( $key );
			}
		}

		$parts = [];
		foreach ( $search_columns as $column ) {
			$parts[] = "`{$column}` LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$where[] = '(' . implode( ' OR ', $parts ) . ')';
	}

	private function append_field_filters( array &$where, array &$params, array $filters, array $fields ) {
		global $wpdb;

		foreach ( array_slice( $filters, 0, 20 ) as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$key = sanitize_key( $filter['key'] ?? '' );
			if ( empty( $fields[ $key ]['filterable'] ) ) {
				continue;
			}

			$field = $fields[ $key ];
			$column = SchemaManager::column_name( $key );
			$value = $filter['value'] ?? '';
			$compare = strtoupper( sanitize_key( $filter['compare'] ?? '=' ) );

			if ( in_array( $field['type'], [ 'multiselect', 'gallery' ], true ) ) {
				$values = is_array( $value ) ? $value : [ $value ];
				$likes = [];
				foreach ( $values as $needle ) {
					$likes[] = "`{$column}` LIKE %s";
					$params[] = '%"' . $wpdb->esc_like( sanitize_text_field( $needle ) ) . '"%';
				}
				if ( $likes ) {
					$where[] = '(' . implode( ' OR ', $likes ) . ')';
				}
				continue;
			}

			if ( is_array( $value ) ) {
				$values = array_values( array_filter( array_map( 'sanitize_text_field', $value ), 'strlen' ) );
				if ( $values ) {
					$where[] = "`{$column}` IN (" . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')';
					$params = array_merge( $params, $values );
				}
				continue;
			}

			if ( in_array( $compare, [ 'GT', 'GTE', 'LT', 'LTE' ], true ) ) {
				$operators = [ 'GT' => '>', 'GTE' => '>=', 'LT' => '<', 'LTE' => '<=' ];
				$where[] = "`{$column}` {$operators[ $compare ]} %s";
				$params[] = sanitize_text_field( $value );
			} elseif ( 'LIKE' === $compare ) {
				$where[] = "`{$column}` LIKE %s";
				$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $value ) ) . '%';
			} else {
				$where[] = "`{$column}` = %s";
				$params[] = FieldTypes::sanitize( $value, $field );
			}
		}
	}

	private function resolve_order_column( $key, array $fields ) {
		$base = [ 'id', 'title', 'status', 'menu_order', 'created_at', 'updated_at' ];
		if ( in_array( $key, $base, true ) ) {
			return $key;
		}

		return isset( $fields[ $key ] ) && ! empty( $fields[ $key ]['filterable'] ) && FieldTypes::is_indexable( $fields[ $key ]['type'] ?? '' )
			? SchemaManager::column_name( $key )
			: 'menu_order';
	}

	private function hydrate( array $row, array $definition ) {
		$item = [
			'id'         => absint( $row['id'] ?? 0 ),
			'title'      => (string) ( $row['title'] ?? '' ),
			'status'     => (string) ( $row['status'] ?? '' ),
			'menu_order' => intval( $row['menu_order'] ?? 0 ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ),
		];

		foreach ( $definition['fields'] ?? [] as $field ) {
			$key = $field['key'];
			$column = SchemaManager::column_name( $key );
			$item[ $key ] = FieldTypes::decode( $row[ $column ] ?? null, $field );
		}

		return $item;
	}

	private function sanitize_status( $status ) {
		$status = sanitize_key( $status );
		return in_array( $status, [ 'publish', 'draft', 'archived' ], true ) ? $status : 'draft';
	}

	private function normalize_statuses( $statuses ) {
		$statuses = is_array( $statuses ) ? $statuses : [ $statuses ];
		$statuses = array_map( 'sanitize_key', $statuses );
		$statuses = array_values( array_intersect( $statuses, [ 'publish', 'draft', 'archived' ] ) );

		return $statuses ?: [ 'publish' ];
	}

	private function empty_result() {
		return [
			'items'    => [],
			'total'    => 0,
			'page'     => 1,
			'per_page' => 20,
			'pages'    => 1,
		];
	}

	private function missing_required_value( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! $this->missing_required_value( $item ) ) {
					return false;
				}
			}
			return true;
		}

		return null === $value || '' === trim( (string) $value );
	}
}
