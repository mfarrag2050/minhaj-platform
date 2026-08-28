<?php
/**
 * Launch languages — the closed domain for both `teaching_language`
 * on `minhaj_groups` and, until a spec says otherwise, `ui_locale`
 * on `minhaj_students`. The source of truth is **قرار 3**: ar, en,
 * fr, es, nl, de. This class exists so any UI that needs the list
 * reads it in one place and cannot drift.
 *
 * Extension: `minhaj_launch_languages` — a filter, not a mutable
 * array. Opening a new market is a **conscious decision** (قرار 8
 * §5), and adding a code here should be its own commit that names
 * the market and links the decision.
 *
 * @package Minhaj\Modules\Groups\Domain
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Domain;

defined( 'ABSPATH' ) || exit;

final class LaunchLanguages {

	/**
	 * @return array<string, string> locale code => native label (upper-cased
	 *                               ISO shown as fallback if the label is
	 *                               translated later).
	 */
	public static function all(): array {
		$base = array(
			'ar' => 'العربية',
			'en' => 'English',
			'fr' => 'Français',
			'es' => 'Español',
			'nl' => 'Nederlands',
			'de' => 'Deutsch',
		);

		/**
		 * Filter · extend the launch language list. Adding a code should
		 * follow قرار 8 §5 (opening a market is a conscious decision) —
		 * do NOT abuse this filter to bypass that.
		 *
		 * @param array<string, string> $base locale => label.
		 */
		$out = apply_filters( 'minhaj_launch_languages', $base );

		return is_array( $out ) ? $out : $base;
	}

	public static function is_valid( string $locale ): bool {
		return isset( self::all()[ $locale ] );
	}
}
