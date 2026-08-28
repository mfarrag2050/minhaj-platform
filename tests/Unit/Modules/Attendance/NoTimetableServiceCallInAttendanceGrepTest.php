<?php
/**
 * spec-attendance-v1 R-6 · static enforcement.
 *
 * The Attendance module MUST NOT call TimetableService directly. The
 * schedule is moved by Timetable, not by attendance rows — R-2 (of the
 * decision that shapes the whole spec) is "the schedule is not moved
 * by attendance." R-7 no_show is not an exception in the code: we
 * emit `minhaj_session_no_show` and let a listener in the Timetable
 * module react, in its own file.
 *
 * The test greps every file under the Attendance module for a call to
 * TimetableService and fails if it finds any. PHPDoc callouts and
 * comments do not count — token-based comment stripping means we
 * check runtime code only.
 *
 * @package Minhaj\Tests\Unit\Modules\Attendance
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Attendance;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class NoTimetableServiceCallInAttendanceGrepTest extends TestCase {

	#[TestDox( 'spec-attendance-v1 R-6: no file under plugins/minhaj-core/includes/Modules/Attendance references TimetableService in runtime code' )]
	public function test_no_timetable_service_call_in_attendance(): void {
		$root = dirname( __DIR__, 4 ) . '/plugins/minhaj-core/includes/Modules/Attendance';
		$this->assertDirectoryExists( $root );

		$iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );

		$offenders = array();
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$source   = (string) file_get_contents( $file->getPathname() );
			$stripped = self::strip_php_comments( $source );

			if ( str_contains( $stripped, 'TimetableService' ) ) {
				$offenders[] = $file->getPathname();
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'TimetableService must not appear in the Attendance module — the schedule is not moved by attendance (R-6).'
		);
	}

	private static function strip_php_comments( string $source ): string {
		$out    = '';
		$tokens = token_get_all( $source );

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				[ $id, $text ] = $token;
				if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
					continue;
				}
				$out .= $text;
			} else {
				$out .= $token;
			}
		}

		return $out;
	}
}
