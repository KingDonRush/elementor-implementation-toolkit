<?php
/**
 * Immutable Blueprint validation result.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ValidationResult {

	private $errors;
	private $warnings;

	public function __construct( array $errors = [], array $warnings = [] ) {
		$this->errors = array_values( $errors );
		$this->warnings = array_values( $warnings );
	}

	public function is_valid() {
		return empty( $this->errors );
	}

	public function errors() {
		return $this->errors;
	}

	public function warnings() {
		return $this->warnings;
	}

	public function to_array() {
		return [
			'valid'    => $this->is_valid(),
			'errors'   => $this->errors,
			'warnings' => $this->warnings,
		];
	}
}
