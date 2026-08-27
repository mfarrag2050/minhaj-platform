<?php
/**
 * Plugin runner.
 *
 * @package Minhaj
 */

declare( strict_types=1 );

namespace Minhaj;

use Minhaj\Migrations\Migrator;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		Migrator::instance()->maybe_upgrade();

		/**
		 * Fires once the Minhaj core plugin has finished booting.
		 *
		 * Modules under `includes/modules/` hook in here to register themselves.
		 */
		do_action( 'minhaj_core_booted', $this );
	}

	private function __construct() {}
}
