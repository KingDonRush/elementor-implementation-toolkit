<?php
/**
 * Signed time trap, honeypot and bounded guest rate limiting.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GuestIntakeGuard {

	public function issue_token( $surface_id ) {
		$issued = time();
		$nonce = wp_generate_password( 16, false, false );
		$payload = $issued . '|' . $nonce;
		$signature = hash_hmac( 'sha256', $surface_id . '|' . $payload, wp_salt( 'nonce' ) );
		return rtrim( strtr( base64_encode( $payload . '|' . $signature ), '+/', '-_' ), '=' );
	}

	public function verify( array $contract, array $request, $consume_rate = true ) {
		if ( is_user_logged_in() ) {
			return true;
		}
		if ( '' !== trim( (string) ( $request['company_website'] ?? '' ) ) ) {
			return $this->error( 'eit_entry_guest_honeypot', 'Guest intake was rejected.' );
		}
		$token = $this->decode( $request['form_token'] ?? '', $contract['surface_id'] );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$elapsed = time() - $token['issued'];
		if ( $elapsed < (int) $contract['guest']['minimum_seconds'] || $elapsed > 7200 ) {
			return $this->error( 'eit_entry_guest_time_trap', 'Guest intake timing could not be verified.' );
		}

		if ( ! $consume_rate ) {
			return true;
		}
		return $this->consume( $contract, 'submission', (int) $contract['guest']['rate_limit_per_hour'] );
	}

	public function consume_upload( array $contract ) {
		return $this->consume( $contract, 'upload', min( 50, max( 1, (int) $contract['guest']['rate_limit_per_hour'] * 5 ) ) );
	}

	private function consume( array $contract, $scope, $limit ) {
		$key = 'eit_entry_rate_' . hash( 'sha256', $scope . '|' . $contract['surface_id'] . '|' . $this->remote_address() . '|' . gmdate( 'Y-m-d-H' ) );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new \WP_Error( 'eit_entry_guest_rate_limited', __( 'Guest intake has reached its hourly limit. Try again later.', 'elementor-implementation-toolkit' ), [ 'status' => 429 ] );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS + 60 );
		return true;
	}

	public function actor_key() {
		if ( is_user_logged_in() ) {
			return hash( 'sha256', 'user|' . get_current_user_id() );
		}
		$user_agent = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 );
		return hash( 'sha256', 'guest|' . $this->remote_address() . '|' . $user_agent );
	}

	private function decode( $encoded, $surface_id ) {
		$encoded = strtr( trim( (string) $encoded ), '-_', '+/' );
		$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
		$decoded = base64_decode( $encoded, true );
		$parts = false === $decoded ? [] : explode( '|', $decoded );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return $this->error( 'eit_entry_guest_token_invalid', 'Guest intake token is invalid.' );
		}
		$expected = hash_hmac( 'sha256', $surface_id . '|' . $parts[0] . '|' . $parts[1], wp_salt( 'nonce' ) );
		return hash_equals( $expected, $parts[2] )
			? [ 'issued' => (int) $parts[0] ]
			: $this->error( 'eit_entry_guest_token_invalid', 'Guest intake token is invalid.' );
	}

	private function remote_address() {
		$address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return filter_var( $address, FILTER_VALIDATE_IP ) ? $address : 'unknown';
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, __( $message, 'elementor-implementation-toolkit' ), [ 'status' => 400 ] ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
