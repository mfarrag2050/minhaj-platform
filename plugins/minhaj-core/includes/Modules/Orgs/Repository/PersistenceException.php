<?php
/**
 * Persistence-layer errors for the Orgs module. Kinds surface the DB
 * constraint that actually rejected the write so the service can translate
 * them to a clean WP_Error at the outer boundary without inspecting error
 * strings twice.
 *
 * @package Minhaj\Modules\Orgs\Repository
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs\Repository;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PersistenceException extends RuntimeException {

	public const WRITE_FAILED            = 'write_failed';
	public const DUPLICATE_ORG_CODE      = 'duplicate_org_code';
	public const DUPLICATE_TOKEN         = 'duplicate_token';
	public const DUPLICATE_ACTIVE_MEMBER = 'duplicate_active_member';

	public function __construct( private readonly string $kind, string $message ) {
		parent::__construct( $message );
	}

	public function kind(): string {
		return $this->kind;
	}
}
