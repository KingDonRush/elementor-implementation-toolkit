<?php
/**
 * Deterministic compiler output.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompilationResult {

	private $data;

	public function __construct( array $data ) {
		$this->data = array_merge(
			[ 'valid' => false, 'errors' => [], 'artifacts' => [], 'bindings' => [], 'recommendations' => [], 'checksum' => '' ],
			$data
		);
	}

	public function is_valid() {
		return ! empty( $this->data['valid'] );
	}

	public function errors() {
		return $this->data['errors'];
	}

	public function artifacts() {
		return $this->data['artifacts'];
	}

	public function bindings() {
		return $this->data['bindings'];
	}

	public function checksum() {
		return $this->data['checksum'];
	}

	public function to_array() {
		return $this->data;
	}
}
