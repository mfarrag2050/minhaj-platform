<?php
/**
 * Plugin Name:       Minhaj Core
 * Plugin URI:        https://github.com/mfarrag2050/minhaj-platform
 * Description:       Core platform plugin for Minhaj — student records, groups, timetables and everything the platform is built on. Business modules load from here.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Minhaj Platform
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       minhaj-core
 * Domain Path:       /languages
 *
 * @package Minhaj
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'MINHAJ_CORE_VERSION', '0.1.0' );
define( 'MINHAJ_CORE_FILE', __FILE__ );
define( 'MINHAJ_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'MINHAJ_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'MINHAJ_CORE_INCLUDES', MINHAJ_CORE_PATH . 'includes/' );

require_once MINHAJ_CORE_INCLUDES . 'Autoloader.php';

\Minhaj\Autoloader::register();

register_activation_hook( __FILE__, array( \Minhaj\Activator::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain(
			'minhaj-core',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		\Minhaj\Plugin::instance()->boot();
	}
);
