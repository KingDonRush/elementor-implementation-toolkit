<?php
/**
 * Shared admin view components for Toolkit product screens.
 */

namespace EIT\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminComponents {

	public static function icon( $name, $class = '' ) {
		$filename = sanitize_file_name( $name ) . '.webp';
		$classes  = trim( 'eit-asset-icon ' . $class );

		return '<img class="' . esc_attr( $classes ) . '" src="' . esc_url( EIT_URL . 'assets/images/icons/' . $filename ) . '" alt="" aria-hidden="true" loading="lazy" />';
	}

	public static function status_badge( $status, $label = '' ) {
		$status = sanitize_key( $status );
		$label  = $label ?: ucfirst( $status );
		$icon   = 'state-' . ( $status ?: 'draft' );

		return '<span class="eit-status-badge eit-status-badge--' . esc_attr( $status ) . '">' . self::icon( $icon, 'eit-status-badge__icon' ) . esc_html( $label ) . '</span>';
	}

	public static function layer_badge( $label, $tone = 'teal' ) {
		return '<span class="eit-layer-badge eit-layer-badge--' . esc_attr( sanitize_key( $tone ) ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function preview_hint( $label, $value ) {
		return '<dl class="eit-preview-contract-row"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></dl>';
	}
}
