<?php
/**
 * Turns a raw `minhaj_group_audit` row into a translated, human-readable
 * sentence for the single-group admin view. The raw JSON payload is still
 * available for admins who need the exact record, hidden behind a
 * "Details" toggle in the audit column.
 *
 * @package Minhaj\Modules\Groups\Admin
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Groups\Admin;

defined( 'ABSPATH' ) || exit;

final class AuditFormatter {

	/**
	 * @param array<string, mixed> $row  A `minhaj_group_audit` row (assoc).
	 * @return string HTML-safe sentence describing the entry.
	 */
	public static function sentence( array $row ): string {
		$action  = (string) ( $row['action'] ?? '' );
		$actor   = self::actor_label( (int) ( $row['actor_user_id'] ?? 0 ) );
		$payload = self::decode_payload( $row );

		switch ( $action ) {
			case 'group.created':
				return sprintf(
					/* translators: 1: actor name, 2: group code, 3: type, 4: min capacity, 5: max capacity. */
					esc_html__( '%1$s created group %2$s (type %3$s, capacity %4$d–%5$d).', 'minhaj-core' ),
					esc_html( $actor ),
					esc_html( (string) ( $payload['code'] ?? '' ) ),
					esc_html( (string) ( $payload['type'] ?? '' ) ),
					(int) ( $payload['capacity_min'] ?? 0 ),
					(int) ( $payload['capacity_max'] ?? 0 )
				);

			case 'group.status_changed':
				return sprintf(
					/* translators: 1: actor, 2: previous status, 3: new status, 4: reason. */
					esc_html__( '%1$s moved the group from %2$s to %3$s. Reason: %4$s', 'minhaj-core' ),
					esc_html( $actor ),
					esc_html( (string) ( $payload['from'] ?? '' ) ),
					esc_html( (string) ( $payload['to'] ?? '' ) ),
					esc_html( (string) ( $payload['reason'] ?? '' ) )
				);

			case 'group.updated':
				$fields = array_map( 'strval', array_keys( $payload ) );
				return sprintf(
					/* translators: 1: actor, 2: comma-separated field names. */
					esc_html__( '%1$s updated the group (%2$s).', 'minhaj-core' ),
					esc_html( $actor ),
					esc_html( implode( ', ', $fields ) )
				);

			case 'group.teacher_assigned':
				return sprintf(
					/* translators: 1: actor, 2: teacher, 3: reason. */
					esc_html__( '%1$s assigned teacher %2$s. Reason: %3$s', 'minhaj-core' ),
					esc_html( $actor ),
					esc_html( self::user_label( (int) ( $payload['to_teacher_id'] ?? 0 ) ) ),
					esc_html( (string) ( $payload['reason'] ?? '' ) )
				);

			case 'group.teacher_changed':
				return sprintf(
					/* translators: 1: actor, 2: previous teacher, 3: new teacher, 4: reason. */
					esc_html__( '%1$s changed the teacher from %2$s to %3$s. Reason: %4$s', 'minhaj-core' ),
					esc_html( $actor ),
					esc_html( self::user_label( (int) ( $payload['from_teacher_id'] ?? 0 ) ) ),
					esc_html( self::user_label( (int) ( $payload['to_teacher_id'] ?? 0 ) ) ),
					esc_html( (string) ( $payload['reason'] ?? '' ) )
				);

			case 'member.added':
				return sprintf(
					/* translators: 1: actor, 2: student, 3: seat number. */
					esc_html__( '%1$s added student %2$s to seat %3$d.', 'minhaj-core' ),
					esc_html( $actor ),
					esc_html( self::user_label( (int) ( $payload['student_id'] ?? 0 ) ) ),
					(int) ( $payload['seat_index'] ?? 0 )
				);

			case 'member.removed':
				return sprintf(
					/* translators: 1: actor, 2: student, 3: reason. */
					esc_html__( '%1$s removed student %2$s. Reason: %3$s', 'minhaj-core' ),
					esc_html( $actor ),
					esc_html( self::user_label( (int) ( $payload['student_id'] ?? 0 ) ) ),
					esc_html( (string) ( $payload['reason'] ?? '' ) )
				);

			case 'member.transferred_out':
				return sprintf(
					/* translators: 1: actor, 2: student, 3: target group id, 4: reason. */
					esc_html__( '%1$s transferred student %2$s out to group #%3$d. Reason: %4$s', 'minhaj-core' ),
					esc_html( $actor ),
					esc_html( self::user_label( (int) ( $payload['student_id'] ?? 0 ) ) ),
					(int) ( $payload['to_group_id'] ?? 0 ),
					esc_html( (string) ( $payload['reason'] ?? '' ) )
				);

			case 'member.transferred_in':
				return sprintf(
					/* translators: 1: actor, 2: student, 3: source group id, 4: reason. */
					esc_html__( '%1$s transferred student %2$s in from group #%3$d. Reason: %4$s', 'minhaj-core' ),
					esc_html( $actor ),
					esc_html( self::user_label( (int) ( $payload['student_id'] ?? 0 ) ) ),
					(int) ( $payload['from_group_id'] ?? 0 ),
					esc_html( (string) ( $payload['reason'] ?? '' ) )
				);

			default:
				return sprintf(
					/* translators: 1: actor, 2: raw action code. */
					esc_html__( '%1$s performed %2$s.', 'minhaj-core' ),
					esc_html( $actor ),
					esc_html( $action )
				);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function decode_payload( array $row ): array {
		$raw = (string) ( $row['payload_json'] ?? '' );
		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private static function actor_label( int $id ): string {
		if ( $id <= 0 ) {
			return __( 'system', 'minhaj-core' );
		}

		$user = get_user_by( 'id', $id );

		return $user ? (string) $user->display_name : sprintf( '#%d', $id );
	}

	private static function user_label( int $id ): string {
		if ( $id <= 0 ) {
			return '#0';
		}

		$user = get_user_by( 'id', $id );
		if ( ! $user ) {
			return sprintf( '#%d', $id );
		}

		return sprintf( '%s (#%d)', (string) $user->display_name, $id );
	}
}
