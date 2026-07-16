<?php
/**
 * Boots shared Blueprint registries and lifecycle services.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlueprintModule {

	private static $registries;
	private static $lifecycle;

	public function init_hooks() {
		add_action( 'plugins_loaded', [ self::class, 'registries' ], 8 );
		( new RouteRuntime() )->init_hooks();
	}

	public static function registries() {
		if ( null === self::$registries ) {
			self::$registries = ( new CoreRegistryFactory() )->create();
		}
		return self::$registries;
	}

	public static function lifecycle() {
		if ( null === self::$lifecycle ) {
			$registries = self::registries();
			$validator = new BlueprintValidator( null, $registries->field_primitives(), null, $registries );
			self::$lifecycle = new LifecycleService(
				[
					'validator' => $validator,
					'compiler' => new Compiler( $validator, null, null, $registries ),
					'preparer' => new RuntimeArtifactPreparer( $registries ),
				]
			);
		}
		return self::$lifecycle;
	}
}
