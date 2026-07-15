<?php
/**
 * Explainable CPT, CCT or adapter recommendation for an Entity node.
 */

namespace EIT\Blueprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StorageRecommendation {

	public function recommend( array $entity ) {
		$config = is_array( $entity['config'] ?? null ) ? $entity['config'] : [];
		$recommended = $this->recommended_strategy( $config );
		$selected = (string) ( $config['storage']['strategy'] ?? $recommended );
		$allowed = [ 'cpt', 'cct', 'adapter' ];
		$selected = in_array( $selected, $allowed, true ) ? $selected : $recommended;
		$override = $selected !== $recommended;
		$reason = trim( (string) ( $config['storage']['override_reason'] ?? '' ) );

		return [
			'recommended' => $recommended,
			'selected'    => $selected,
			'override'    => $override,
			'valid'       => ! $override || '' !== $reason,
			'rationale'   => $this->rationale( $recommended, $config ),
			'impact'      => $this->impact( $selected ),
			'override_reason' => $reason,
		];
	}

	private function recommended_strategy( array $config ) {
		$owner = (string) ( $config['owner'] ?? 'toolkit' );
		if ( in_array( $owner, [ 'woocommerce', 'external' ], true ) || ! empty( $config['adapter_id'] ) ) {
			return 'adapter';
		}
		$mode = (string) ( $config['mode'] ?? 'structured' );
		if ( ! empty( $config['public'] ) || ! empty( $config['routed'] ) || ! empty( $config['versioned'] ) || in_array( $mode, [ 'editorial', 'hybrid' ], true ) ) {
			return 'cpt';
		}
		return 'cct';
	}

	private function rationale( $strategy, array $config ) {
		if ( 'adapter' === $strategy ) {
			return 'Data ownership belongs to WooCommerce or an external adapter.';
		}
		if ( 'cpt' === $strategy ) {
			return 'Public, routed, versioned or editorial content benefits from WordPress post semantics.';
		}
		return ! empty( $config['high_volume'] )
			? 'Non-routed high-volume operational records benefit from indexed table storage.'
			: 'Structured non-routed operational records do not require post semantics.';
	}

	private function impact( $strategy ) {
		$impacts = [
			'cpt' => [ 'WordPress URLs and revisions may be available.', 'Post and post-meta query costs apply.' ],
			'cct' => [ 'No public URL or Gutenberg editor is created.', 'Declared filter and sort fields receive table indexes.' ],
			'adapter' => [ 'The external system remains authoritative.', 'Only declared adapter capabilities can be compiled.' ],
		];
		return $impacts[ $strategy ];
	}
}
