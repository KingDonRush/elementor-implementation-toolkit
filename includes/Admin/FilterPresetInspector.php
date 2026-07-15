<?php
/**
 * Human-readable metadata and health summaries for filter presets.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterPresetInspector {

	private $rules;

	public function __construct( FilterPresetDiagnosticRules $rules = null ) {
		$this->rules = $rules ?: new FilterPresetDiagnosticRules();
	}

	public function diagnostics( array $preset ) {
		return $this->rules->analyze( $preset );
	}

	public function filter_labels( array $filters ) {
		$labels = [];
		foreach ( $filters as $filter ) {
			if ( is_array( $filter ) ) {
				$labels[] = $filter['label'] ?? $filter['key'] ?? $filter['type'] ?? '';
			}
		}
		return implode( ', ', array_filter( array_map( 'trim', $labels ) ) );
	}

	public function library_summary( array $presets ) {
		$summary = [ 'draft' => 0, 'widget' => 0, 'filters' => 0, 'attention' => 0 ];
		foreach ( $presets as $preset ) {
			$filters = is_array( $preset['filters'] ?? null ) ? $preset['filters'] : [];
			$summary['filters'] += count( $filters );
			$summary['draft'] += empty( $filters ) ? 1 : 0;
			$summary['widget'] += 'elementor_widget' === ( $preset['created_from']['source'] ?? '' ) ? 1 : 0;
			$summary['attention'] += 'ok' !== $this->health( $this->diagnostics( $preset ) )['severity'] ? 1 : 0;
		}
		return $summary;
	}

	public function source_label( array $preset ) {
		$source = $preset['created_from']['source'] ?? 'legacy';
		if ( 'elementor_widget' === $source ) {
			return __( 'Elementor widget', 'elementor-implementation-toolkit' );
		}
		if ( 'admin' === $source ) {
			return __( 'Admin preset', 'elementor-implementation-toolkit' );
		}
		return __( 'Legacy / unknown', 'elementor-implementation-toolkit' );
	}

	public function source_detail( array $preset ) {
		$source = $preset['created_from'] ?? [];
		if ( 'elementor_widget' === ( $source['source'] ?? '' ) ) {
			$parts = [];
			if ( ! empty( $source['document_id'] ) ) {
				$parts[] = sprintf( __( 'Document #%d', 'elementor-implementation-toolkit' ), absint( $source['document_id'] ) );
			}
			if ( ! empty( $source['element_id'] ) ) {
				$parts[] = sprintf( __( 'Element %s', 'elementor-implementation-toolkit' ), $source['element_id'] );
			}
			return $parts ? implode( ' - ', $parts ) : __( 'Saved from the Elementor editor', 'elementor-implementation-toolkit' );
		}
		if ( 'admin' === ( $source['source'] ?? '' ) ) {
			return __( 'Created or edited in wp-admin', 'elementor-implementation-toolkit' );
		}
		return __( 'No source metadata saved yet', 'elementor-implementation-toolkit' );
	}

	public function updated_label( array $preset ) {
		if ( empty( $preset['updated_at'] ) ) {
			return __( 'Not saved yet', 'elementor-implementation-toolkit' );
		}
		return mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $preset['updated_at'] );
	}

	public function health( array $diagnostics ) {
		$errors = 0;
		$warnings = 0;
		foreach ( $diagnostics as $diagnostic ) {
			$errors += 'error' === ( $diagnostic['severity'] ?? '' ) ? 1 : 0;
			$warnings += 'warning' === ( $diagnostic['severity'] ?? '' ) ? 1 : 0;
		}
		if ( $errors ) {
			return [
				'severity' => 'error', 'label' => __( 'Critical', 'elementor-implementation-toolkit' ),
				'class' => 'eit-status-pill is-error',
				'summary' => sprintf( _n( '%d blocking issue', '%d blocking issues', $errors, 'elementor-implementation-toolkit' ), $errors ),
			];
		}
		if ( $warnings ) {
			return [
				'severity' => 'warning', 'label' => __( 'Review', 'elementor-implementation-toolkit' ),
				'class' => 'eit-status-pill is-warning',
				'summary' => sprintf( _n( '%d warning', '%d warnings', $warnings, 'elementor-implementation-toolkit' ), $warnings ),
			];
		}
		return [
			'severity' => 'ok', 'label' => __( 'Healthy', 'elementor-implementation-toolkit' ),
			'class' => 'eit-status-pill', 'summary' => __( 'No diagnosed issues', 'elementor-implementation-toolkit' ),
		];
	}

	public static function option_based_filter_types() {
		return [ 'checkbox', 'radio', 'select', 'chips', 'toggle', 'swatch', 'rating' ];
	}

	public static function option_count( $options ) {
		$options = trim( (string) $options );
		if ( '' === $options ) {
			return 0;
		}
		return count( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $options ) ) ) );
	}

	public static function icon_class( $type ) {
		$icons = [
			'search' => 'dashicons dashicons-search', 'select' => 'dashicons dashicons-category',
			'range' => 'dashicons dashicons-slides', 'checkbox' => 'dashicons dashicons-yes-alt',
			'radio' => 'dashicons dashicons-marker', 'chips' => 'dashicons dashicons-screenoptions',
			'toggle' => 'dashicons dashicons-controls-repeat', 'date' => 'dashicons dashicons-calendar-alt',
			'rating' => 'dashicons dashicons-star-filled', 'swatch' => 'dashicons dashicons-art',
		];
		return $icons[ $type ] ?? 'dashicons dashicons-filter';
	}
}
