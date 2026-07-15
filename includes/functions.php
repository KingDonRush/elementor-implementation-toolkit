<?php
/**
 * Public CCT helper API.
 */

use EIT\CCT\CurrentItemContext;
use EIT\CCT\DefinitionManager;
use EIT\CCT\Repository;
use EIT\Blueprint\BlueprintModule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'eit_get_cct_definition' ) ) {
	function eit_get_cct_definition( $type ) {
		return DefinitionManager::get( $type );
	}
}

if ( ! function_exists( 'eit_query_cct_items' ) ) {
	function eit_query_cct_items( $type, array $args = [] ) {
		return ( new Repository() )->query( $type, $args );
	}
}

if ( ! function_exists( 'eit_get_cct_item' ) ) {
	function eit_get_cct_item( $type, $id ) {
		return ( new Repository() )->get( $type, $id );
	}
}

if ( ! function_exists( 'eit_get_current_cct_item' ) ) {
	function eit_get_current_cct_item() {
		return CurrentItemContext::item();
	}
}

if ( ! function_exists( 'eit_blueprint_lifecycle' ) ) {
	function eit_blueprint_lifecycle() {
		return BlueprintModule::lifecycle();
	}
}

if ( ! function_exists( 'eit_blueprint_registries' ) ) {
	function eit_blueprint_registries() {
		return BlueprintModule::registries();
	}
}
