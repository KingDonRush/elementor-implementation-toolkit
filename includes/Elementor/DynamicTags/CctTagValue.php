<?php
/**
 * Shared CCT dynamic tag value resolver.
 */

namespace EIT\Elementor\DynamicTags;

use EIT\CCT\CurrentItemContext;
use EIT\CCT\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CctTagValue {

	public static function resolve( $type, $field ) {
		$item = CurrentItemContext::item();

		if ( ! $item && self::is_editor() && current_user_can( 'edit_posts' ) && $type ) {
			$result = ( new Repository() )->query(
				$type,
				[
					'status'   => [ 'publish', 'draft' ],
					'per_page' => 1,
					'page'     => 1,
				]
			);
			$item = $result['items'][0] ?? null;
		}

		return is_array( $item ) && array_key_exists( $field, $item ) ? $item[ $field ] : null;
	}

	private static function is_editor() {
		return class_exists( '\Elementor\Plugin' )
			&& \Elementor\Plugin::$instance
			&& \Elementor\Plugin::$instance->editor
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}
