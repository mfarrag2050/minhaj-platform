<?php
/**
 * Plugin runner.
 *
 * @package Minhaj
 */

declare( strict_types=1 );

namespace Minhaj;

use Minhaj\Migrations\Migrator;
use Minhaj\Modules\Groups\Module as GroupsModule;
use Minhaj\Modules\Timetable\Module as TimetableModule;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private bool $booted             = false;
	private bool $modules_registered = false;

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

		$this->register_modules();

		Migrator::instance()->maybe_upgrade();

		/**
		 * Fires once the Minhaj core plugin has finished booting.
		 */
		do_action( 'minhaj_core_booted', $this );
	}

	/**
	 * Wires each module's hooks in one place so both the runtime boot and the
	 * activation hook see the same registration state before the Migrator
	 * resolves the migration list.
	 */
	public function register_modules(): void {
		if ( $this->modules_registered ) {
			return;
		}

		$this->modules_registered = true;

		GroupsModule::register();
		TimetableModule::register();
	}

	private function __construct() {}
}
