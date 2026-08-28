<?php
/**
 * Real ZoomClient over Server-to-Server OAuth (M-14).
 *
 * The token is fetched with account_credentials grant, cached in a
 * transient with a TTL five minutes shorter than Zoom's own so we never
 * hand out an expired bearer. The credentials MUST be defined as
 * constants in wp-config.php (M-14: no DB, no repo).
 *
 * This class exposes ONLY the surface MeetingsService needs. There is no
 * kitchen-sink method, and there is no place to write start_url /
 * join_url / password into $wpdb.
 *
 * @package Minhaj\Modules\Meetings\Zoom
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Zoom;

defined( 'ABSPATH' ) || exit;

/*
 * ZoomApiException messages relay $response->get_error_message() /
 * Zoom's own "message" verbatim so operators can diagnose failures.
 * They never reach an HTML response — MeetingsService converts them
 * to WP_Error at the boundary. base64_encode below is the standard
 * HTTP Basic-Auth encoding for the token exchange, not obfuscation.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

final class HttpZoomClient implements ZoomClient {

	private const TRANSIENT_KEY   = 'minhaj_zoom_s2s_token';
	private const OAUTH_URL       = 'https://zoom.us/oauth/token';
	private const API_BASE        = 'https://api.zoom.us/v2';
	private const REQUEST_TIMEOUT = 15;

	/**
	 * @param array<string, mixed> $args
	 */
	public function create_meeting( string $zoom_user_id, array $args ): array {
		return $this->request(
			'POST',
			'/users/' . rawurlencode( $zoom_user_id ) . '/meetings',
			$args
		);
	}

	public function delete_meeting( string $zoom_meeting_id ): void {
		$this->request( 'DELETE', '/meetings/' . rawurlencode( $zoom_meeting_id ) );
	}

	/**
	 * @param array<string, mixed> $registrant
	 * @return array<string, mixed>
	 */
	public function add_registrant( string $zoom_meeting_id, array $registrant ): array {
		return $this->request(
			'POST',
			'/meetings/' . rawurlencode( $zoom_meeting_id ) . '/registrants',
			$registrant
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_meeting( string $zoom_meeting_id ): array {
		return $this->request( 'GET', '/meetings/' . rawurlencode( $zoom_meeting_id ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_account_settings(): array {
		return $this->request( 'GET', '/accounts/me/settings' );
	}

	// ------------------------------------------------------------------ Internal.

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 * @throws ZoomApiException On non-2xx.
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		$token = $this->get_token();

		$args = array(
			'method'  => $method,
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			throw new ZoomApiException( 0, 'network_error', (string) $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = '' === $raw ? array() : (array) json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			throw new ZoomApiException(
				$status,
				(string) ( $json['code'] ?? 'zoom_error' ),
				(string) ( $json['message'] ?? 'Zoom API returned ' . $status )
			);
		}

		return $json;
	}

	private function get_token(): string {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( ! defined( 'MINHAJ_ZOOM_ACCOUNT_ID' )
			|| ! defined( 'MINHAJ_ZOOM_CLIENT_ID' )
			|| ! defined( 'MINHAJ_ZOOM_CLIENT_SECRET' )
		) {
			throw new ZoomApiException(
				0,
				'missing_credentials',
				'MINHAJ_ZOOM_ACCOUNT_ID / _CLIENT_ID / _CLIENT_SECRET must be defined in wp-config.php (M-14).'
			);
		}

		$response = wp_remote_post(
			self::OAUTH_URL . '?grant_type=account_credentials&account_id=' . rawurlencode( (string) MINHAJ_ZOOM_ACCOUNT_ID ),
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode(
						(string) MINHAJ_ZOOM_CLIENT_ID . ':' . (string) MINHAJ_ZOOM_CLIENT_SECRET
					),
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new ZoomApiException( 0, 'oauth_network_error', (string) $response->get_error_message() );
		}

		$body = (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			throw new ZoomApiException( 401, 'oauth_failed', 'Zoom OAuth returned no token.' );
		}

		$token   = (string) $body['access_token'];
		$expires = (int) ( $body['expires_in'] ?? 3600 );

		set_transient( self::TRANSIENT_KEY, $token, max( 60, $expires - 300 ) );

		return $token;
	}
}
