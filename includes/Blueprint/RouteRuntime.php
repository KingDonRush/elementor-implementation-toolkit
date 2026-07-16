<?php
/**
 * Executes active virtual Route contracts without creating hidden WordPress pages.
 */

namespace EIT\Blueprint;

use EIT\Infrastructure\ArtifactStore;
use EIT\Infrastructure\BlueprintStore;
use EIT\Registry\ExtensionContract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RouteRuntime {

	const QUERY_VAR = 'eit_route';
	const CHECKSUM_OPTION = 'eit_route_runtime_checksum';

	private static $current;
	private $contracts;

	public function init_hooks() {
		add_action( 'init', [ $this, 'register_routes' ], 20 );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'template_redirect', [ $this, 'prepare_current' ], 1 );
		add_filter( 'template_include', [ $this, 'template' ], 99 );
		add_filter( 'document_title_parts', [ $this, 'document_title' ] );
	}

	public function contracts() {
		if ( null !== $this->contracts ) {
			return $this->contracts;
		}
		$this->contracts = [];
		$paths = [];
		$artifacts = new ArtifactStore();
		foreach ( ( new BlueprintStore() )->all() as $blueprint ) {
			$version_id = absint( $blueprint['active_version_id'] ?? 0 );
			if ( ! $version_id ) {
				continue;
			}
			foreach ( $artifacts->for_version( $version_id, 'route_contract' ) as $artifact ) {
				$contract = $artifact['payload'];
				$contract['blueprint_id'] = $blueprint['id'];
				$contract['version_id'] = $version_id;
				$contract['collision'] = false;
				$id = (string) ( $contract['route_id'] ?? $artifact['node_id'] );
				$path = strtolower( (string) ( $contract['path'] ?? '' ) );
				if ( 'virtual' === ( $contract['kind'] ?? '' ) && 'public' === ( $contract['exposure'] ?? '' ) && isset( $paths[ $path ] ) ) {
					$contract['collision'] = true;
					$this->contracts[ $paths[ $path ] ]['collision'] = true;
					do_action( 'eit_route_collision', $path, $paths[ $path ], $id );
				} else {
					$paths[ $path ] = $id;
				}
				$this->contracts[ $id ] = $contract;
			}
		}
		return $this->contracts;
	}

	public function register_routes() {
		$registered = [];
		foreach ( $this->contracts() as $route_id => $contract ) {
			if ( 'virtual' !== ( $contract['kind'] ?? '' ) || 'public' !== ( $contract['exposure'] ?? '' ) || ! empty( $contract['collision'] ) ) {
				continue;
			}
			$path = trim( (string) $contract['path'], '/' );
			if ( '' === $path ) {
				continue;
			}
			add_rewrite_rule( '^' . preg_quote( $path, '#' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=' . rawurlencode( $route_id ), 'top' );
			$registered[ $path ] = $route_id;
		}
		ksort( $registered );
		$checksum = hash( 'sha256', wp_json_encode( $registered ) );
		if ( ! hash_equals( (string) get_option( self::CHECKSUM_OPTION, '' ), $checksum ) ) {
			update_option( self::CHECKSUM_OPTION, $checksum, false );
			flush_rewrite_rules( false );
		}
	}

	public function query_vars( array $variables ) {
		$variables[] = self::QUERY_VAR;
		return array_values( array_unique( $variables ) );
	}

	public function prepare_current() {
		$route_id = strtolower( sanitize_text_field( (string) get_query_var( self::QUERY_VAR ) ) );
		$contract = $this->contracts()[ $route_id ] ?? null;
		if ( ! $contract || 'public' !== ( $contract['exposure'] ?? '' ) || ! empty( $contract['collision'] ) ) {
			return;
		}
		$presentation = $this->presentation( $contract );
		$adapter = $presentation ? BlueprintModule::registries()->presentation_adapters()->get( $presentation['adapter']['id'] ?? '' ) : null;
		$verified = $adapter ? ExtensionContract::verify( $adapter, $presentation['adapter'] ?? [] ) : new \WP_Error( 'eit_extension_missing' );
		if ( is_wp_error( $verified ) ) {
			do_action( 'eit_route_presentation_unavailable', $contract, $presentation );
			return;
		}
		try {
			$content = $adapter->render( $presentation, [ 'route' => $contract ] );
		} catch ( \Throwable $error ) {
			do_action( 'eit_route_presentation_failed', $contract, get_class( $error ) );
			return;
		}
		if ( is_wp_error( $content ) || '' === trim( (string) $content ) ) {
			return;
		}
		self::$current = [ 'contract' => $contract, 'content' => (string) $content ];
		status_header( 200 );
		global $wp_query;
		if ( is_object( $wp_query ) ) {
			$wp_query->is_404 = false;
			$wp_query->is_page = true;
		}
	}

	public function template( $template ) {
		return self::$current ? dirname( __DIR__, 2 ) . '/templates/route.php' : $template;
	}

	public function document_title( array $parts ) {
		if ( self::$current ) {
			$parts['title'] = self::$current['contract']['name'];
		}
		return $parts;
	}

	public static function current_content() {
		return (string) ( self::$current['content'] ?? '' );
	}

	private function presentation( array $route ) {
		foreach ( ( new ArtifactStore() )->for_version( $route['version_id'], 'presentation_contract' ) as $artifact ) {
			if ( (string) ( $route['presentation_id'] ?? '' ) === (string) $artifact['node_id'] ) {
				return $artifact['payload'];
			}
		}
		return null;
	}
}
