<?php
/**
 * Minimal WP_Error shim for unit tests that never load WordPress core.
 *
 * @package Minhaj\Tests\Support
 */

declare( strict_types=1 );

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {

		/** @var array<string, array<int, string>> */
		private array $errors = array();

		/** @var array<string, mixed> */
		private array $error_data = array();

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code(): string {
			if ( array() === $this->errors ) {
				return '';
			}

			return (string) array_key_first( $this->errors );
		}

		public function get_error_message( string $code = '' ): string {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_data( string $code = '' ): mixed {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->error_data[ $code ] ?? '';
		}
	}
}
