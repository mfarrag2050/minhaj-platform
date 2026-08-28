<?php
/**
 * Launch languages registry — one list, two consumers.
 *
 * Each locale has two flags:
 *
 *   • ui_available       — a translation exists (i.e. a `.po/.mo` ships
 *                          for the locale). Consumer: parent portal's
 *                          `ui_locale` picker. Static: flips when a new
 *                          translation is shipped.
 *
 *   • teaching_available — a teacher can actually take a group in the
 *                          locale. Consumer: the `teaching_language`
 *                          dropdown at group create. Derived at read
 *                          time from
 *                          `minhaj_group_teaching_language_coverage` —
 *                          not stored, always fresh.
 *
 * قرار 3 says the parent's UI language does NOT need to match the
 * teacher's bridge language, so the two consumers use two subsets of
 * the same list. The list is one and only one; opening a new market
 * is a conscious decision (قرار 8 §5) via the
 * `minhaj_launch_languages` filter.
 *
 * @package Minhaj\Modules\Groups\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Domain;

defined( 'ABSPATH' ) || exit;

final class LaunchLanguages {

	/**
	 * The registry. Extended via `minhaj_launch_languages`.
	 *
	 * @return array<string, array{label: string, ui_available: bool}>
	 */
	public static function all(): array {
		$base = array(
			'ar' => array(
				'label'        => 'العربية',
				'ui_available' => false,
			),
			'en' => array(
				'label'        => 'English',
				'ui_available' => false,
			),
			'fr' => array(
				'label'        => 'Français',
				'ui_available' => false,
			),
			'es' => array(
				'label'        => 'Español',
				'ui_available' => false,
			),
			'nl' => array(
				'label'        => 'Nederlands',
				'ui_available' => false,
			),
			'de' => array(
				'label'        => 'Deutsch',
				'ui_available' => false,
			),
		);

		/**
		 * Filter · extend or amend the launch language registry. Each
		 * entry is `[label, ui_available]`. `teaching_available` is
		 * NOT part of the stored value — it is derived from coverage.
		 * Adding a locale should follow قرار 8 §5.
		 *
		 * @param array<string, array{label:string, ui_available:bool}> $base
		 */
		$out = apply_filters( 'minhaj_launch_languages', $base );

		return is_array( $out ) ? $out : $base;
	}

	public static function is_valid( string $locale ): bool {
		return isset( self::all()[ $locale ] );
	}

	/**
	 * Locales that have a shipped UI translation. The parent portal's
	 * `ui_locale` picker reads from this — showing a locale with no
	 * translation would leave the parent staring at English fallback.
	 *
	 * @return array<string, string> locale => label
	 */
	public static function for_ui(): array {
		$out = array();
		foreach ( self::all() as $locale => $entry ) {
			if ( ! empty( $entry['ui_available'] ) ) {
				$out[ $locale ] = (string) $entry['label'];
			}
		}
		return $out;
	}

	/**
	 * Locales that have at least one assignable teacher. Reads live
	 * coverage via the filter the People module answers — cached
	 * within a single request (small array) but not across requests.
	 *
	 * The group create form uses this to decide which teaching
	 * languages to OFFER; the S-8 gate in GroupService still refuses
	 * a zero-coverage locale on save, so this method is only an
	 * ergonomics filter, not a security boundary.
	 *
	 * @return array<string, array{label:string, coverage:int}>
	 */
	public static function for_teaching(): array {
		$out = array();
		foreach ( self::all() as $locale => $entry ) {
			$count = apply_filters( 'minhaj_group_teaching_language_coverage', null, $locale );
			if ( null === $count || (int) $count < 1 ) {
				continue;
			}
			$out[ $locale ] = array(
				'label'    => (string) $entry['label'],
				'coverage' => (int) $count,
			);
		}
		return $out;
	}
}
