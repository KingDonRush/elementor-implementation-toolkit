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
			self::$lifecycle = new LifecycleService(
				[
					'compiler' => new Compiler( null, null, null, $registries ),
					'preparer' => new RuntimeArtifactPreparer( $registries ),
				]
			);
		}
		return self::$lifecycle;
	}
}
