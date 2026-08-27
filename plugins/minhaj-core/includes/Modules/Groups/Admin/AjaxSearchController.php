<?php
/**
 * AJAX endpoints powering the admin autocomplete inputs — student and
 * teacher user search, plus group-code search for the transfer form.
 *
 * Every endpoint enforces the same gate as the write actions:
 * `minhaj_manage_groups` capability + a dedicated nonce
 * (`minhaj_groups_search`). Results are role-scoped so admins can't
 * accidentally attach the wrong role to a seat.
 *
 * @package Minhaj\Modules\Groups\Admin
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Admin;

use Minhaj\Modules\Groups\Repository\GroupRepository;
use Minhaj\Modules\Groups\Roles;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

final class AjaxSearchController {

	public const NONCE_ACTION = 'minhaj_groups_search';

	public const ACTION_USERS  = 'minhaj_groups_search_users';
	public const ACTION_GROUPS = 'minhaj_groups_search_groups';

	private const MAX_RESULTS = 15;

	public function __construct( private readonly GroupRepository $repo ) {}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_USERS, array( $this, 'search_users' ) );
		add_action( 'wp_ajax_' . self::ACTION_GROUPS, array( $this, 'search_groups' ) );
	}

	public function search_users(): void {
		if ( ! current_user_can( AdminController::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION );

		$role_key = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( (string) $_GET['role'] ) ) : '';
		$query    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';

		$role = $this->resolve_role( $role_key );
		if ( '' === $role ) {
			wp_send_json_error( array( 'message' => 'invalid_role' ), 400 );
		}

		if ( strlen( $query ) < 2 ) {
			wp_send_json( array() );
		}

		$users = new WP_User_Query(
			array(
				'role'           => $role,
				'search'         => '*' . $query . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'number'         => self::MAX_RESULTS,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				'fields'         => array( 'ID', 'display_name', 'user_login' ),
			)
		);

		$results = array();
		foreach ( (array) $users->get_results() as $u ) {
			$id           = (int) $u->ID;
			$display_name = (string) $u->display_name;
			$login        = (string) $u->user_login;

			$results[] = array(
				'id'    => $id,
				'value' => $display_name,
				'label' => sprintf( '%s (%s) #%d', $display_name, $login, $id ),
			);
		}

		wp_send_json( $results );
	}

	public function search_groups(): void {
		if ( ! current_user_can( AdminController::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION );

		$query   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$exclude = isset( $_GET['exclude'] ) ? absint( wp_unslash( (string) $_GET['exclude'] ) ) : 0;

		if ( '' === $query ) {
			wp_send_json( array() );
		}

		$rows = $this->repo->search_groups_by_code( $query, $exclude, self::MAX_RESULTS );

		$results = array();
		foreach ( $rows as $row ) {
			$results[] = array(
				'id'    => (int) $row['id'],
				'value' => (string) $row['code'],
				'label' => sprintf( '%s — %s #%d', (string) $row['code'], (string) $row['status'], (int) $row['id'] ),
			);
		}

		wp_send_json( $results );
	}

	private function resolve_role( string $key ): string {
		if ( 'student' === $key ) {
			return Roles::student_role();
		}

		if ( 'teacher' === $key ) {
			return Roles::teacher_role();
		}

		return '';
	}
}
