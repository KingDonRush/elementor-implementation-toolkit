<?php
/**
 * Filter option parsing helpers for the Elementor controller widget.
 */

namespace EIT\Elementor\FilterController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilterOptions {

	public static function parse( $raw ) {
		$options = [];
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			$value = sanitize_title( $parts[0] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			$options[] = [
				'value'  => $value,
				'label'  => sanitize_text_field( $parts[1] ?? $parts[0] ),
				'visual' => sanitize_text_field( $parts[2] ?? '' ),
			];
		}

		return $options;
	}

	public static function default_rating_options() {
		return [
			[ 'value' => '5', 'label' => '5 stars', 'visual' => '' ],
			[ 'value' => '4', 'label' => '4+ stars', 'visual' => '' ],
			[ 'value' => '3', 'label' => '3+ stars', 'visual' => '' ],
			[ 'value' => '2', 'label' => '2+ stars', 'visual' => '' ],
			[ 'value' => '1', 'label' => '1+ star', 'visual' => '' ],
		];
	}

	public static function swatch_style( $visual ) {
		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $visual ) ) {
			return 'background-color:' . $visual . ';';
		}

		if ( filter_var( $visual, FILTER_VALIDATE_URL ) ) {
			return 'background-image:url(' . esc_url_raw( $visual ) . ');';
		}

		return '';
	}
}
