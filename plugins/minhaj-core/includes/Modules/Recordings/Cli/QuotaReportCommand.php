<?php
/**
 * `wp minhaj recordings quota-report`
 *
 * Spec G-3: **days remaining, not GB**. An admin who reads "68 GB left"
 * has no idea when the recorder will stop; "2 days left" they act on.
 *
 * @package Minhaj\Modules\Recordings\Cli
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Recordings\Cli;

use Minhaj\Modules\Recordings\RecordingsService;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class QuotaReportCommand {

	public function __construct( private readonly RecordingsService $service ) {}

	/**
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$q = $this->service->quota_status();

		if ( isset( $q['error'] ) ) {
			WP_CLI::error( 'Quota lookup failed: ' . (string) $q['error'] );
			return;
		}

		$used = self::human_bytes( (int) $q['used_bytes'] );
		$plan = self::human_bytes( (int) $q['plan_bytes'] );
		$rem  = self::human_bytes( (int) $q['remaining_bytes'] );
		$days = null === $q['remaining_days'] ? '?' : (string) $q['remaining_days'];

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( '  Zoom cloud recording quota (used / plan)  : %s / %s', $used, $plan ) );
		WP_CLI::log( sprintf( '  Remaining                                : %s', $rem ) );
		WP_CLI::log( sprintf( '  Days remaining at observed daily rate    : %s', $days ) );
		WP_CLI::log( '' );

		$plan_i = (int) $q['plan_bytes'];
		if ( $plan_i > 0 && ( (int) $q['used_bytes'] / $plan_i ) >= 0.6 ) {
			WP_CLI::warning( 'At or above 60% of Zoom cloud quota — the recorder will stop before you notice at manual pace.' );
		}
	}

	private static function human_bytes( int $b ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$max   = count( $units ) - 1;
		$i     = 0;
		$v     = (float) $b;
		while ( $v >= 1024 && $i < $max ) {
			$v /= 1024;
			++$i;
		}
		return sprintf( '%.2f %s', $v, $units[ $i ] );
	}
}
