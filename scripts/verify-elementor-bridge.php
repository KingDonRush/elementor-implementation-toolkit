<?php
/**
 * WordPress integration verification for the V0.9 Elementor and Woo bridges.
 */

use EIT\Admin\BlueprintAdminPresenter;
use EIT\Blueprint\BlueprintModule;
use EIT\Elementor\DynamicTags\ElementorProAdapter;
use EIT\Elementor\Loop\CctLoopIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$assertions = 0;
$assert = function ( $condition, $message ) use ( &$assertions ) {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( did_action( 'elementor/loaded' ) > 0 && class_exists( '\Elementor\Plugin' ), 'Elementor Core must be available.' );
$plugin = \Elementor\Plugin::$instance;
$widgets = $plugin->widgets_manager->get_widget_types();
$connector_names = [
	'eit-toolkit-field',
	'eit-toolkit-collection-surface',
	'eit-toolkit-filter-surface',
	'eit-toolkit-entry-surface',
	'eit-toolkit-action',
];
foreach ( $connector_names as $name ) {
	$assert( isset( $widgets[ $name ] ), 'Missing Elementor connector widget: ' . $name );
	$assert( in_array( 'eit-frontend', $widgets[ $name ]->get_style_depends(), true ), 'Connector must declare the shared Toolkit stylesheet: ' . $name );
}
foreach ( array_slice( $connector_names, 1 ) as $name ) {
	$assert( in_array( 'eit-frontend', $widgets[ $name ]->get_script_depends(), true ), 'Interactive connector must declare the shared Toolkit script: ' . $name );
}
$assert( isset( $widgets['eit-filter-controller'] ), 'Legacy Filter Controller must remain registered during 1.x.' );

$pro = new ElementorProAdapter();
$tags = $plugin->dynamic_tags->get_tags();
$typed_tags = [ 'eit-field-text', 'eit-field-number', 'eit-field-url', 'eit-field-image', 'eit-field-gallery', 'eit-field-color' ];
foreach ( $typed_tags as $tag ) {
	$assert( $pro->available() ? isset( $tags[ $tag ] ) : ! isset( $tags[ $tag ] ), 'Typed dynamic tag availability drifted: ' . $tag );
}

$schema = ( new BlueprintAdminPresenter() )->schema();
$woo = $schema['adapters']['woocommerce'] ?? [];
$assert( 13 === count( $woo['fields'] ?? [] ), 'WooCommerce adapter must expose its closed product Field catalog.' );
$assert( isset( $woo['health']['ok'], $woo['health']['message'] ), 'WooCommerce adapter must expose honest health instead of a decorative state.' );
$assert( isset( $schema['elementor_templates'] ) && is_array( $schema['elementor_templates'] ), 'Systems schema must expose a read-only Elementor template catalog.' );
$assert( isset( BlueprintModule::registries()->presentation_adapters()->all()['elementor'] ), 'Elementor Presentation adapter must be registered.' );

$canary = new CctLoopIntegration();
$canary->inspect_public_managers();
$assert( ! empty( $canary->health_check()['ok'] ), 'Elementor public document/widget manager canary failed.' );

$root = dirname( __DIR__ );
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
$internal_pro_references = [];
foreach ( $iterator as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$contents = file_get_contents( $file->getPathname() );
	if ( false !== strpos( $contents, 'ElementorPro\\Modules\\' ) || false !== strpos( $contents, 'Skin_Loop_Base' ) ) {
		$internal_pro_references[] = $file->getPathname();
	}
}
$assert( [] === $internal_pro_references, 'Elementor bridge still depends on an internal Pro class.' );
$assert( ! file_exists( $root . '/includes/Elementor/Loop/SkinLoopCct.php' ), 'Removed Pro skin must not remain as dead compatibility code.' );

$woo_source = '';
foreach ( glob( $root . '/includes/Woo/*.php' ) ?: [] as $file ) {
	$woo_source .= (string) file_get_contents( $file );
}
$assert( false === strpos( $woo_source, 'get_post_meta(' ) && false === strpos( $woo_source, 'update_post_meta(' ), 'Woo adapter must not access product meta directly.' );

$templates_source = (string) file_get_contents( $root . '/includes/Elementor/FilterTemplateManager.php' );
$assert( false !== strpos( $templates_source, "'post_status' => 'draft'" ), 'Explicit template creation must produce a draft.' );
$edit_start = strpos( $templates_source, 'public static function get_edit_url' );
$edit_end = strpos( $templates_source, 'public static function delete_filter_template' );
$edit_source = false !== $edit_start && false !== $edit_end ? substr( $templates_source, $edit_start, $edit_end - $edit_start ) : '';
$assert( false !== $edit_start && false === strpos( $edit_source, 'ensure_editor_surface' ), 'Reading an Elementor edit URL must not mutate post meta.' );

WP_CLI::success( sprintf( 'Elementor/Woo bridge verification passed: %d assertions.', $assertions ) );
