<?php
/**
 * Version-aware registry for code-provided Toolkit extensions.
 */

namespace EIT\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExtensionRegistry {

	private $interface;
	private $extensions = [];

	public function __construct( $interface ) {
		$this->interface = ltrim( (string) $interface, '\\' );
	}

	public function register( $extension ) {
		if ( ! $extension instanceof $this->interface ) {
			throw new \InvalidArgumentException( 'Toolkit extension does not implement ' . $this->interface . '.' );
		}

		$id = strtolower( trim( (string) $extension->get_id() ) );
		$version = trim( (string) $extension->get_version() );
		if ( ! preg_match( '/^[a-z][a-z0-9_.-]{1,63}$/', $id ) ) {
			throw new \InvalidArgumentException( 'Toolkit extension ID is invalid.' );
		}
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
			throw new \InvalidArgumentException( 'Toolkit extension version must use semantic versioning.' );
		}
		if ( isset( $this->extensions[ $id ] ) ) {
			throw new \LogicException( 'Toolkit extension ID is already registered: ' . $id );
		}

		$this->extensions[ $id ] = $extension;
		return $extension;
	}

	public function get( $id ) {
		return $this->extensions[ strtolower( (string) $id ) ] ?? null;
	}

	public function all() {
		ksort( $this->extensions );
		return $this->extensions;
	}

	public function health() {
		$health = [];
		foreach ( $this->all() as $id => $extension ) {
			$result = $extension->health_check();
			$health[ $id ] = is_array( $result ) ? $result : [ 'ok' => false, 'message' => 'Invalid health response.' ];
		}
		return $health;
	}
}
