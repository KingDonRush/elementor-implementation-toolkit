<?php
/**
 * Redacts secrets and summarizes content before diagnostic persistence.
 */

namespace EIT\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PayloadRedactor {

	const MAX_DEPTH = 8;
	const MAX_ITEMS = 50;
	const MAX_STRING_BYTES = 256;

	public function redact( $value, $key = '', $depth = 0 ) {
		if ( $depth >= self::MAX_DEPTH ) {
			return '[depth-limited]';
		}
		if ( $this->is_secret_key( $key ) ) {
			return '[redacted]';
		}
		if ( $this->is_content_key( $key ) ) {
			return $this->summary( $value );
		}
		if ( is_array( $value ) ) {
			$redacted = [];
			foreach ( array_slice( $value, 0, self::MAX_ITEMS, true ) as $child_key => $child ) {
				$redacted[ $child_key ] = $this->redact( $child, (string) $child_key, $depth + 1 );
			}
			if ( count( $value ) > self::MAX_ITEMS ) {
				$redacted['_truncated_items'] = count( $value ) - self::MAX_ITEMS;
			}
			return $redacted;
		}
		if ( is_object( $value ) ) {
			return $this->summary( get_class( $value ) );
		}
		if ( is_string( $value ) && $this->looks_sensitive( $value ) ) {
			return '[redacted]';
		}
		if ( is_string( $value ) && strlen( $value ) > self::MAX_STRING_BYTES ) {
			return $this->summary( $value );
		}
		return $value;
	}

	private function is_secret_key( $key ) {
		return 1 === preg_match( '/secret|token|password|authorization|cookie|nonce|credential|api.?key/i', $key );
	}

	private function is_content_key( $key ) {
		return 1 === preg_match( '/^(html|content|body|document|raw|payload|values?|search|email|phone|address|_elementor_data)$/i', $key );
	}

	private function looks_sensitive( $value ) {
		return 1 === preg_match(
			'/(?:^|\s)(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}|(?:[?&]|^)(?:access_?token|token|api_?key|secret|password|signature)=[^&\s]{4,}|(?:sk|ghp|github_pat|xox[baprs])[-_][A-Za-z0-9_-]{12,}|AKIA[0-9A-Z]{16}/i',
			$value
		);
	}

	private function summary( $value ) {
		$encoded = is_string( $value ) ? $value : wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$encoded = false === $encoded ? gettype( $value ) : $encoded;
		return [
			'redacted' => true,
			'type' => gettype( $value ),
			'bytes' => strlen( $encoded ),
			'sha256' => hash( 'sha256', $encoded ),
		];
	}
}
