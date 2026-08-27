<?php
/**
 * PSR-4 autoloader for the Minhaj namespace.
 *
 * @package Minhaj
 */

declare( strict_types=1 );

namespace Minhaj;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const NAMESPACE_PREFIX = 'Minhaj\\';

	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	public static function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::NAMESPACE_PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::NAMESPACE_PREFIX ) );
		$path     = MINHAJ_CORE_INCLUDES . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
