<?php
/**
 * Groups module bootstrap.
 *
 * @package Minhaj\Modules\Groups
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups;

use Minhaj\Modules\Groups\Admin\AdminController;
use Minhaj\Modules\Groups\Admin\AjaxSearchController;
use Minhaj\Modules\Groups\Admin\Assets;
use Minhaj\Modules\Groups\Migrations\AddDerivedFieldsToGroups;
use Minhaj\Modules\Groups\Migrations\CreateBatchesTable;
use Minhaj\Modules\Groups\Migrations\CreateCurriculumLevels;
use Minhaj\Modules\Groups\Migrations\CreateGroupCodeCounters;
use Minhaj\Modules\Groups\Migrations\CreateGroupsTables;
use Minhaj\Modules\Groups\Migrations\DefaultSessionDurationTo60;
use Minhaj\Modules\Groups\Migrations\UnifyLanguageColumnType;
use Minhaj\Modules\Groups\Repository\GroupRepository;

defined( 'ABSPATH' ) || exit;

final class Module {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'minhaj_core_register_migrations', array( self::class, 'contribute_migrations' ) );

		$repo = new GroupRepository();
		( new GroupCodeFormatter( $repo ) )->register();

		if ( is_admin() ) {
			$service = new GroupService( $repo );
			( new AdminController( $service, $repo ) )->register();
			( new Assets() )->register();
			( new AjaxSearchController( $repo ) )->register();
		}
	}

	/**
	 * @param array<int, \Minhaj\Migrations\Migration> $migrations
	 * @return array<int, \Minhaj\Migrations\Migration>
	 */
	public static function contribute_migrations( array $migrations ): array {
		$migrations[] = new CreateGroupsTables();
		$migrations[] = new AddDerivedFieldsToGroups();
		$migrations[] = new DefaultSessionDurationTo60();
		$migrations[] = new CreateBatchesTable();
		$migrations[] = new UnifyLanguageColumnType();
		$migrations[] = new CreateGroupCodeCounters();
		$migrations[] = new CreateCurriculumLevels();

		return $migrations;
	}
}
