<?php
/**
 * Default subscriber for the `minhaj_group_code_format` filter.
 *
 * Format: {MARKET}-{BATCH_CODE}-{LEVEL}-{SEQ}
 *   e.g. NL-B2609-A1-03
 *
 *   • MARKET     from the batch's `market` column (never typed by the admin).
 *   • BATCH_CODE from the batch's `code` column.
 *   • LEVEL      from the group create args, upper-cased for consistency.
 *   • SEQ        1 + (existing rows in this batch × level) + attempt.
 *
 * Retries on collision: the caller (GroupService::create) walks up to
 * `max_attempts` cycles; each cycle passes an incrementing `attempt` in
 * the args, and this filter uses it to bump the sequence slot rather
 * than reading the DB again. The uq_code on `minhaj_groups.code` is
 * the source of truth, not a pre-flight SELECT.
 *
 * @package Minhaj\Modules\Groups
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups;

use Minhaj\Modules\Groups\Repository\GroupRepository;

defined( 'ABSPATH' ) || exit;

final class GroupCodeFormatter {

	public function __construct( private readonly GroupRepository $repo ) {}

	public function register(): void {
		add_filter( 'minhaj_group_code_format', array( $this, 'format' ), 10, 2 );
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function format( string $existing_code, array $args ): string {
		if ( '' !== $existing_code ) {
			return $existing_code;
		}

		$batch_id = isset( $args['batch_id'] ) ? (int) $args['batch_id'] : 0;
		$level    = strtoupper( trim( (string) ( $args['level'] ?? '' ) ) );
		$attempt  = (int) ( $args['attempt'] ?? 0 );

		if ( 0 === $batch_id || '' === $level ) {
			return '';
		}

		$batch = $this->repo->find_batch( $batch_id );
		if ( null === $batch ) {
			return '';
		}

		$market     = strtoupper( trim( (string) ( $batch['market'] ?? '' ) ) );
		$batch_code = strtoupper( trim( (string) ( $batch['code'] ?? '' ) ) );

		if ( '' === $market || '' === $batch_code ) {
			return '';
		}

		$existing = $this->repo->count_groups_in_batch_level( $batch_id, $args['level'] ?? '' );
		$seq      = 1 + $existing + $attempt;

		return sprintf( '%s-%s-%s-%02d', $market, $batch_code, $level, $seq );
	}
}
