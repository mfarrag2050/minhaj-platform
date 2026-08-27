<?php
/**
 * `wp minhaj people language-coverage <locale>` — answers the S-8 question:
 * how many teachers can genuinely take a group in this language today?
 * "Genuinely" = active status + valid non-expired safeguarding check +
 * declared can_teach_in for the locale. That intersection is what turns a
 * market-opening decision from wishful thinking into a checkable number.
 *
 * @package Minhaj\Modules\People\Cli
 */

declare( strict_types=1 );

namespace Minhaj\Modules\People\Cli;

use Minhaj\Modules\People\PeopleService;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class LanguageCoverageCommand {

	public function __construct( private readonly PeopleService $service ) {}

	/**
	 * ## OPTIONS
	 *
	 * <locale>
	 * : BCP-47 locale (e.g. nl, fr, ar).
	 *
	 * ## EXAMPLES
	 *
	 *     wp minhaj people language-coverage nl
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$locale = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $locale ) {
			WP_CLI::error( 'locale is required — e.g. wp minhaj people language-coverage nl' );
		}

		$coverage = $this->service->language_coverage( $locale );

		WP_CLI::log(
			sprintf(
				'Assignable teachers for locale "%s": %d',
				$coverage['locale'],
				$coverage['assignable']
			)
		);
	}
}
