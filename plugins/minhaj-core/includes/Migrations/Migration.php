<?php
/**
 * Base contract for a schema migration.
 *
 * @package Minhaj\Migrations
 */

declare( strict_types=1 );

namespace Minhaj\Migrations;

defined( 'ABSPATH' ) || exit;

abstract class Migration {

	abstract public function version(): int;

	abstract public function name(): string;

	abstract public function up(): void;
}
