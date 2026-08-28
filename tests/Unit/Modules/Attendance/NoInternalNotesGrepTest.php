<?php
/**
 * spec-attendance-v1 R-9 · mirror principle · AC-12.
 *
 * The Attendance module MUST NOT introduce a hidden note column or
 * property. Every free-text field carries the name `note_visible` so
 * a reader knows immediately: this text will be shown to the parent.
 * A column named `notes_internal` (or `internal_note`, or anything
 * that reads as "the private staff opinion of the child") is exactly
 * the failure mode R-9 exists to prevent.
 *
 * @package Minhaj\Tests\Unit\Modules\Attendance
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Modules\Attendance;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class NoInternalNotesGrepTest extends TestCase {

	#[TestDox( 'spec-attendance-v1 R-9 (AC-12): no notes_internal / internal_note anywhere in the Attendance module' )]
	public function test_no_internal_notes_in_attendance(): void {
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
			if ( preg_match( '/(notes_internal|internal_note|internal_notes|hidden_note|private_note)/', $stripped ) ) {
				$offenders[] = $file->getPathname();
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'A hidden note field is R-9. Use note_visible.'
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
