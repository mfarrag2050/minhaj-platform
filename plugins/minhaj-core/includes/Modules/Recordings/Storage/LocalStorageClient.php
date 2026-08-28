<?php
/**
 * Local-disk implementation of StorageClient. Exists ONLY so wp-env
 * integration tests can prove the guardrails without a cloud provider.
 * Its `region()` returns the value passed in — tests pass an EU code
 * so the region gate (G-4) is exercised.
 *
 * `presign` returns a file:// URL with an HMAC-signed expiry appended
 * as a query string; the fake host verifier (in tests) checks the
 * signature. Not meant for production.
 *
 * NEVER used in production — production wires a real S3/Blob client
 * via the `minhaj_storage_client` filter.
 *
 * @package Minhaj\Modules\Recordings\Storage
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Storage;

defined( 'ABSPATH' ) || exit;

// Test-only fake — deliberately uses native filesystem calls (WP_Filesystem
// would drag network/FTP concerns into a local sandbox). The StorageException
// messages relay dev-facing diagnostics, not user output; escaping them would
// corrupt log lines.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class LocalStorageClient implements StorageClient {

	public function __construct(
		private readonly string $root,
		private readonly string $region = 'eu-central-1',
		private readonly string $sign_secret = 'local-dev-secret'
	) {
		if ( ! is_dir( $this->root ) && ! wp_mkdir_p( $this->root ) ) {
			throw new StorageException( 'Cannot create local storage root: ' . $this->root );
		}
	}

	public function put( string $object_key, string $body_or_path ): int {
		$abs = $this->absolute( $object_key );
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new StorageException( 'Cannot create directory: ' . $dir );
		}

		// If body_or_path is an existing file path, copy it; else treat as bytes.
		if ( is_file( $body_or_path ) ) {
			if ( ! copy( $body_or_path, $abs ) ) {
				throw new StorageException( 'Copy failed to ' . $abs );
			}
		} elseif ( false === file_put_contents( $abs, $body_or_path, LOCK_EX ) ) {
				throw new StorageException( 'Write failed to ' . $abs );
		}

		$size = filesize( $abs );
		if ( false === $size ) {
			throw new StorageException( 'Stat failed after write: ' . $abs );
		}
		return (int) $size;
	}

	public function get_bytes( string $object_key, int $offset = 0, ?int $length = null ): string {
		$abs = $this->absolute( $object_key );
		if ( ! is_file( $abs ) ) {
			throw new StorageException( 'Object not found: ' . $object_key );
		}
		$fh = fopen( $abs, 'rb' );
		if ( false === $fh ) {
			throw new StorageException( 'Cannot open ' . $abs );
		}
		try {
			if ( $offset > 0 ) {
				fseek( $fh, $offset );
			}
			$out = null === $length
				? stream_get_contents( $fh )
				: fread( $fh, $length );
			if ( false === $out ) {
				throw new StorageException( 'Read failed on ' . $abs );
			}
			return (string) $out;
		} finally {
			fclose( $fh );
		}
	}

	public function delete( string $object_key ): void {
		$abs = $this->absolute( $object_key );
		if ( is_file( $abs ) ) {
			wp_delete_file( $abs );
			if ( is_file( $abs ) ) {
				throw new StorageException( 'Delete failed on ' . $abs );
			}
		}
	}

	public function exists( string $object_key ): bool {
		return is_file( $this->absolute( $object_key ) );
	}

	public function region(): string {
		return $this->region;
	}

	public function presign( string $object_key, int $ttl_minutes ): string {
		$expires = time() + ( max( 1, $ttl_minutes ) * 60 );
		$payload = $object_key . '|' . $expires;
		$sig     = hash_hmac( 'sha256', $payload, $this->sign_secret );
		return 'file://' . $this->absolute( $object_key ) . '?expires=' . $expires . '&sig=' . $sig;
	}

	private function absolute( string $object_key ): string {
		// Prevent path traversal — no ".." allowed.
		if ( str_contains( $object_key, '..' ) ) {
			throw new StorageException( 'Illegal object_key: ' . $object_key );
		}
		return rtrim( $this->root, '/' ) . '/' . ltrim( $object_key, '/' );
	}
}
