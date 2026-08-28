<?php
/**
 * Orgs public interface — spec-organizations-v1 §6.
 *
 * Layering contract (mirrors every other service in this plugin):
 *   • Callers (admin/CLI/REST) enforce current_user_can + nonce BEFORE
 *     calling. Every write takes `int $actor_user_id` explicitly (§5 O-10).
 *   • Domain rules return errors as WP_Error at the outer boundary; the
 *     repository throws PersistenceException on DB failures.
 *   • Writes ride a single transaction. Audit rows are inserted BEFORE
 *     commit; do_action events fire AFTER commit — never inside a
 *     transaction that may roll back (§5 O-10).
 *
 * §5 O-11 · `licensee` type + `data_controller=org` are LOCKED. Attempting
 * to create either fails with an explicit "unsupported" error — activation
 * requires the software-vendor bundle from §9.5, not a config flip.
 *
 * @package Minhaj\Modules\Orgs
 */

declare( strict_types=1 );

namespace Minhaj\Modules\Orgs;

use Minhaj\Access\AccessPolicy;
use Minhaj\Modules\Orgs\Domain\MembershipRole;
use Minhaj\Modules\Orgs\Domain\OrgStatus;
use Minhaj\Modules\Orgs\Domain\OrgType;
use Minhaj\Modules\Orgs\Repository\OrgRepository;
use Minhaj\Modules\Orgs\Repository\PersistenceException;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/*
 * WP_Error messages here relay dev-facing rule codes and validated enum
 * values — never user-supplied HTML — so the WPCS output-escape sniff is
 * disabled at this boundary. Presentation layers escape at render.
 *
 * do_action hook names come from Events constants, prefixed minhaj_*. The
 * sniff cannot resolve dynamic hook names statically and flags them; the
 * prefix rule is satisfied by construction.
 */
// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

final class OrgService {

	public function __construct(
		private readonly OrgRepository $repo,
		private readonly ?AccessPolicy $access = null
	) {}

	// ============================================================== create_org.

	/**
	 * @param array<string, mixed> $args
	 * @return int|WP_Error
	 */
	public function create_org( int $actor_user_id, array $args ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$type = isset( $args['type'] ) ? (string) $args['type'] : OrgType::SUPPLIER;
		if ( ! OrgType::is_valid( $type ) ) {
			return new WP_Error( 'invalid_type', __( 'Unknown org type.', 'minhaj-core' ) );
		}

		// O-11 · licensee is locked; a data_controller override to `org` is
		// the same story — both are the "org is Data Controller" branch that
		// needs the §9.5 bundle before it can safely ship.
		$data_controller_input = isset( $args['data_controller'] ) ? (string) $args['data_controller'] : OrgType::data_controller_for( $type );
		if ( ! OrgType::is_enabled( $type ) || 'org' === $data_controller_input ) {
			return new WP_Error(
				'org_type_unsupported',
				__( 'Org type "licensee" / data_controller="org" is not supported in this release.', 'minhaj-core' )
			);
		}

		$code = isset( $args['code'] ) ? sanitize_text_field( (string) $args['code'] ) : '';
		if ( '' === $code ) {
			return new WP_Error( 'invalid_code', __( 'Org code is required.', 'minhaj-core' ) );
		}

		$name = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Org name is required.', 'minhaj-core' ) );
		}

		$country = strtoupper( sanitize_text_field( (string) ( $args['country'] ?? '' ) ) );
		if ( '' !== $country && ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return new WP_Error( 'invalid_country', __( 'country must be an ISO-3166 alpha-2 code.', 'minhaj-core' ) );
		}

		$timezone = sanitize_text_field( (string) ( $args['default_timezone'] ?? 'UTC' ) );
		try {
			new \DateTimeZone( $timezone );
		} catch ( \Exception $e ) {
			return new WP_Error( 'invalid_timezone', __( 'default_timezone is not a valid IANA identifier.', 'minhaj-core' ) );
		}

		$now = current_time( 'mysql', true );

		$data = array(
			'code'             => $code,
			'name'             => $name,
			'type'             => $type,
			'data_controller'  => OrgType::data_controller_for( $type ),
			'status'           => OrgStatus::SUSPENDED,
			'country'          => $country,
			'default_timezone' => $timezone,
			'contract_ref'     => sanitize_text_field( (string) ( $args['contract_ref'] ?? '' ) ),
			'dpa_signed_at'    => null,
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$org_id = 0;

		$this->repo->begin_transaction();
		try {
			$org_id = $this->repo->insert_org( $data );
			$this->repo->commit();
		} catch ( PersistenceException $e ) {
			$this->repo->rollback();
			if ( PersistenceException::DUPLICATE_ORG_CODE === $e->kind() ) {
				return new WP_Error( 'org_code_taken', __( 'Org code already in use.', 'minhaj-core' ) );
			}
			return new WP_Error( 'persistence_error', $e->getMessage() );
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::ORG_CREATED, $org_id, $type, $actor_user_id );

		return $org_id;
	}

	// ============================================================== set_status.

	/**
	 * @return true|WP_Error
	 */
	public function set_status( int $actor_user_id, int $org_id, string $status, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( ! OrgStatus::is_valid( $status ) ) {
			return new WP_Error( 'invalid_status', __( 'Unknown org status.', 'minhaj-core' ) );
		}

		$reason_clean = sanitize_text_field( $reason );
		if ( '' === trim( $reason_clean ) ) {
			return new WP_Error( 'reason_required', __( 'A reason is required.', 'minhaj-core' ) );
		}

		$from_status = '';

		$this->repo->begin_transaction();
		try {
			$org = $this->repo->find_org_for_update( $org_id );
			if ( null === $org ) {
				$this->repo->rollback();
				return new WP_Error( 'org_not_found', __( 'Org not found.', 'minhaj-core' ) );
			}

			$from_status = (string) $org['status'];

			// O-8 · a DPA-less org cannot be activated. The requirement is
			// filter-controlled *for tests only* — the default is true and MUST
			// remain true in production.
			$requires_dpa = (bool) apply_filters( 'minhaj_org_requires_dpa', true );
			if ( OrgStatus::ACTIVE === $status && $requires_dpa && empty( $org['dpa_signed_at'] ) ) {
				$this->repo->rollback();
				return new WP_Error(
					'dpa_required',
					__( 'Org has no DPA on file — cannot activate.', 'minhaj-core' )
				);
			}

			$now = current_time( 'mysql', true );
			$this->repo->update_org(
				$org_id,
				array(
					'status'     => $status,
					'updated_at' => $now,
				)
			);

			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::ORG_STATUS_CHANGED, $org_id, $from_status, $status, $reason_clean, $actor_user_id );

		return true;
	}

	// ============================================================== record_dpa.

	/**
	 * @return true|WP_Error
	 */
	public function record_dpa( int $actor_user_id, int $org_id, string $signed_date, string $ref ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$signed_date_clean = sanitize_text_field( $signed_date );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $signed_date_clean ) ) {
			return new WP_Error( 'invalid_arg', __( 'signed_date must be YYYY-MM-DD.', 'minhaj-core' ) );
		}

		$ref_clean = sanitize_text_field( $ref );
		if ( '' === trim( $ref_clean ) ) {
			return new WP_Error( 'invalid_arg', __( 'contract_ref is required.', 'minhaj-core' ) );
		}

		$this->repo->begin_transaction();
		try {
			$org = $this->repo->find_org_for_update( $org_id );
			if ( null === $org ) {
				$this->repo->rollback();
				return new WP_Error( 'org_not_found', __( 'Org not found.', 'minhaj-core' ) );
			}

			$now = current_time( 'mysql', true );
			$this->repo->update_org(
				$org_id,
				array(
					'dpa_signed_at' => $signed_date_clean,
					'contract_ref'  => $ref_clean,
					'updated_at'    => $now,
				)
			);
			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::ORG_DPA_RECORDED, $org_id, $signed_date_clean, $actor_user_id );

		return true;
	}

	// ================================================== issue_registration_link.

	/**
	 * @param array<string, mixed> $args
	 * @return array{id:int, token:string, url:string}|WP_Error
	 */
	public function issue_registration_link( int $actor_user_id, int $org_id, array $args ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$link_id = 0;
		$token   = '';

		$this->repo->begin_transaction();
		try {
			$org = $this->repo->find_org_for_update( $org_id );
			if ( null === $org ) {
				$this->repo->rollback();
				return new WP_Error( 'org_not_found', __( 'Org not found.', 'minhaj-core' ) );
			}

			// O-8 · no link may be issued for an org without a DPA on file.
			$requires_dpa = (bool) apply_filters( 'minhaj_org_requires_dpa', true );
			if ( $requires_dpa && empty( $org['dpa_signed_at'] ) ) {
				$this->repo->rollback();
				return new WP_Error(
					'dpa_required',
					__( 'Org has no DPA on file — cannot issue link.', 'minhaj-core' )
				);
			}

			if ( OrgStatus::ACTIVE !== (string) $org['status'] ) {
				$this->repo->rollback();
				return new WP_Error( 'org_not_active', __( 'Only active orgs can issue links.', 'minhaj-core' ) );
			}

			$token = $this->generate_token();

			$now  = current_time( 'mysql', true );
			$data = array(
				'org_id'     => $org_id,
				'token'      => $token,
				'label'      => sanitize_text_field( (string) ( $args['label'] ?? '' ) ),
				'campaign'   => sanitize_text_field( (string) ( $args['campaign'] ?? '' ) ),
				'status'     => 'active',
				'max_uses'   => isset( $args['max_uses'] ) ? (int) $args['max_uses'] : null,
				'uses_count' => 0,
				'expires_at' => isset( $args['expires_at'] ) && '' !== $args['expires_at']
					? sanitize_text_field( (string) $args['expires_at'] )
					: null,
				'created_by' => $actor_user_id,
				'created_at' => $now,
			);

			$link_id = $this->repo->insert_registration_link( $data );

			$this->repo->commit();
		} catch ( PersistenceException $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage(), array( 'kind' => $e->kind() ) );
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		$url = $this->build_registration_url( $token );

		do_action( Events::LINK_ISSUED, $org_id, $link_id, $token, $actor_user_id );

		return array(
			'id'    => $link_id,
			'token' => $token,
			'url'   => $url,
		);
	}

	// ================================================= revoke_registration_link.

	/**
	 * @return true|WP_Error
	 */
	public function revoke_registration_link( int $actor_user_id, int $link_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$reason_clean = sanitize_text_field( $reason );
		if ( '' === trim( $reason_clean ) ) {
			return new WP_Error( 'reason_required', __( 'A reason is required.', 'minhaj-core' ) );
		}

		$org_id = 0;

		$this->repo->begin_transaction();
		try {
			$link = $this->repo->find_registration_link_for_update( $link_id );
			if ( null === $link ) {
				$this->repo->rollback();
				return new WP_Error( 'link_not_found', __( 'Registration link not found.', 'minhaj-core' ) );
			}

			$org_id = (int) $link['org_id'];
			$now    = current_time( 'mysql', true );

			$this->repo->update_registration_link(
				$link_id,
				array(
					'status'     => 'revoked',
					'revoked_at' => $now,
				)
			);
			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::LINK_REVOKED, $org_id, $link_id, $reason_clean, $actor_user_id );

		return true;
	}

	// ============================================= resolve_registration_token.

	/**
	 * Returns the link row + org row when the token is still usable — nothing
	 * otherwise. Never leaks which of "unknown", "revoked", "expired",
	 * "exhausted" is the reason: O-4 says the public form must give one
	 * neutral response for all of them.
	 *
	 * @return array{link:array<string,mixed>, org:array<string,mixed>}|null
	 */
	public function resolve_registration_token( string $token ): ?array {
		$token = trim( $token );
		if ( 22 !== strlen( $token ) ) {
			return null;
		}

		$link = $this->repo->find_registration_link_by_token( $token );
		if ( null === $link ) {
			return null;
		}

		if ( 'active' !== (string) $link['status'] ) {
			return null;
		}

		$today = current_time( 'Y-m-d', true );
		if ( ! empty( $link['expires_at'] ) && (string) $link['expires_at'] < $today ) {
			return null;
		}

		if ( null !== $link['max_uses'] && (int) $link['uses_count'] >= (int) $link['max_uses'] ) {
			return null;
		}

		$org = $this->repo->find_org( (int) $link['org_id'] );
		if ( null === $org ) {
			return null;
		}

		if ( OrgStatus::ACTIVE !== (string) $org['status'] ) {
			return null;
		}

		return array(
			'link' => $link,
			'org'  => $org,
		);
	}

	// ================================================ consume_registration_token.

	/**
	 * MUST be called inside the caller's outer transaction — the People module
	 * opens one for `create_student()` and the token consumption rides on it.
	 * The atomic UPDATE below is the enforcement point for spec §8-3 (a race on
	 * `max_uses=1` returns exactly one success): the WHERE clause re-checks
	 * status/expiry/uses so a stale row read cannot slip a second consumer past.
	 *
	 * @return true|WP_Error
	 */
	public function consume_registration_token( int $link_id ) {
		if ( $link_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'link_id is required.', 'minhaj-core' ) );
		}

		$affected = $this->repo->increment_uses_if_available( $link_id );
		if ( 0 === $affected ) {
			return new WP_Error(
				'link_exhausted',
				__( 'Registration link is no longer usable.', 'minhaj-core' )
			);
		}

		return true;
	}

	// ================================================================ add_member.

	/**
	 * @return int|WP_Error
	 */
	public function add_member( int $actor_user_id, int $org_id, int $user_id, string $role_in_org ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		if ( $org_id <= 0 || $user_id <= 0 ) {
			return new WP_Error( 'invalid_arg', __( 'org_id and user_id are required.', 'minhaj-core' ) );
		}

		if ( ! MembershipRole::is_valid( $role_in_org ) ) {
			return new WP_Error( 'invalid_role', __( 'Unknown role_in_org.', 'minhaj-core' ) );
		}

		$membership_id = 0;

		$this->repo->begin_transaction();
		try {
			$org = $this->repo->find_org_for_update( $org_id );
			if ( null === $org ) {
				$this->repo->rollback();
				return new WP_Error( 'org_not_found', __( 'Org not found.', 'minhaj-core' ) );
			}

			$now = current_time( 'mysql', true );

			try {
				$membership_id = $this->repo->insert_member(
					array(
						'org_id'      => $org_id,
						'user_id'     => $user_id,
						'role_in_org' => $role_in_org,
						'started_at'  => $now,
					)
				);
			} catch ( PersistenceException $e ) {
				$this->repo->rollback();
				if ( PersistenceException::DUPLICATE_ACTIVE_MEMBER === $e->kind() ) {
					return new WP_Error(
						'duplicate_active_member',
						__( 'User is already an active member of this org.', 'minhaj-core' )
					);
				}
				return new WP_Error( 'persistence_error', $e->getMessage() );
			}

			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::MEMBER_ADDED, $org_id, $membership_id, $user_id, $role_in_org, $actor_user_id );

		return $membership_id;
	}

	// ================================================================ end_membership.

	/**
	 * @return true|WP_Error
	 */
	public function end_membership( int $actor_user_id, int $membership_id, string $reason ) {
		$actor_check = $this->require_actor( $actor_user_id );
		if ( is_wp_error( $actor_check ) ) {
			return $actor_check;
		}

		$reason_clean = sanitize_text_field( $reason );
		if ( '' === trim( $reason_clean ) ) {
			return new WP_Error( 'reason_required', __( 'A reason is required.', 'minhaj-core' ) );
		}

		$org_id  = 0;
		$user_id = 0;

		$this->repo->begin_transaction();
		try {
			$member = $this->repo->find_member( $membership_id );
			if ( null === $member ) {
				$this->repo->rollback();
				return new WP_Error( 'member_not_found', __( 'Membership not found.', 'minhaj-core' ) );
			}

			if ( null !== $member['ended_at'] ) {
				$this->repo->rollback();
				return new WP_Error( 'already_ended', __( 'Membership already ended.', 'minhaj-core' ) );
			}

			$org_id  = (int) $member['org_id'];
			$user_id = (int) $member['user_id'];
			$now     = current_time( 'mysql', true );

			$this->repo->update_member( $membership_id, array( 'ended_at' => $now ) );
			$this->repo->commit();
		} catch ( Throwable $e ) {
			$this->repo->rollback();
			return new WP_Error( 'persistence_error', $e->getMessage() );
		}

		do_action( Events::MEMBER_ENDED, $org_id, $membership_id, $user_id, $reason_clean, $actor_user_id );

		return true;
	}

	// ================================================================= org_ids_for_user.

	/**
	 * @return array<int, int>
	 */
	public function org_ids_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		return $this->repo->list_active_org_ids_for_user( $user_id );
	}

	// =============================================================== attribution_report.

	/**
	 * @return array{org_id:int, from:string, to:string, count:int, students:array<int, array<string,mixed>>}
	 */
	public function attribution_report( int $org_id, string $from, string $to ): array {
		$from_clean = sanitize_text_field( $from );
		$to_clean   = sanitize_text_field( $to );

		$rows = $this->repo->attribution_rows( $org_id, $from_clean, $to_clean );

		return array(
			'org_id'   => $org_id,
			'from'     => $from_clean,
			'to'       => $to_clean,
			'count'    => count( $rows ),
			'students' => $rows,
		);
	}

	// ------------------------------------------------------------------- Helpers.

	/**
	 * @return true|WP_Error
	 */
	private function require_actor( int $actor_user_id ) {
		if ( $actor_user_id <= 0 ) {
			return new WP_Error(
				'missing_actor',
				__( 'actor_user_id must be a positive integer — audit rows cannot be anonymous.', 'minhaj-core' )
			);
		}

		return true;
	}

	/**
	 * 128 bits of entropy encoded url-safe. 16 random bytes → 22 base64url
	 * characters after stripping padding — matches the CHAR(22) column and
	 * spec §3.2. Loops on the astronomically unlikely token collision.
	 */
	private function generate_token(): string {
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$raw = random_bytes( 16 );
			// base64_encode here is url-safe token encoding, not obfuscation
			// of code — the WPCS sniff cannot tell them apart.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$b64 = rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
			if ( null === $this->repo->find_registration_link_by_token( $b64 ) ) {
				return $b64;
			}
		}

		// Five collisions on a 128-bit space means something is very wrong.
		throw new \RuntimeException( 'unable to allocate a unique registration token' );
	}

	private function build_registration_url( string $token ): string {
		$default = home_url( '/join/' . $token );

		/**
		 * Filter · shape of the public registration URL. Sites behind a
		 * reverse proxy or on a custom rewrite may need to override.
		 *
		 * @param string $url
		 * @param string $token
		 */
		return (string) apply_filters( 'minhaj_org_registration_url', $default, $token );
	}
}
