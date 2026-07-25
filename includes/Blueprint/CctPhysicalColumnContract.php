<?php
/**
 * Canonical physical column signatures for compiled CCT field primitives.
 */

namespace EIT\Blueprint;

use EIT\CCT\FieldTypes;
use EIT\CCT\SchemaManager as CctSchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctPhysicalColumnContract {

	public static function expected( $storage_key, array $semantics ) {
		$column = CctSchemaManager::column_name( $storage_key );
		$type = self::runtime_type( $semantics['type'] ?? '' );
		$sql = trim( FieldTypes::sql_type( $type ) );
		if ( '' === $column || ! preg_match( '/^(.+?)\s+(NULL|NOT NULL)(?:\s+DEFAULT\s+(.+))?$/i', $sql, $matches ) ) {
			return new \WP_Error( 'eit_migration_physical_signature_invalid', __( 'CCT field semantics do not compile to a verifiable physical column.', 'elementor-implementation-toolkit' ) );
		}

		return [
			'column' => $column,
			'type' => self::normalize_type( $matches[1] ),
			'nullable' => 'NULL' === strtoupper( $matches[2] ),
			'default' => self::normalize_default( $matches[3] ?? null ),
			'extra' => '',
		];
	}

	public static function actual( array $column ) {
		$name = (string) ( $column['Field'] ?? $column['field'] ?? '' );
		$type = (string) ( $column['Type'] ?? $column['type'] ?? '' );
		$nullable = strtoupper( (string) ( $column['Null'] ?? $column['null'] ?? '' ) );
		if ( '' === $name || '' === $type || ! in_array( $nullable, [ 'YES', 'NO' ], true ) ) {
			return new \WP_Error( 'eit_migration_column_signature_unreadable', __( 'CCT column metadata is incomplete.', 'elementor-implementation-toolkit' ) );
		}

		return [
			'column' => $name,
			'type' => self::normalize_type( $type ),
			'nullable' => 'YES' === $nullable,
			'default' => self::normalize_default( $column['Default'] ?? $column['default'] ?? null ),
			'extra' => strtolower( trim( (string) ( $column['Extra'] ?? $column['extra'] ?? '' ) ) ),
		];
	}

	public static function matches( array $expected, array $column ) {
		$actual = self::actual( $column );
		return is_wp_error( $actual ) ? $actual : self::normalize_expected( $expected ) === $actual;
	}

	public static function normalize_expected( array $signature ) {
		return [
			'column' => (string) ( $signature['column'] ?? '' ),
			'type' => self::normalize_type( $signature['type'] ?? '' ),
			'nullable' => true === ( $signature['nullable'] ?? null ),
			'default' => self::normalize_default( $signature['default'] ?? null ),
			'extra' => strtolower( trim( (string) ( $signature['extra'] ?? '' ) ) ),
		];
	}

	private static function runtime_type( $semantic_type ) {
		$types = [
			'short_text' => 'text', 'long_text' => 'textarea', 'rich_text' => 'textarea',
			'integer' => 'number', 'decimal' => 'number', 'money' => 'number', 'percentage' => 'number', 'calculated' => 'number',
			'boolean' => 'boolean', 'single_choice' => 'select', 'multiple_choice' => 'multiselect',
			'date' => 'date', 'time' => 'time', 'datetime' => 'datetime', 'image' => 'image', 'gallery' => 'gallery',
			'email' => 'email', 'phone' => 'text', 'url' => 'url', 'file' => 'image', 'color' => 'text',
		];
		return $types[ sanitize_key( $semantic_type ) ] ?? 'textarea';
	}

	private static function normalize_type( $type ) {
		$type = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $type ) ) );
		$type = preg_replace( '/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type );
		return preg_replace( '/\binteger\b/', 'int', $type );
	}

	private static function normalize_default( $default ) {
		if ( null === $default ) {
			return null;
		}
		$default = trim( (string) $default );
		if ( 2 <= strlen( $default ) && "'" === $default[0] && "'" === $default[ strlen( $default ) - 1 ] ) {
			$default = substr( $default, 1, -1 );
		}
		return $default;
	}
}
