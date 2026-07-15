<?php
/**
 * Built-in redirect, email, notification and safe webhook actions.
 */

namespace EIT\Entry;

use EIT\Contracts\FormActionInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoreFormAction implements FormActionInterface {

	private $type;

	public function __construct( $type ) {
		if ( ! in_array( $type, [ 'redirect', 'email', 'notification', 'webhook' ], true ) ) {
			throw new \InvalidArgumentException( 'Unsupported core Entry action.' );
		}
		$this->type = $type;
	}

	public function get_id() {
		return $this->type;
	}

	public function get_version() {
		return '1.0.0';
	}

	public function get_capabilities() {
		return [ 'idempotent_job', 'redacted_context', 'retryable' ];
	}

	public function execute( array $action, array $context = [] ) {
		$config = is_array( $action['config'] ?? null ) ? $action['config'] : [];
		if ( 'redirect' === $this->type ) {
			return $this->redirect( $config );
		}
		if ( 'email' === $this->type ) {
			return $this->email( $config, $context );
		}
		if ( 'notification' === $this->type ) {
			do_action( 'eit_entry_notification', $action, $context );
			return [ 'notified' => true ];
		}
		return $this->webhook( $config, $context );
	}

	public function health_check() {
		return [ 'ok' => true, 'version' => $this->get_version(), 'type' => $this->type ];
	}

	private function redirect( array $config ) {
		$url = wp_validate_redirect( esc_url_raw( $config['url'] ?? '' ), '' );
		return '' === $url
			? new \WP_Error( 'eit_entry_redirect_invalid', __( 'Entry redirect URL is invalid.', 'elementor-implementation-toolkit' ) )
			: [ 'redirect' => $url ];
	}

	private function email( array $config, array $context ) {
		$recipient = 'admin' === ( $config['recipient'] ?? 'admin' ) ? get_option( 'admin_email' ) : sanitize_email( $config['recipient'] ?? '' );
		if ( ! is_email( $recipient ) ) {
			return new \WP_Error( 'eit_entry_email_recipient_invalid', __( 'Entry email recipient is invalid.', 'elementor-implementation-toolkit' ) );
		}
		$subject = sanitize_text_field( $config['subject'] ?? __( 'Entry workflow update', 'elementor-implementation-toolkit' ) );
		$message = sprintf(
			/* translators: 1: surface name, 2: item ID, 3: workflow event. */
			__( '%1$s item %2$s completed the %3$s workflow event.', 'elementor-implementation-toolkit' ),
			$context['surface_name'] ?? __( 'Entry Surface', 'elementor-implementation-toolkit' ),
			$context['item_id'] ?? '',
			$context['event'] ?? ''
		);
		return wp_mail( $recipient, $subject, $message )
			? [ 'sent' => true ]
			: new \WP_Error( 'eit_entry_email_failed', __( 'Entry notification email could not be sent.', 'elementor-implementation-toolkit' ) );
	}

	private function webhook( array $config, array $context ) {
		$url = esc_url_raw( $config['url'] ?? '' );
		if ( ! wp_http_validate_url( $url ) || wp_parse_url( $url, PHP_URL_USER ) || wp_parse_url( $url, PHP_URL_PASS ) ) {
			return new \WP_Error( 'eit_entry_webhook_url_invalid', __( 'Entry webhook URL failed safe URL validation.', 'elementor-implementation-toolkit' ) );
		}
		$response = wp_safe_remote_post(
			$url,
			[
				'timeout' => 5,
				'redirection' => 0,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body' => wp_json_encode( $this->webhook_payload( $context ) ),
				'data_format' => 'body',
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300
			? [ 'status_code' => $code ]
			: new \WP_Error( 'eit_entry_webhook_failed', sprintf( __( 'Entry webhook returned HTTP %d.', 'elementor-implementation-toolkit' ), $code ) );
	}

	private function webhook_payload( array $context ) {
		return [
			'request_id' => $context['request_id'] ?? '',
			'surface_id' => $context['surface_id'] ?? '',
			'item_id' => $context['item_id'] ?? '',
			'event' => $context['event'] ?? '',
			'status' => $context['status'] ?? '',
		];
	}
}
