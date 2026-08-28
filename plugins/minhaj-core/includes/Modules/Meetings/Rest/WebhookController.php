<?php
/**
 * M-15 · M-16 · signed webhook boundary.
 *
 * The handler:
 *   1. Reads the raw body (bypassing WP's json_decode of the request).
 *   2. Verifies the signature against MINHAJ_ZOOM_WEBHOOK_SECRET.
 *   3. Handles endpoint.url_validation with the challenge echo.
 *   4. Inserts into `minhaj_zoom_events` under a dedup key and returns
 *      200 — deliberately WITHOUT touching any downstream state so we
 *      stay well under Zoom's 3-second timeout (M-16).
 *
 * The processing runs in wp-cron via MeetingsService::process_pending_events.
 *
 * @package Minhaj\Modules\Meetings\Rest
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Rest;

use Minhaj\Modules\Meetings\MeetingsService;
use Minhaj\Modules\Meetings\Zoom\WebhookVerifier;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class WebhookController {

	public const ROUTE_NAMESPACE = 'minhaj/v1';
	public const ROUTE_PATH      = '/zoom/webhook';

	public function __construct(
		private readonly MeetingsService $service,
		private readonly WebhookVerifier $verifier
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				// M-15 · anonymous by design — signature IS the permission.
				// The permission callback still validates the signature so
				// no unauthenticated request reaches the handler if the
				// signature is missing or wrong.
				'permission_callback' => array( $this, 'permission_callback' ),
			)
		);
	}

	public function permission_callback( WP_REST_Request $request ): bool {
		$verdict = $this->verify( $request );
		return in_array( $verdict['status'], array( WebhookVerifier::VALID, WebhookVerifier::VALIDATION ), true );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$verdict = $this->verify( $request );

		if ( WebhookVerifier::VALIDATION === $verdict['status'] ) {
			return new WP_REST_Response(
				array(
					'plainToken'     => $verdict['plain_token'] ?? '',
					'encryptedToken' => $verdict['encrypted_token'] ?? '',
				),
				200
			);
		}

		if ( WebhookVerifier::VALID !== $verdict['status'] ) {
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$body    = (string) $request->get_body();
		$payload = (array) json_decode( $body, true );
		$event   = (string) ( $payload['event'] ?? '' );
		$ts      = (int) ( $payload['event_ts'] ?? 0 );
		$uuid    = (string) ( $payload['payload']['object']['uuid'] ?? $payload['payload']['object']['id'] ?? '' );

		if ( '' === $event ) {
			return new WP_REST_Response( array( 'error' => 'no_event' ), 400 );
		}

		$dedup = hash( 'sha256', $event . '|' . $uuid . '|' . $ts );

		$id = $this->service->ingest_webhook( $body, $dedup, $event );
		if ( is_wp_error( $id ) ) {
			return new WP_REST_Response( array( 'error' => 'persistence' ), 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'event_id' => $id,
			),
			200
		);
	}

	/**
	 * @return array{status:string, plain_token?:string, encrypted_token?:string}
	 */
	private function verify( WP_REST_Request $request ): array {
		$headers = array();
		foreach ( $request->get_headers() as $name => $values ) {
			$headers[ strtolower( $name ) ] = is_array( $values ) ? (string) $values[0] : (string) $values;
		}
		// WP normalises header names to snake_case; Zoom sends dashed.
		$headers['x-zm-signature']         = $headers['x_zm_signature'] ?? $headers['x-zm-signature'] ?? '';
		$headers['x-zm-request-timestamp'] = $headers['x_zm_request_timestamp'] ?? $headers['x-zm-request-timestamp'] ?? '';

		return $this->verifier->verify( (string) $request->get_body(), $headers );
	}
}
