<?php
/**
 * Maps GroupService WP_Error codes and success sentinels to user-facing,
 * translatable strings. Raw error codes must never reach the admin UI.
 *
 * @package Minhaj\Modules\Groups\Admin
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Admin;

defined( 'ABSPATH' ) || exit;

final class ErrorMap {

	/**
	 * @return array{message:string, type:string}
	 */
	public static function resolve( string $code, string $fallback_type = 'error' ): array {
		$map = self::map();

		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}

		return array(
			'message' => __( 'The action could not be completed.', 'minhaj-core' ),
			'type'    => $fallback_type,
		);
	}

	public static function message( string $code ): string {
		return self::resolve( $code )['message'];
	}

	/**
	 * @return array<string, array{message:string, type:string}>
	 */
	private static function map(): array {
		return array(
			// Failure codes emitted by GroupService.
			'group_full'            => array(
				'message' => __( 'The group is full — no free seats.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'group_not_found'       => array(
				'message' => __( 'Group not found.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'group_closed'          => array(
				'message' => __( 'This group is closed to new members.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'member_not_found'      => array(
				'message' => __( 'Membership not found.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'member_not_active'     => array(
				'message' => __( 'This membership is no longer active.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'invalid_transition'    => array(
				'message' => __( 'That status transition is not allowed from the current state.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'invalid_status'        => array(
				'message' => __( 'Unknown target status.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'invalid_type'          => array(
				'message' => __( 'Unknown group type.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'invalid_capacity'      => array(
				'message' => __( 'The capacity values are invalid for this group type.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'invalid_code'          => array(
				'message' => __( 'A group code is required.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'invalid_arg'           => array(
				'message' => __( 'One or more required fields are missing.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'invalid_transfer'      => array(
				'message' => __( 'Cannot transfer to the same group.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'code_taken'            => array(
				'message' => __( 'That group code is already in use.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'not_ready_to_schedule' => array(
				'message' => __( 'Cannot schedule: minimum members not reached.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'missing_actor'         => array(
				'message' => __( 'Missing actor identity — try re-logging in.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'reason_required'       => array(
				'message' => __( 'A reason is required for this action.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'nothing_to_update'     => array(
				'message' => __( 'No changes to apply.', 'minhaj-core' ),
				'type'    => 'warning',
			),
			'persistence_error'     => array(
				'message' => __( 'An unexpected database error occurred. Please try again.', 'minhaj-core' ),
				'type'    => 'error',
			),
			'rejected'              => array(
				'message' => __( 'This action was blocked by another extension.', 'minhaj-core' ),
				'type'    => 'error',
			),

			// Success sentinels emitted by the admin controller after a clean commit.
			'create_ok'             => array(
				'message' => __( 'Group created.', 'minhaj-core' ),
				'type'    => 'success',
			),
			'add_member_ok'         => array(
				'message' => __( 'Member added.', 'minhaj-core' ),
				'type'    => 'success',
			),
			'remove_member_ok'      => array(
				'message' => __( 'Member removed.', 'minhaj-core' ),
				'type'    => 'success',
			),
			'transfer_member_ok'    => array(
				'message' => __( 'Member transferred.', 'minhaj-core' ),
				'type'    => 'success',
			),
			'assign_teacher_ok'     => array(
				'message' => __( 'Teacher assigned.', 'minhaj-core' ),
				'type'    => 'success',
			),
			'transition_ok'         => array(
				'message' => __( 'Group status updated.', 'minhaj-core' ),
				'type'    => 'success',
			),
			'capacity_over_promise' => array(
				'message' => __( 'Group saved. Note: capacity_max above 5 breaks the published promise of 3–5 seats per group.', 'minhaj-core' ),
				'type'    => 'warning',
			),
		);
	}
}
