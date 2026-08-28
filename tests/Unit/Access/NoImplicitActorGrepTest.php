<?php
/**
 * §8-8 · Static enforcement of spec-access-v1 A-3.
 *
 * The Access module MUST NOT call `get_current_user_id()` — that function
 * returns 0 in cron, CLI, and Zoom webhooks, which is precisely where an
 * access decision most needs a real actor. Every call must pass the id
 * explicitly. This test greps the module's source and fails if the string
 * appears anywhere, so the rule cannot regress into a "quick fix".
 *
 * @package Minhaj\Tests\Unit\Access
 */

declare( strict_types=1 );

namespace Minhaj\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class NoImplicitActorGrepTest extends TestCase {

	#[TestDox( 'spec-access-v1 §8-8: no file in plugins/minhaj-core/includes/Access calls get_current_user_id()' )]
	public function test_no_get_current_user_id_in_access_module(): void {
		$root = dirname( __DIR__, 3 ) . '/plugins/minhaj-core/includes/Access';
		$this->assertDirectoryExists( $root );

		$iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );

		$offenders = array();
		foreach ( $iter as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			// Strip PHPDoc + line comments so a documentation callout ("don't
			// call get_current_user_id() here") does not count as a violation.
			$stripped = self::strip_php_comments( $contents );

			if ( str_contains( $stripped, 'get_current_user_id(' ) ) {
				$offenders[] = $file->getPathname();
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'get_current_user_id() must not appear in the Access module — pass actor_user_id explicitly.'
		);
	}

	/**
	 * Tokenise the file and drop every T_COMMENT + T_DOC_COMMENT — leaves
	 * runtime code only, so a doc callout that *names* the forbidden function
	 * is not conflated with an actual call site.
	 */
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
