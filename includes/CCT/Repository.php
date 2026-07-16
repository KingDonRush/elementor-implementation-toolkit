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
			'author_id'  => absint( $values['author_id'] ?? get_current_user_id() ),
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
			if ( false === $updated ) {
				return new \WP_Error( 'eit_cct_update_failed', $wpdb->last_error );
			}
			$this->changed( $definition, $type, $id );
			return $id;
		}

		$data['created_at'] = $now;
		$inserted = $wpdb->insert( $table, $data );

		if ( false === $inserted ) {
			return new \WP_Error( 'eit_cct_insert_failed', $wpdb->last_error );
		}
		$id = absint( $wpdb->insert_id );
		$this->changed( $definition, $type, $id );
		return $id;
	}

	public function delete( $type, $id ) {
		global $wpdb;

		if ( ! DefinitionManager::get( $type ) ) {
			return false;
		}

		$deleted = $wpdb->delete( SchemaManager::table_name( $type ), [ 'id' => absint( $id ) ], [ '%d' ] );
		if ( false !== $deleted && $deleted > 0 ) {
			$this->changed( DefinitionManager::get( $type ), $type, $id );
		}
		return false !== $deleted;
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
		$this->append_search( $where, $params, $args['search'] ?? '', $fields, $args['search_fields'] ?? [] );
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

	public function facet_counts( $type, array $args, array $field_keys ) {
		global $wpdb;

		$definition = DefinitionManager::get( $type );
		if ( ! $definition ) {
			return [];
		}
		$table = SchemaManager::table_name( $type );
		$fields = DefinitionManager::fields( $type );
		$result = [];
		foreach ( array_slice( array_unique( array_map( 'sanitize_key', $field_keys ) ), 0, 10 ) as $key ) {
			$field = $fields[ $key ] ?? null;
			if ( ! $field || empty( $field['filterable'] ) || ! FieldTypes::is_indexable( $field['type'] ?? '' ) ) {
				continue;
			}
			$where = [ 'status = %s' ];
			$params = [ 'publish' ];
			$this->append_id_filters( $where, $params, $args );
			$this->append_search( $where, $params, $args['search'] ?? '', $fields, $args['search_fields'] ?? [] );
			$this->append_field_filters( $where, $params, $args['filters'] ?? [], $fields, $key );
			$column = SchemaManager::column_name( $key );
			$where[] = "`{$column}` IS NOT NULL";
			$sql = "SELECT `{$column}` facet_value, COUNT(*) facet_count FROM `{$table}` WHERE " . implode( ' AND ', $where ) . " GROUP BY `{$column}` ORDER BY facet_count DESC, facet_value ASC LIMIT 100";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from internal table/column identifiers and generated placeholder clauses.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
			$result[ $key ] = [];
			foreach ( $rows ?: [] as $row ) {
				$result[ $key ][ (string) $row['facet_value'] ] = (int) $row['facet_count'];
			}
		}
		return $result;
	}

	public function matching_ids( $type, array $args, $limit = 10000 ) {
		global $wpdb;

		$definition = DefinitionManager::get( $type );
		if ( ! $definition ) {
			return [];
		}
		$table = SchemaManager::table_name( $type );
		$fields = DefinitionManager::fields( $type );
		$where = [ 'status = %s' ];
		$params = [ 'publish' ];
		$this->append_id_filters( $where, $params, $args );
		$this->append_search( $where, $params, $args['search'] ?? '', $fields, $args['search_fields'] ?? [] );
		$this->append_field_filters( $where, $params, $args['filters'] ?? [], $fields );
		$limit = max( 1, min( 10000, absint( $limit ) ) );
		$params[] = $limit;
		$sql = "SELECT id FROM `{$table}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is assembled from internal identifiers and generated placeholder clauses.
		return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ?: [] );
	}

	private function append_id_filters( array &$where, array &$params, array $args ) {
		foreach ( [ 'include' => 'IN', 'exclude' => 'NOT IN' ] as $key => $operator ) {
			$raw_ids = (array) ( $args[ $key ] ?? [] );
			$ids = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );
			if ( empty( $ids ) ) {
				if ( 'include' === $key && $raw_ids ) {
					$where[] = '1=0';
				}
				continue;
			}
			$where[] = 'id ' . $operator . ' (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $ids );
		}
	}

	private function append_search( array &$where, array &$params, $search, array $fields, array $allowed_keys = [] ) {
		$search = sanitize_text_field( $search );
		if ( '' === $search ) {
			return;
		}

		global $wpdb;
		$search_columns = [ 'title' ];
		foreach ( $fields as $key => $field ) {
			$allowed = ! $allowed_keys || in_array( $key, $allowed_keys, true );
			if ( $allowed && ( ! empty( $field['filterable'] ) || in_array( $key, $allowed_keys, true ) ) && in_array( $field['type'], [ 'text', 'textarea', 'email', 'url', 'select' ], true ) ) {
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

	private function append_field_filters( array &$where, array &$params, array $filters, array $fields, $excluded_key = '' ) {
		global $wpdb;

		foreach ( array_slice( $filters, 0, 20 ) as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}

			$key = sanitize_key( $filter['key'] ?? '' );
			if ( $key === $excluded_key || empty( $fields[ $key ]['filterable'] ) ) {
				continue;
			}

			$field = $fields[ $key ];
			$column = SchemaManager::column_name( $key );
			$value = $filter['value'] ?? '';
			$compare = sanitize_key( $filter['compare'] ?? 'equals' );

			if ( in_array( $field['type'], [ 'multiselect', 'gallery' ], true ) ) {
				$values = is_array( $value ) ? $value : [ $value ];
				$likes = [];
				foreach ( $values as $needle ) {
					$likes[] = "`{$column}` " . ( 'not_in' === $compare ? 'NOT LIKE' : 'LIKE' ) . ' %s';
					$params[] = '%"' . $wpdb->esc_like( sanitize_text_field( $needle ) ) . '"%';
				}
				if ( $likes ) {
					$where[] = '(' . implode( 'not_in' === $compare ? ' AND ' : ' OR ', $likes ) . ')';
				}
				continue;
			}

			if ( in_array( $compare, [ 'in', 'not_in' ], true ) && is_array( $value ) ) {
				$values = array_values( array_filter( array_map( 'sanitize_text_field', $value ), 'strlen' ) );
				if ( $values ) {
					$where[] = "`{$column}` " . ( 'not_in' === $compare ? 'NOT IN' : 'IN' ) . ' (' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')';
					$params = array_merge( $params, $values );
				}
				continue;
			}
			if ( 'between' === $compare && is_array( $value ) ) {
				$minimum = $value['min'] ?? $value['from'] ?? '';
				$maximum = $value['max'] ?? $value['to'] ?? '';
				if ( '' !== (string) $minimum ) {
					$where[] = "`{$column}` >= %s";
					$params[] = FieldTypes::sanitize( $minimum, $field );
				}
				if ( '' !== (string) $maximum ) {
					$where[] = "`{$column}` <= %s";
					$params[] = FieldTypes::sanitize( $maximum, $field );
				}
			} elseif ( in_array( $compare, [ 'gt', 'gte', 'lt', 'lte' ], true ) ) {
				$operators = [ 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=' ];
				$where[] = "`{$column}` {$operators[ $compare ]} %s";
				$params[] = FieldTypes::sanitize( $value, $field );
			} elseif ( 'like' === $compare ) {
				$where[] = "`{$column}` LIKE %s";
				$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $value ) ) . '%';
			} else {
				$where[] = "`{$column}` " . ( 'not_equals' === $compare ? '!=' : '=' ) . ' %s';
				$params[] = FieldTypes::sanitize( $value, $field );
			}
		}
	}

	private function resolve_order_column( $key, array $fields ) {
		$base = [ 'id', 'title', 'status', 'menu_order', 'created_at', 'updated_at' ];
		if ( in_array( $key, $base, true ) ) {
			return $key;
		}

		return isset( $fields[ $key ] ) && ( ! empty( $fields[ $key ]['sortable'] ) || ! empty( $fields[ $key ]['filterable'] ) ) && FieldTypes::is_indexable( $fields[ $key ]['type'] ?? '' )
			? SchemaManager::column_name( $key )
			: 'menu_order';
	}

	private function hydrate( array $row, array $definition ) {
		$item = [
			'id'         => absint( $row['id'] ?? 0 ),
			'title'      => (string) ( $row['title'] ?? '' ),
			'status'     => (string) ( $row['status'] ?? '' ),
			'author_id'  => absint( $row['author_id'] ?? 0 ),
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
		return in_array( $status, [ 'publish', 'draft', 'review', 'archived' ], true ) ? $status : 'draft';
	}

	private function normalize_statuses( $statuses ) {
		$statuses = is_array( $statuses ) ? $statuses : [ $statuses ];
		$statuses = array_map( 'sanitize_key', $statuses );
		$statuses = array_values( array_intersect( $statuses, [ 'publish', 'draft', 'review', 'archived' ] ) );

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

	private function changed( array $definition, $type, $id ) {
		do_action( 'eit_collection_entity_changed', (string) ( $definition['entity_id'] ?? '' ), (string) $type, absint( $id ) );
	}
}
