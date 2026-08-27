<?php
/**
 * PHPUnit bootstrap for Minhaj Core unit tests.
 *
 * @package Minhaj\Tests
 */

declare( strict_types=1 );

$minhaj_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $minhaj_autoload ) ) {
	fwrite(
		STDERR,
		"Composer dependencies are not installed. Run `composer install` before executing tests.\n"
	);
	exit( 1 );
}

require_once $minhaj_autoload;

if ( class_exists( \Brain\Monkey::class ) ) {
	\Brain\Monkey\setUp();
}
