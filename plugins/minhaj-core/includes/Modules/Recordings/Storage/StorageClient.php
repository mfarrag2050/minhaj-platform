<?php
/**
 * Storage abstraction — spec §6.
 *
 * Thin wrapper over an object store. NO business logic here; the
 * concrete implementation may be S3-compatible, Azure Blob, or a local
 * dev fake. Swapping the provider must not touch RecordingsService.
 *
 * The provider is deliberately not chosen yet (spec §9.4). Until then,
 * the LocalStorageClient carries wp-env tests and the service is
 * written against this interface.
 *
 * @package Minhaj\Modules\Recordings\Storage
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Storage;

defined( 'ABSPATH' ) || exit;

interface StorageClient {

	/**
	 * Write bytes to $object_key. Overwrites if present.
	 *
	 * @param string $object_key   Provider-relative path (e.g. "2026/08/sess-42/mp4").
	 * @param string $body_or_path Raw bytes OR local file path — implementation-specific;
	 *                             the caller passes a path when it already has one on disk.
	 *
	 * @return int Bytes written (as observed by the store).
	 * @throws StorageException On any write failure.
	 */
	public function put( string $object_key, string $body_or_path ): int;

	/**
	 * Return the object contents as a string (or throw). Small objects
	 * only — for large ones use `get_stream` when implemented. The Split
	 * A / verify probe read uses this on a known-small byte range.
	 *
	 * @throws StorageException On any read failure.
	 */
	public function get_bytes( string $object_key, int $offset = 0, ?int $length = null ): string;

	public function delete( string $object_key ): void;

	public function exists( string $object_key ): bool;

	/**
	 * Region where objects land (spec §5.1 G-4 requires this be a
	 * configured EU region — the service verifies it against the
	 * allowed list before insert).
	 */
	public function region(): string;

	/**
	 * Short-lived signed URL for one user, one recording. Non-indexable.
	 * TTL in minutes — the caller passes the value from
	 * `minhaj_recording_view_ttl_minutes` (default 15).
	 *
	 * The URL grants BYTES ONLY — the storage layer is not aware of who
	 * clicked; the caller records `view` in the access log BEFORE
	 * returning the URL.
	 */
	public function presign( string $object_key, int $ttl_minutes ): string;
}
