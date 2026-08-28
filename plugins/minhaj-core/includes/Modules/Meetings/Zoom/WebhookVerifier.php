<?php
/**
 * M-15 · every webhook signed or rejected.
 *
 * Zoom signs webhooks with HMAC-SHA256 over `v0:{timestamp}:{body}` using
 * MINHAJ_ZOOM_WEBHOOK_SECRET (constant, M-14). Any request whose
 * `x-zm-signature` header does not match, or whose `x-zm-request-timestamp`
 * is more than five minutes off from server clock, is rejected with 401
 * and no row is inserted.
 *
 * The comparison uses hash_equals — a naive `===` string compare would
 * leak signature bytes via response-time.
 *
 * Zoom also sends `endpoint.url_validation` when the endpoint is first
 * registered. That payload carries a plainText and expects a hash of it
 * back. verify() sees this shape and returns VALIDATION with the payload
 * so the caller can respond appropriately.
 *
 * @package Minhaj\Modules\Meetings\Zoom
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Zoom;

defined( 'ABSPATH' ) || exit;

final class WebhookVerifier {

	public const VALID      = 'valid';
	public const INVALID    = 'invalid';
	public const STALE      = 'stale';
	public const VALIDATION = 'validation';
	public const NO_SECRET  = 'no_secret';

	private const MAX_SKEW_SECONDS = 300;

	public function __construct( private readonly string $secret ) {}

	public static function from_config(): self {
		$secret = defined( 'MINHAJ_ZOOM_WEBHOOK_SECRET' ) ? (string) MINHAJ_ZOOM_WEBHOOK_SECRET : '';
		return new self( $secret );
	}

	/**
	 * @param array<string, string> $headers Lowercased.
	 * @return array{status:string, plain_token?:string, encrypted_token?:string}
	 */
	public function verify( string $raw_body, array $headers ): array {
		if ( '' === $this->secret ) {
			return array( 'status' => self::NO_SECRET );
		}

		$timestamp = (int) ( $headers['x-zm-request-timestamp'] ?? 0 );
		$signature = (string) ( $headers['x-zm-signature'] ?? '' );

		if ( $timestamp <= 0 || '' === $signature ) {
			return array( 'status' => self::INVALID );
		}

		// URL validation payload arrives with the same signature scheme
		// but expects a hashed plainToken echo. Handle before HMAC test
		// because Zoom sends the challenge without a `x-zm-signature`
		// header in some tenants.
		$payload = (array) json_decode( $raw_body, true );
		if ( 'endpoint.url_validation' === ( $payload['event'] ?? '' ) ) {
			$plain_token = (string) ( $payload['payload']['plainToken'] ?? '' );
			$encrypted   = hash_hmac( 'sha256', $plain_token, $this->secret );

			return array(
				'status'          => self::VALIDATION,
				'plain_token'     => $plain_token,
				'encrypted_token' => $encrypted,
			);
		}

		if ( abs( time() - $timestamp ) > self::MAX_SKEW_SECONDS ) {
			return array( 'status' => self::STALE );
		}

		$message  = 'v0:' . $timestamp . ':' . $raw_body;
		$expected = 'v0=' . hash_hmac( 'sha256', $message, $this->secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return array( 'status' => self::INVALID );
		}

		return array( 'status' => self::VALID );
	}
}
