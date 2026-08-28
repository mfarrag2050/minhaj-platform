<?php
/**
 * spec-zoom-sessions-v1 M-15 — every webhook signed or rejected.
 *
 * @package Minhaj\Tests\Unit\Modules\Meetings
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Meetings;

use Minhaj\Modules\Meetings\Zoom\WebhookVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass( WebhookVerifier::class )]
final class WebhookVerifierTest extends TestCase {

	private const SECRET = 'test-webhook-secret';

	#[TestDox( 'M-15 · a well-signed webhook returns VALID' )]
	public function test_valid_signature(): void {
		$body      = '{"event":"meeting.started","payload":{"object":{"id":"m-1","uuid":"u-1"}},"event_ts":1725000000}';
		$timestamp = time();
		$signature = 'v0=' . hash_hmac( 'sha256', 'v0:' . $timestamp . ':' . $body, self::SECRET );

		$v      = new WebhookVerifier( self::SECRET );
		$result = $v->verify(
			$body,
			array(
				'x-zm-signature'         => $signature,
				'x-zm-request-timestamp' => (string) $timestamp,
			)
		);

		$this->assertSame( WebhookVerifier::VALID, $result['status'] );
	}

	#[TestDox( 'M-15 · a tampered body flips the signature — verify returns INVALID' )]
	public function test_tampered_body(): void {
		$body      = '{"event":"meeting.started"}';
		$timestamp = time();
		$signature = 'v0=' . hash_hmac( 'sha256', 'v0:' . $timestamp . ':' . $body, self::SECRET );

		$v      = new WebhookVerifier( self::SECRET );
		$result = $v->verify(
			'{"event":"meeting.ended"}',
			array(
				'x-zm-signature'         => $signature,
				'x-zm-request-timestamp' => (string) $timestamp,
			)
		);

		$this->assertSame( WebhookVerifier::INVALID, $result['status'] );
	}

	#[TestDox( 'M-15 · timestamp older than 5 minutes returns STALE (protects against replay)' )]
	public function test_stale_timestamp(): void {
		$body      = '{"event":"meeting.started"}';
		$timestamp = time() - 6000;
		$signature = 'v0=' . hash_hmac( 'sha256', 'v0:' . $timestamp . ':' . $body, self::SECRET );

		$v      = new WebhookVerifier( self::SECRET );
		$result = $v->verify(
			$body,
			array(
				'x-zm-signature'         => $signature,
				'x-zm-request-timestamp' => (string) $timestamp,
			)
		);

		$this->assertSame( WebhookVerifier::STALE, $result['status'] );
	}

	#[TestDox( 'M-15 · endpoint.url_validation payload receives an echoed HMAC of the plainToken' )]
	public function test_url_validation(): void {
		$plain_token = 'plaintoken-abc-123';
		$body        = wp_json_encode(
			array(
				'event'   => 'endpoint.url_validation',
				'payload' => array( 'plainToken' => $plain_token ),
			)
		);

		$v      = new WebhookVerifier( self::SECRET );
		$result = $v->verify(
			(string) $body,
			array(
				'x-zm-signature'         => 'not-checked-for-this-path',
				'x-zm-request-timestamp' => (string) time(),
			)
		);

		$this->assertSame( WebhookVerifier::VALIDATION, $result['status'] );
		$this->assertSame( $plain_token, $result['plain_token'] );
		$this->assertSame( hash_hmac( 'sha256', $plain_token, self::SECRET ), $result['encrypted_token'] );
	}

	#[TestDox( 'M-15 · a missing secret produces NO_SECRET, not a false positive' )]
	public function test_missing_secret(): void {
		$v      = new WebhookVerifier( '' );
		$result = $v->verify( '{}', array( 'x-zm-signature' => 'x', 'x-zm-request-timestamp' => (string) time() ) );

		$this->assertSame( WebhookVerifier::NO_SECRET, $result['status'] );
	}
}
