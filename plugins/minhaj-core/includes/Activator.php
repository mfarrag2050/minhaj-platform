<?php
/**
 * Plugin activation handler.
 *
 * @package Minhaj
 */

declare( strict_types=1 );

namespace Minhaj;

use Minhaj\Migrations\Migrator;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		// Modules must contribute their migrations before the Migrator collects them.
		Plugin::instance()->register_modules();

		Migrator::instance()->install();
	}
}
