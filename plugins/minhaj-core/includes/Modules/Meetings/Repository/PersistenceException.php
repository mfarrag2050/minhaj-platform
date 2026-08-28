<?php
/**
 * Kinds reflect the DB unique keys the module cares about, so the
 * service can translate a specific collision into a specific WP_Error
 * at the outer boundary — never by string-matching $wpdb->last_error
 * twice.
 *
 * @package Minhaj\Modules\Meetings\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Meetings\Repository;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PersistenceException extends RuntimeException {

	public const WRITE_FAILED              = 'write_failed';
	public const DUPLICATE_SESSION         = 'duplicate_session';    // uq_session on session_meetings
	public const DUPLICATE_SESSION_HOST    = 'duplicate_session_host'; // uq_session_host on participants
	public const DUPLICATE_SESSION_SUBJECT = 'duplicate_session_subject'; // uq_session_subject on participants
	public const DUPLICATE_EVENT           = 'duplicate_event';      // uq_dedup on zoom_events

	public function __construct( private readonly string $kind, string $message ) {
		parent::__construct( $message );
	}

	public function kind(): string {
		return $this->kind;
	}
}
