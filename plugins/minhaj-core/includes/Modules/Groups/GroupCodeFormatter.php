<?php
/**
 * Default subscriber for the `minhaj_group_code_format` filter.
 *
 * Format: {MARKET}-{BATCH_CODE}-{LEVEL}-{SEQ}
 *   e.g. NL-B2609-A1-03
 *
 *   • MARKET     from the batch's `market` column (never typed by admin).
 *   • BATCH_CODE from the batch's `code` column.
 *   • LEVEL      from the create args, upper-cased for consistency.
 *   • SEQ        `reserve_next_seq(batch_id, level)` — a persistent
 *                counter that never rewinds. Deleting or cancelling
 *                a group does NOT free its code.
 *
 * The counter is bumped once per invocation (once per attempt); each
 * attempt reserves a fresh slot, so retries after a UNIQUE-index race
 * do not spin on the same value.
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

		$batch_id  = isset( $args['batch_id'] ) ? (int) $args['batch_id'] : 0;
		$raw_level = trim( (string) ( $args['level'] ?? '' ) );
		$level     = strtoupper( $raw_level );

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

		// Persistent counter — never rewinds, even if the caller's
		// transaction rolls back. Each call burns a fresh slot; the
		// retry loop in GroupService::create relies on this to avoid
		// spinning on the same seq after a UNIQUE-index race.
		$seq = $this->repo->reserve_next_seq( $batch_id, $level );

		return sprintf( '%s-%s-%s-%02d', $market, $batch_code, $level, $seq );
	}
}
