#!/usr/bin/env bash
# Cross-org isolation + duplicate-membership DB proof — spec-organizations-v1 §8-5, §8-11.
#
# §8-5 is the most important line in the two phase-2 specs: a partner-org
# admin must NOT see another partner's rows. This test proves it against a
# live MariaDB — not by mocking AccessRepository, but by:
#   1. Creating two orgs A and B, each with a group + a member + a
#      teacher-linked profile + a student profile.
#   2. Creating a WP user with role=minhaj_org_admin and an active
#      minhaj_org_members row for org A only.
#   3. Calling AccessPolicy::visible_group_ids_for /
#      visible_student_ids_for / can_view_group with that user's id and
#      asserting zero rows for every org-B row.
#
# §8-11: attempting to insert a second active minhaj_org_members row for
# the same (org_id, user_id) MUST be rejected by the database — not by PHP.
# We prove the rejection comes from InnoDB by inspecting $wpdb->last_error
# for the uq_active_member key name.
#
# Pattern intentionally mirrors tests/Integration/groups-concurrency.sh.

set -euo pipefail

WP_ENV=${WP_ENV:-wp-env}
LOGDIR=$(mktemp -d)
GREEN=$'\033[32m'
RED=$'\033[31m'
BOLD=$'\033[1m'
RESET=$'\033[0m'

trap 'rm -rf "$LOGDIR"' EXIT

run_wp() {
  "$WP_ENV" run cli wp "$@" 2>/dev/null
}

echo "${BOLD}== Reset test tables ==${RESET}"
run_wp db query "
  DELETE FROM wp_minhaj_group_audit;
  DELETE FROM wp_minhaj_group_members;
  DELETE FROM wp_minhaj_groups;
  DELETE FROM wp_minhaj_org_members;
  DELETE FROM wp_minhaj_org_registration_links;
  DELETE FROM wp_minhaj_orgs;
  DELETE FROM wp_minhaj_student_profiles;
  DELETE FROM wp_minhaj_teacher_profiles;
  DELETE FROM wp_minhaj_person_audit;
" >/dev/null

echo "${BOLD}== Seed: two orgs + one group + one student per org ==${RESET}"

# The DPA gate defends production activation (spec §5 O-8) — we bypass it
# here via the documented filter so this test can focus on the scoping
# invariant. The gate is separately proved by tests/Unit/Modules/Orgs.
SEED_CODE=$(cat <<'PHP'
add_filter( 'minhaj_org_requires_dpa', '__return_false' );

$repo = new \Minhaj\Modules\Orgs\Repository\OrgRepository();
$svc  = new \Minhaj\Modules\Orgs\OrgService( $repo );

$org_a = $svc->create_org( 1, [ 'code' => 'ORG-A', 'name' => 'Alpha Partners', 'country' => 'QA' ] );
$org_b = $svc->create_org( 1, [ 'code' => 'ORG-B', 'name' => 'Beta Partners',  'country' => 'AE' ] );
foreach ( [ $org_a, $org_b ] as $oid ) {
    if ( is_wp_error( $oid ) ) { echo "seed_org_failed:" . $oid->get_error_code(); exit(1); }
    $svc->set_status( 1, $oid, 'active', 'test-activate' );
}

// Groups — one per org.
$groups_repo = new \Minhaj\Modules\Groups\Repository\GroupRepository();
$groups_svc  = new \Minhaj\Modules\Groups\GroupService( $groups_repo );

$group_a = $groups_svc->create( 1, [ 'code' => 'GRP-A', 'type' => 'group', 'capacity_min' => 3, 'capacity_max' => 5 ] );
$group_b = $groups_svc->create( 1, [ 'code' => 'GRP-B', 'type' => 'group', 'capacity_min' => 3, 'capacity_max' => 5 ] );
foreach ( [ $group_a, $group_b ] as $gid ) {
    if ( is_wp_error( $gid ) ) { echo "seed_group_failed:" . $gid->get_error_code(); exit(1); }
}

global $wpdb;

// Attach each group to its org (Groups module does not yet accept org_id via
// create() — the column is present, we set it directly).
$wpdb->query( $wpdb->prepare( "UPDATE wp_minhaj_groups SET org_id = %d WHERE id = %d", $org_a, $group_a ) );
$wpdb->query( $wpdb->prepare( "UPDATE wp_minhaj_groups SET org_id = %d WHERE id = %d", $org_b, $group_b ) );

// Assign a teacher to each group and add one student member each.
$teacher_a = wp_insert_user( [ 'user_login' => 'teacher_a_' . uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'minhaj_teacher' ] );
$teacher_b = wp_insert_user( [ 'user_login' => 'teacher_b_' . uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'minhaj_teacher' ] );
$student_a = wp_insert_user( [ 'user_login' => 'student_a_' . uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'minhaj_student' ] );
$student_b = wp_insert_user( [ 'user_login' => 'student_b_' . uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'minhaj_student' ] );

$now = current_time( 'mysql', true );
$wpdb->insert( 'wp_minhaj_teacher_profiles', [ 'user_id' => $teacher_a, 'display_name' => 'Teacher A', 'status' => 'active', 'org_id' => $org_a, 'created_at' => $now, 'updated_at' => $now ] );
$wpdb->insert( 'wp_minhaj_teacher_profiles', [ 'user_id' => $teacher_b, 'display_name' => 'Teacher B', 'status' => 'active', 'org_id' => $org_b, 'created_at' => $now, 'updated_at' => $now ] );
$wpdb->insert( 'wp_minhaj_student_profiles', [ 'user_id' => $student_a, 'first_name' => 'Sara', 'family_name_initial' => 'A', 'origin_org_id' => $org_a, 'created_at' => $now ] );
$wpdb->insert( 'wp_minhaj_student_profiles', [ 'user_id' => $student_b, 'first_name' => 'Bilal', 'family_name_initial' => 'B', 'origin_org_id' => $org_b, 'created_at' => $now ] );

$groups_svc->assign_teacher( 1, $group_a, $teacher_a, 'test-seed' );
$groups_svc->assign_teacher( 1, $group_b, $teacher_b, 'test-seed' );
$groups_svc->add_member( 1, $group_a, $student_a );
$groups_svc->add_member( 1, $group_b, $student_b );

// The org-A admin: WP user with our new role + an active membership in org A.
$admin_a_id = wp_insert_user( [ 'user_login' => 'org_a_admin_' . uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'minhaj_org_admin' ] );
$svc->add_member( 1, $org_a, $admin_a_id, 'org_admin' );

printf( "ORG_A=%d ORG_B=%d GROUP_A=%d GROUP_B=%d STUDENT_A=%d STUDENT_B=%d TEACHER_A=%d TEACHER_B=%d ADMIN_A=%d\n",
    $org_a, $org_b, $group_a, $group_b, $student_a, $student_b, $teacher_a, $teacher_b, $admin_a_id );
PHP
)

SEED_OUT=$(run_wp eval "$SEED_CODE" | tr -d '\r')
echo "  $SEED_OUT"

parse_id() { printf '%s' "$SEED_OUT" | grep -oE "$1=[0-9]+" | head -1 | cut -d= -f2; }

ORG_A=$(parse_id ORG_A)
ORG_B=$(parse_id ORG_B)
GROUP_A=$(parse_id GROUP_A)
GROUP_B=$(parse_id GROUP_B)
STUDENT_A=$(parse_id STUDENT_A)
STUDENT_B=$(parse_id STUDENT_B)
ADMIN_A=$(parse_id ADMIN_A)

for var in ORG_A ORG_B GROUP_A GROUP_B STUDENT_A STUDENT_B ADMIN_A; do
  if [[ -z "${!var:-}" ]]; then
    echo "${RED}✗ could not parse $var from seed output${RESET}"
    exit 1
  fi
done

echo
echo "${BOLD}== §8-5 · org-A admin (user=$ADMIN_A) queries the AccessPolicy ==${RESET}"

ISOLATION_CODE=$(cat <<PHP
\$repo   = new \\Minhaj\\Access\\AccessRepository();
\$policy = new \\Minhaj\\Access\\AccessPolicy( \$repo );

\$visible_groups   = \$policy->visible_group_ids_for( $ADMIN_A );
\$visible_students = \$policy->visible_student_ids_for( $ADMIN_A );
\$scope            = \$policy->org_ids_for( $ADMIN_A );

printf( "GROUPS=%s STUDENTS=%s SCOPE=%s IS_SCOPED=%s CAN_VIEW_B=%s CAN_VIEW_A=%s\n",
    implode( ',', \$visible_groups ),
    implode( ',', \$visible_students ),
    null === \$scope ? 'null' : ( '[' . implode( ',', \$scope ) . ']' ),
    \$policy->is_org_scoped( $ADMIN_A ) ? 'true' : 'false',
    \$policy->can_view_group( $ADMIN_A, $GROUP_B ) ? 'YES' : 'no',
    \$policy->can_view_group( $ADMIN_A, $GROUP_A ) ? 'YES' : 'no'
);
PHP
)

ISOLATION_OUT=$(run_wp eval "$ISOLATION_CODE" | tr -d '\r')
echo "  $ISOLATION_OUT"

VISIBLE_GROUPS=$(printf '%s' "$ISOLATION_OUT" | grep -oE 'GROUPS=[0-9,]*' | cut -d= -f2)
VISIBLE_STUDENTS=$(printf '%s' "$ISOLATION_OUT" | grep -oE 'STUDENTS=[0-9,]*' | cut -d= -f2)
SCOPE=$(printf '%s' "$ISOLATION_OUT" | grep -oE 'SCOPE=[^ ]+' | cut -d= -f2)
IS_SCOPED=$(printf '%s' "$ISOLATION_OUT" | grep -oE 'IS_SCOPED=[a-z]+' | cut -d= -f2)
CAN_VIEW_B=$(printf '%s' "$ISOLATION_OUT" | grep -oE 'CAN_VIEW_B=[A-Za-z]+' | cut -d= -f2)
CAN_VIEW_A=$(printf '%s' "$ISOLATION_OUT" | grep -oE 'CAN_VIEW_A=[A-Za-z]+' | cut -d= -f2)

echo
echo "${BOLD}== Assertions ==${RESET}"
FAIL=0

# visible_group_ids_for must contain group A only.
if [[ "$VISIBLE_GROUPS" == "$GROUP_A" ]]; then
  echo "  ${GREEN}✓ visible_group_ids_for = [$GROUP_A] — org B not present${RESET}"
else
  echo "  ${RED}✗ visible_group_ids_for=[$VISIBLE_GROUPS] — expected [$GROUP_A] only${RESET}"
  FAIL=1
fi

# visible_student_ids_for must contain student A only.
if [[ "$VISIBLE_STUDENTS" == "$STUDENT_A" ]]; then
  echo "  ${GREEN}✓ visible_student_ids_for = [$STUDENT_A] — org B student not leaked${RESET}"
else
  echo "  ${RED}✗ visible_student_ids_for=[$VISIBLE_STUDENTS] — expected [$STUDENT_A] only${RESET}"
  FAIL=1
fi

# Scope must be a real array, not null.
if [[ "$SCOPE" == "[$ORG_A]" ]] && [[ "$IS_SCOPED" == "true" ]]; then
  echo "  ${GREEN}✓ org_ids_for = [$ORG_A], is_org_scoped = true${RESET}"
else
  echo "  ${RED}✗ scope=$SCOPE is_scoped=$IS_SCOPED — expected [$ORG_A] and true${RESET}"
  FAIL=1
fi

# can_view_group must refuse group B.
if [[ "$CAN_VIEW_B" == "no" ]]; then
  echo "  ${GREEN}✓ can_view_group(admin_A, group_B) = false${RESET}"
else
  echo "  ${RED}✗ can_view_group(admin_A, group_B) returned $CAN_VIEW_B — CROSS-ORG LEAK${RESET}"
  FAIL=1
fi

if [[ "$CAN_VIEW_A" == "YES" ]]; then
  echo "  ${GREEN}✓ can_view_group(admin_A, group_A) = true${RESET}"
else
  echo "  ${RED}✗ can_view_group(admin_A, group_A) returned $CAN_VIEW_A — own org should be visible${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== §8-11 · duplicate active membership in org A must be rejected by the DB ==${RESET}"

DUP_CODE=$(cat <<PHP
global \$wpdb;
\$wpdb->suppress_errors( true );
\$result = \$wpdb->insert(
    'wp_minhaj_org_members',
    [
        'org_id'      => $ORG_A,
        'user_id'     => $ADMIN_A,
        'role_in_org' => 'org_admin',
        'started_at'  => current_time( 'mysql', true ),
    ]
);
\$err = (string) \$wpdb->last_error;
\$wpdb->suppress_errors( false );

printf( "INSERT=%s ERR=[%s]\n", false === \$result ? 'refused' : 'accepted', \$err );

// Now try via OrgService as well — it should surface as WP_Error duplicate_active_member.
\$svc = new \\Minhaj\\Modules\\Orgs\\OrgService( new \\Minhaj\\Modules\\Orgs\\Repository\\OrgRepository() );
\$out = \$svc->add_member( 1, $ORG_A, $ADMIN_A, 'org_admin' );
printf( "SERVICE=%s\n", is_wp_error( \$out ) ? ( 'err:' . \$out->get_error_code() ) : ( 'ok:' . \$out ) );
PHP
)

DUP_OUT=$(run_wp eval "$DUP_CODE" | tr -d '\r')
echo "  $DUP_OUT"

INSERT_RESULT=$(printf '%s' "$DUP_OUT" | grep -oE 'INSERT=[a-z]+' | cut -d= -f2)
INSERT_ERR=$(printf '%s' "$DUP_OUT" | grep -oE 'ERR=\[[^]]*\]' | sed 's/^ERR=\[//;s/\]$//')
SERVICE_RESULT=$(printf '%s' "$DUP_OUT" | grep -oE 'SERVICE=[a-z_:]+' | cut -d= -f2)

if [[ "$INSERT_RESULT" == "refused" ]]; then
  echo "  ${GREEN}✓ raw INSERT refused by the database${RESET}"
else
  echo "  ${RED}✗ raw INSERT succeeded — the uq_active_member unique key is not enforced${RESET}"
  FAIL=1
fi

if [[ "$INSERT_ERR" == *"uq_active_member"* ]]; then
  echo "  ${GREEN}✓ MySQL error names the uq_active_member key: ${INSERT_ERR}${RESET}"
else
  echo "  ${RED}✗ error message does not name uq_active_member: ${INSERT_ERR}${RESET}"
  FAIL=1
fi

if [[ "$SERVICE_RESULT" == "err:duplicate_active_member" ]]; then
  echo "  ${GREEN}✓ OrgService translates the DB error into WP_Error(duplicate_active_member)${RESET}"
else
  echo "  ${RED}✗ OrgService returned $SERVICE_RESULT — expected err:duplicate_active_member${RESET}"
  FAIL=1
fi

echo
if [[ "$FAIL" != "0" ]]; then
  echo "${RED}${BOLD}ORGS CROSS-SCOPE PROOF FAILED${RESET}"
  exit 1
fi

echo "${GREEN}${BOLD}ORGS CROSS-SCOPE PROOF PASSED${RESET}"
