<?php
/**
 * Reports active compiled runtime authority separately from legacy and candidate sources.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ActiveRuntimeProbe {

	private $definitions_reader;
	private $semantics;

	public function __construct( $definitions_reader = null, ?LegacySemanticContract $semantics = null ) {
		$this->definitions_reader = is_callable( $definitions_reader ) ? $definitions_reader : [ $this, 'read_definitions' ];
		$this->semantics = $semantics ?: new LegacySemanticContract();
	}

	public function probe( $source_type, $source_key ) {
		$strategy = sanitize_key( $source_type );
		if ( ! in_array( $strategy, [ 'cpt', 'cct' ], true ) ) {
			return [ 'available' => false, 'authority' => 'active_compiled_artifact', 'contract_checksum' => '' ];
		}
		$definitions = call_user_func( $this->definitions_reader, $strategy );
		$slug = sanitize_key( $source_key );
		$definition = is_array( $definitions ) && is_array( $definitions[ $slug ] ?? null ) ? $definitions[ $slug ] : null;
		if ( null === $definition ) {
			return [ 'available' => false, 'authority' => 'active_compiled_artifact', 'contract_checksum' => '' ];
		}
		$contract = $this->semantics->from_definition( $strategy, $definition );
		return [
			'available' => true,
			'authority' => 'active_compiled_artifact',
			'contract_checksum' => $this->semantics->checksum( $contract ),
		];
	}

	public function read_definitions( $strategy ) {
		return 'cpt' === $strategy
			? RuntimeDefinitionProvider::cpt_definitions()
			: RuntimeDefinitionProvider::cct_definitions();
	}
}
