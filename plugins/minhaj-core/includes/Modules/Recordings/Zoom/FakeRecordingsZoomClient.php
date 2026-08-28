<?php
/**
 * Test-only fake · lets the whole recordings pipeline run in wp-env
 * without a Zoom account with cloud recording enabled. Behaviour is
 * scripted per-instance:
 *
 *   • `set_download_bytes( $file_id, string $bytes )` — canned bytes
 *     returned by `download()`. Optionally lie about size to exercise
 *     G-1's triple-verification.
 *   • `set_download_error( $file_id, RecordingsZoomException $e )` —
 *     force the download to throw.
 *
 * The class records every call in `$calls` so tests assert what did or
 * did NOT reach Zoom (e.g. G-1: delete MUST NOT fire when verification
 * fails).
 *
 * @package Minhaj\Modules\Recordings\Zoom
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Zoom;

defined( 'ABSPATH' ) || exit;

// Test-only fake — canned bytes to a test path; parse_url on a fixed
// synthetic URL shape (fake-zoom/download/<file_id>); exception messages
// are dev-facing diagnostics.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class FakeRecordingsZoomClient implements RecordingsZoomClient {

	/** @var array<int, array<string, mixed>> */
	public array $calls = array();

	/** @var array<string, string> file_id => bytes */
	private array $bodies = array();

	/** @var array<string, RecordingsZoomException> */
	private array $errors = array();

	/** @var array<string, int> file_id => reported bytes (differs from actual for triple-verify tests) */
	private array $reported_bytes = array();

	/** @var array<string, mixed> */
	private array $quota;

	/** @var array<int, array<string, mixed>> */
	private array $orphans = array();

	/**
	 * @param array{used_bytes?:int, plan_bytes?:int, daily_bytes?:int} $quota
	 */
	public function __construct( array $quota = array() ) {
		$this->quota = array_merge(
			array(
				'used_bytes'  => 0,
				'plan_bytes'  => 100 * 1024 * 1024 * 1024,
				'daily_bytes' => 21 * 1024 * 1024 * 1024,
			),
			$quota
		);
	}

	public function set_download_bytes( string $file_id, string $bytes, ?int $reported_bytes = null ): void {
		$this->bodies[ $file_id ]         = $bytes;
		$this->reported_bytes[ $file_id ] = null === $reported_bytes ? strlen( $bytes ) : $reported_bytes;
	}

	public function set_download_error( string $file_id, RecordingsZoomException $e ): void {
		$this->errors[ $file_id ] = $e;
	}

	/** @param array<int, array<string, mixed>> $rows */
	public function set_orphans( array $rows ): void {
		$this->orphans = $rows;
	}

	public function set_quota( array $q ): void {
		$this->quota = array_merge( $this->quota, $q );
	}

	public function download( string $download_url, string $download_token, string $target_path ): array {
		// Extract file_id from a synthetic download URL of the form
		// https://fake-zoom/download/<file_id> that the test seeds via
		// register_from_webhook. Real webhooks would carry a fresh URL
		// each time — we don't need to model that here.
		$parsed_path = wp_parse_url( $download_url, PHP_URL_PATH );
		$file_id     = basename( is_string( $parsed_path ) ? $parsed_path : '' );

		$this->calls[] = array(
			'method'     => 'download',
			'file_id'    => $file_id,
			'target'     => $target_path,
			'token_hash' => substr( sha1( $download_token ), 0, 8 ),
		);

		if ( isset( $this->errors[ $file_id ] ) ) {
			throw $this->errors[ $file_id ];
		}

		if ( ! isset( $this->bodies[ $file_id ] ) ) {
			throw new RecordingsZoomException( 'Fake · no bytes seeded for file_id=' . $file_id );
		}

		$bytes = $this->bodies[ $file_id ];
		if ( false === file_put_contents( $target_path, $bytes ) ) {
			throw new RecordingsZoomException( 'Fake · cannot write ' . $target_path );
		}

		return array(
			// The "reported" bytes MAY differ from actual bytes on disk
			// — this is how we exercise G-1 (triple verify).
			'bytes'           => $this->reported_bytes[ $file_id ] ?? strlen( $bytes ),
			'checksum_sha256' => hash( 'sha256', $bytes ),
		);
	}

	public function delete_recording_file( string $meeting_uuid, string $file_id ): void {
		$this->calls[] = array(
			'method'       => 'delete_recording_file',
			'meeting_uuid' => $meeting_uuid,
			'file_id'      => $file_id,
		);
	}

	public function quota(): array {
		$this->calls[] = array( 'method' => 'quota' );
		return $this->quota;
	}

	public function list_cloud_recordings( int $from_days = 30 ): array {
		$this->calls[] = array(
			'method'    => 'list_cloud_recordings',
			'from_days' => $from_days,
		);
		return $this->orphans;
	}

	public function delete_calls_for( string $file_id ): int {
		$n = 0;
		foreach ( $this->calls as $c ) {
			if ( 'delete_recording_file' === ( $c['method'] ?? '' ) && ( $c['file_id'] ?? '' ) === $file_id ) {
				++$n;
			}
		}
		return $n;
	}
}
