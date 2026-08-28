#!/usr/bin/env bash
# Cross-org isolation + duplicate-membership DB proof — spec-organizations-v1 §8-5, §8-11.
#
# §8-5 is the most important line in the two phase-2 specs: a partner-org
# admin must NOT see another partner's rows. This test proves it against a
# live MariaDB — not by mocking AccessRepository, but by:
#   1. Creating two orgs A and B, each with a group + a member + a
#      teacher-linked profile + a student profile.
#   2. Creating TWO org-admin WP users, one per org, each with an active
#      minhaj_org_members row for their own org only.
#   3. Calling AccessPolicy::visible_group_ids_for /
#      visible_student_ids_for / can_view_group from BOTH admins and
#      asserting each sees only their own rows. A one-way test would
#      miss an asymmetric leak — e.g. a query that scopes by teacher_id
#      but leaks in the student direction.
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

// Bypass the S-4 assignability gate (spec-people-v1) via direct UPDATE:
// these synthetic teachers have no safeguarding check on file. The gate
// is proved separately by tests/Unit/Modules/People/AssignabilityGateTest;
// this test is about org isolation and would double-book the test surface
// if it also had to walk the People onboarding path.
$wpdb->update( 'wp_minhaj_groups', [ 'teacher_id' => $teacher_a ], [ 'id' => $group_a ] );
$wpdb->update( 'wp_minhaj_groups', [ 'teacher_id' => $teacher_b ], [ 'id' => $group_b ] );

$groups_svc->add_member( 1, $group_a, $student_a );
$groups_svc->add_member( 1, $group_b, $student_b );

// Two org admins: WP users with our new role, each an active member of
// their own org only. Testing symmetrically catches asymmetric leaks
// (queries that filter one direction but not the other).
$admin_a_id = wp_insert_user( [ 'user_login' => 'org_a_admin_' . uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'minhaj_org_admin' ] );
$admin_b_id = wp_insert_user( [ 'user_login' => 'org_b_admin_' . uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'minhaj_org_admin' ] );
$svc->add_member( 1, $org_a, $admin_a_id, 'org_admin' );
$svc->add_member( 1, $org_b, $admin_b_id, 'org_admin' );

printf( "ORG_A=%d ORG_B=%d GROUP_A=%d GROUP_B=%d STUDENT_A=%d STUDENT_B=%d TEACHER_A=%d TEACHER_B=%d ADMIN_A=%d ADMIN_B=%d\n",
    $org_a, $org_b, $group_a, $group_b, $student_a, $student_b, $teacher_a, $teacher_b, $admin_a_id, $admin_b_id );
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
ADMIN_B=$(parse_id ADMIN_B)
TEACHER_A=$(parse_id TEACHER_A)
TEACHER_B=$(parse_id TEACHER_B)

for var in ORG_A ORG_B GROUP_A GROUP_B STUDENT_A STUDENT_B ADMIN_A ADMIN_B TEACHER_A TEACHER_B; do
  if [[ -z "${!var:-}" ]]; then
    echo "${RED}✗ could not parse $var from seed output${RESET}"
    exit 1
  fi
done

# probe_admin prints one line for a given (admin, own_group, own_student, other_group, other_student)
# summarising every AccessPolicy answer the assertion block needs.
probe_admin() {
  local label=$1 admin=$2 own_group=$3 own_student=$4 other_group=$5 other_student=$6

  local code
  code=$(cat <<PHP
\$repo   = new \\Minhaj\\Access\\AccessRepository();
\$policy = new \\Minhaj\\Access\\AccessPolicy( \$repo );

\$visible_groups   = \$policy->visible_group_ids_for( $admin );
\$visible_students = \$policy->visible_student_ids_for( $admin );
\$scope            = \$policy->org_ids_for( $admin );

printf(
    "GROUPS=%s STUDENTS=%s SCOPE=%s IS_SCOPED=%s CAN_VIEW_OTHER_GROUP=%s CAN_VIEW_OWN_GROUP=%s CAN_VIEW_OTHER_STUDENT=%s CAN_VIEW_OWN_STUDENT=%s\n",
    implode( ',', \$visible_groups ),
    implode( ',', \$visible_students ),
    null === \$scope ? 'null' : ( '[' . implode( ',', \$scope ) . ']' ),
    \$policy->is_org_scoped( $admin ) ? 'true' : 'false',
    \$policy->can_view_group( $admin, $other_group ) ? 'YES' : 'no',
    \$policy->can_view_group( $admin, $own_group ) ? 'YES' : 'no',
    \$policy->can_view_student( $admin, $other_student ) ? 'YES' : 'no',
    \$policy->can_view_student( $admin, $own_student ) ? 'YES' : 'no'
);
PHP
)

  run_wp eval "$code" | tr -d '\r'
}

FAIL=0

# assert_isolation compares one admin's probe against the expected own vs other rows.
assert_isolation() {
  local label=$1 out=$2 expected_group=$3 expected_student=$4 expected_scope=$5

  local vg vs sc is_sc cv_other cv_own cvs_other cvs_own
  vg=$(printf '%s' "$out" | grep -oE 'GROUPS=[0-9,]*' | cut -d= -f2)
  vs=$(printf '%s' "$out" | grep -oE 'STUDENTS=[0-9,]*' | cut -d= -f2)
  sc=$(printf '%s' "$out" | grep -oE 'SCOPE=[^ ]+' | cut -d= -f2)
  is_sc=$(printf '%s' "$out" | grep -oE 'IS_SCOPED=[a-z]+' | cut -d= -f2)
  cv_other=$(printf '%s' "$out" | grep -oE 'CAN_VIEW_OTHER_GROUP=[A-Za-z]+' | cut -d= -f2)
  cv_own=$(printf '%s' "$out" | grep -oE 'CAN_VIEW_OWN_GROUP=[A-Za-z]+' | cut -d= -f2)
  cvs_other=$(printf '%s' "$out" | grep -oE 'CAN_VIEW_OTHER_STUDENT=[A-Za-z]+' | cut -d= -f2)
  cvs_own=$(printf '%s' "$out" | grep -oE 'CAN_VIEW_OWN_STUDENT=[A-Za-z]+' | cut -d= -f2)

  if [[ "$vg" == "$expected_group" ]]; then
    echo "  ${GREEN}✓ [$label] visible_group_ids_for = [$expected_group] — other org not present${RESET}"
  else
    echo "  ${RED}✗ [$label] visible_group_ids_for=[$vg] — expected [$expected_group]${RESET}"
    FAIL=1
  fi

  if [[ "$vs" == "$expected_student" ]]; then
    echo "  ${GREEN}✓ [$label] visible_student_ids_for = [$expected_student] — other org student not leaked${RESET}"
  else
    echo "  ${RED}✗ [$label] visible_student_ids_for=[$vs] — expected [$expected_student]${RESET}"
    FAIL=1
  fi

  if [[ "$sc" == "[$expected_scope]" ]] && [[ "$is_sc" == "true" ]]; then
    echo "  ${GREEN}✓ [$label] org_ids_for = [$expected_scope], is_org_scoped = true${RESET}"
  else
    echo "  ${RED}✗ [$label] scope=$sc is_scoped=$is_sc — expected [$expected_scope] and true${RESET}"
    FAIL=1
  fi

  if [[ "$cv_other" == "no" ]]; then
    echo "  ${GREEN}✓ [$label] can_view_group(other) = false${RESET}"
  else
    echo "  ${RED}✗ [$label] can_view_group(other) returned $cv_other — CROSS-ORG LEAK${RESET}"
    FAIL=1
  fi

  if [[ "$cv_own" == "YES" ]]; then
    echo "  ${GREEN}✓ [$label] can_view_group(own) = true${RESET}"
  else
    echo "  ${RED}✗ [$label] can_view_group(own) returned $cv_own — own org should be visible${RESET}"
    FAIL=1
  fi

  if [[ "$cvs_other" == "no" ]]; then
    echo "  ${GREEN}✓ [$label] can_view_student(other) = false${RESET}"
  else
    echo "  ${RED}✗ [$label] can_view_student(other) returned $cvs_other — student CROSS-ORG LEAK${RESET}"
    FAIL=1
  fi

  if [[ "$cvs_own" == "YES" ]]; then
    echo "  ${GREEN}✓ [$label] can_view_student(own) = true${RESET}"
  else
    echo "  ${RED}✗ [$label] can_view_student(own) returned $cvs_own — own org student should be visible${RESET}"
    FAIL=1
  fi
}

echo
echo "${BOLD}== §8-5 · direction A→B: org-A admin (user=$ADMIN_A) queries the AccessPolicy ==${RESET}"
PROBE_A=$(probe_admin "A→B" "$ADMIN_A" "$GROUP_A" "$STUDENT_A" "$GROUP_B" "$STUDENT_B")
echo "  $PROBE_A"

echo
echo "${BOLD}== §8-5 · direction B→A: org-B admin (user=$ADMIN_B) queries the AccessPolicy ==${RESET}"
PROBE_B=$(probe_admin "B→A" "$ADMIN_B" "$GROUP_B" "$STUDENT_B" "$GROUP_A" "$STUDENT_A")
echo "  $PROBE_B"

echo
echo "${BOLD}== Assertions ==${RESET}"

assert_isolation "A→B" "$PROBE_A" "$GROUP_A" "$STUDENT_A" "$ORG_A"
assert_isolation "B→A" "$PROBE_B" "$GROUP_B" "$STUDENT_B" "$ORG_B"

echo
echo "${BOLD}== §8-6 · org-A admin reads student profile — no guardian PII surfaces ==${RESET}"

# Seed a guardian with a distinctive email + phone so a leak is impossible
# to miss. If either string appears in what the admin can read, we fail.
# Randomise per run — the wp_users.user_email unique key would otherwise
# collide with prior runs, and the whole point is to prove *this* email
# never surfaces, so re-using yesterday's would give a false pass anyway.
GUARDIAN_EMAIL="pii-leak-check-$(date +%s)-$RANDOM@example.com"
GUARDIAN_PHONE="+974-5555-$(date +%s)-$RANDOM"

PII_SEED_CODE=$(cat <<PHP
\$guardian_a = wp_insert_user( [
    'user_login' => 'guardian_pii_' . uniqid(),
    'user_email' => '$GUARDIAN_EMAIL',
    'user_pass'  => wp_generate_password(),
    'role'       => 'minhaj_parent',
] );
if ( is_wp_error( \$guardian_a ) ) { echo "insert_user_failed:" . \$guardian_a->get_error_code(); exit(1); }
update_user_meta( \$guardian_a, 'billing_phone', '$GUARDIAN_PHONE' );

global \$wpdb;
\$now = current_time( 'mysql', true );
\$wpdb->insert( 'wp_minhaj_guardianship', [
    'guardian_id'  => \$guardian_a,
    'student_id'   => $STUDENT_A,
    'relationship' => 'parent',
    'is_primary'   => 1,
    'can_view'     => 1,
    'can_manage'   => 1,
    'started_at'   => \$now,
    'created_at'   => \$now,
] );
printf( "GUARDIAN_A=%d\n", \$guardian_a );
PHP
)

PII_SEED_OUT=$(run_wp eval "$PII_SEED_CODE" | tr -d '\r')
echo "  $PII_SEED_OUT"
GUARDIAN_A=$(printf '%s' "$PII_SEED_OUT" | grep -oE 'GUARDIAN_A=[0-9]+' | cut -d= -f2)

if [[ -z "${GUARDIAN_A:-}" ]]; then
  echo "  ${RED}✗ could not seed guardian for PII check${RESET}"
  exit 1
fi

# Serialise every read an org-admin UI would plausibly perform against the
# student, then grep for the seeded email / phone. The grep is intentionally
# broader than the current schema — it catches leaks in new columns too.
PII_PROBE_CODE=$(cat <<PHP
\$access_repo   = new \\Minhaj\\Access\\AccessRepository();
\$people_repo   = new \\Minhaj\\Modules\\People\\Repository\\PeopleRepository();

\$views = [
    'access.find_student_profile' => \$access_repo->find_student_profile( $STUDENT_A ),
    'people.find_student_profile' => \$people_repo->find_student_profile( $STUDENT_A ),
    // list_guardians_of_student is legitimate (org admin needs the count),
    // but MUST NOT contain email / phone — those live on wp_users and
    // wp_usermeta, not on the guardianship row.
    'people.list_guardians'       => \$people_repo->list_guardians_of_student( $STUDENT_A ),
];

// Also emit can_view_student(admin_A, guardian_A). The guardian is a WP
// user; the admin has no relationship to them, so this MUST be false.
\$policy = new \\Minhaj\\Access\\AccessPolicy( \$access_repo );
\$views['policy.can_view_student(guardian_A)'] = \$policy->can_view_student( $ADMIN_A, $GUARDIAN_A );

echo json_encode( \$views, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
PHP
)

PII_PROBE_OUT=$(run_wp eval "$PII_PROBE_CODE" | tr -d '\r')

# Emit the payload so a future reviewer can see exactly what shipped.
echo "  --- payload the org admin can read ---"
printf '%s\n' "$PII_PROBE_OUT" | sed 's/^/  /'
echo "  --------------------------------------"

if grep -F -q "$GUARDIAN_EMAIL" <<<"$PII_PROBE_OUT"; then
  echo "  ${RED}✗ guardian email surfaced in org-admin read of student — PII LEAK${RESET}"
  FAIL=1
else
  echo "  ${GREEN}✓ guardian email absent from every org-admin student read${RESET}"
fi

if grep -F -q "$GUARDIAN_PHONE" <<<"$PII_PROBE_OUT"; then
  echo "  ${RED}✗ guardian phone surfaced in org-admin read of student — PII LEAK${RESET}"
  FAIL=1
else
  echo "  ${GREEN}✓ guardian phone absent from every org-admin student read${RESET}"
fi

# can_view_student against the guardian's own user id must be false — the
# admin has no relationship to the guardian.
if grep -q '"policy.can_view_student(guardian_A)": false' <<<"$PII_PROBE_OUT"; then
  echo "  ${GREEN}✓ can_view_student(admin_A, guardian_A) = false${RESET}"
else
  echo "  ${RED}✗ can_view_student(admin_A, guardian_A) returned true — guardian identity exposed${RESET}"
  FAIL=1
fi

echo
echo "${BOLD}== §8-7 · suspending org A — running group intact, new registration blocked ==${RESET}"

SUSPEND_CODE=$(cat <<PHP
add_filter( 'minhaj_org_requires_dpa', '__return_false' );

\$svc = new \\Minhaj\\Modules\\Orgs\\OrgService( new \\Minhaj\\Modules\\Orgs\\Repository\\OrgRepository() );

// Baseline: an active token issued BEFORE suspension.
\$link = \$svc->issue_registration_link( 1, $ORG_A, [ 'label' => 'pre-suspend' ] );
if ( is_wp_error( \$link ) ) { echo "seed_link_failed:" . \$link->get_error_code(); exit(1); }
\$token = \$link['token'];

// Baseline: pattern totals + teacher availability so generation can run.
global \$wpdb;
\$wpdb->update(
    'wp_minhaj_groups',
    [ 'total_sessions' => 3, 'session_duration_minutes' => 60 ],
    [ 'id' => $GROUP_A ]
);

\$timetable = new \\Minhaj\\Modules\\Timetable\\TimetableService( new \\Minhaj\\Modules\\Timetable\\Repository\\TimetableRepository() );

\$avail = \$timetable->set_availability( 1, $TEACHER_A, [ [
    'weekday'        => 1,          // Monday
    'start_local'    => '09:00',
    'end_local'      => '12:00',
    'timezone'       => 'Asia/Qatar',
    'effective_from' => '2027-01-01',
    'effective_to'   => null,
] ] );
if ( is_wp_error( \$avail ) ) { echo "avail_failed:" . \$avail->get_error_code(); exit(1); }

// Promote group A to ACTIVE before suspending the org. A draft group
// teaches nobody, so proving suspension does not touch it would be
// hollow — the spec (O-6) protects children in the middle of a paid
// programme, and a "middle of a paid programme" group is running.
\$groups_svc = new \\Minhaj\\Modules\\Groups\\GroupService( new \\Minhaj\\Modules\\Groups\\Repository\\GroupRepository() );

// R-2 needs active_members >= capacity_min (=3). Seed two filler members.
foreach ( [ 'filler1', 'filler2' ] as \$slug ) {
    \$uid = wp_insert_user( [
        'user_login' => \$slug . '_a_' . uniqid(),
        'user_pass'  => wp_generate_password(),
        'role'       => 'minhaj_student',
    ] );
    if ( is_wp_error( \$uid ) ) { echo "filler_insert_failed:" . \$uid->get_error_code(); exit(1); }
    \$res = \$groups_svc->add_member( 1, $GROUP_A, \$uid );
    if ( is_wp_error( \$res ) ) { echo "add_filler_failed:" . \$res->get_error_code(); exit(1); }
}

foreach ( [ 'forming', 'scheduled', 'active' ] as \$next ) {
    \$t = \$groups_svc->transition( 1, $GROUP_A, \$next, 'test-promote-to-active' );
    if ( is_wp_error( \$t ) ) { echo "transition_failed_at_{\$next}:" . \$t->get_error_code(); exit(1); }
}

\$row = \$wpdb->get_row( \$wpdb->prepare( 'SELECT status FROM wp_minhaj_groups WHERE id = %d', $GROUP_A ), ARRAY_A );
printf( "GROUP_A_STATUS_BEFORE_SUSPEND=%s\n", (string) \$row['status'] );

// Suspend org A.
\$suspend = \$svc->set_status( 1, $ORG_A, 'suspended', 'test-suspend' );
printf( "SET_STATUS_CALL=%s\n", is_wp_error( \$suspend ) ? ( 'err:' . \$suspend->get_error_code() ) : 'ok' );

// Row-level status on group A must be untouched by the org suspension.
\$row = \$wpdb->get_row( \$wpdb->prepare( 'SELECT status FROM wp_minhaj_groups WHERE id = %d', $GROUP_A ), ARRAY_A );
printf( "GROUP_A_STATUS_AFTER=%s\n", (string) \$row['status'] );

// Issuing a NEW link on the suspended org must fail.
\$new_link = \$svc->issue_registration_link( 1, $ORG_A, [ 'label' => 'post-suspend' ] );
printf( "NEW_LINK=%s\n", is_wp_error( \$new_link ) ? ( 'err:' . \$new_link->get_error_code() ) : 'ok' );

// Resolving a token that was valid pre-suspension must now return null.
\$resolved = \$svc->resolve_registration_token( \$token );
printf( "TOKEN_RESOLVE=%s\n", null === \$resolved ? 'null' : 'accepted' );

// Future sessions on the running group must still generate.
\$gen = \$timetable->generate_for_group( 1, $GROUP_A, [
    'anchor_timezone'  => 'Asia/Qatar',
    'weekdays'         => [ 1 ],
    'start_local'      => '09:30',
    'duration_minutes' => 60,
    'weeks_count'      => 3,
    'first_week_start' => '2027-01-04',
] );
if ( is_wp_error( \$gen ) ) {
    printf( "GENERATE=err:%s\n", \$gen->get_error_code() );
} else {
    printf( "GENERATE=ok count=%d\n", count( \$gen ) );
}
PHP
)

SUSPEND_OUT=$(run_wp eval "$SUSPEND_CODE" | tr -d '\r')
echo "  $SUSPEND_OUT"

SUSPEND_RESULT=$(printf '%s' "$SUSPEND_OUT" | grep -oE 'SET_STATUS_CALL=[a-z:_]+' | cut -d= -f2)
GROUP_A_STATUS_BEFORE_SUSPEND=$(printf '%s' "$SUSPEND_OUT" | grep -oE 'GROUP_A_STATUS_BEFORE_SUSPEND=[a-z]+' | cut -d= -f2)
GROUP_A_STATUS_AFTER=$(printf '%s' "$SUSPEND_OUT" | grep -oE 'GROUP_A_STATUS_AFTER=[a-z]+' | cut -d= -f2)
NEW_LINK_RESULT=$(printf '%s' "$SUSPEND_OUT" | grep -oE 'NEW_LINK=[a-z:_]+' | cut -d= -f2)
TOKEN_RESOLVE_RESULT=$(printf '%s' "$SUSPEND_OUT" | grep -oE 'TOKEN_RESOLVE=[a-z]+' | cut -d= -f2)
GENERATE_RESULT=$(printf '%s' "$SUSPEND_OUT" | grep -oE 'GENERATE=[a-z:_]+' | cut -d= -f2)
GENERATE_COUNT=$(printf '%s' "$SUSPEND_OUT" | grep -oE 'count=[0-9]+' | cut -d= -f2)

if [[ "$GROUP_A_STATUS_BEFORE_SUSPEND" == "active" ]]; then
  echo "  ${GREEN}✓ group A promoted to active before the suspend — the scenario matches the spec (running group)${RESET}"
else
  echo "  ${RED}✗ group A status before suspend was $GROUP_A_STATUS_BEFORE_SUSPEND, not active — scenario invalid${RESET}"
  FAIL=1
fi

if [[ "$SUSPEND_RESULT" == "ok" ]]; then
  echo "  ${GREEN}✓ set_status(suspended) succeeded${RESET}"
else
  echo "  ${RED}✗ set_status(suspended) returned $SUSPEND_RESULT${RESET}"
  FAIL=1
fi

if [[ "$GROUP_A_STATUS_AFTER" == "active" ]]; then
  echo "  ${GREEN}✓ group A status stays active after the org suspend — no cascade${RESET}"
else
  echo "  ${RED}✗ group A status changed to $GROUP_A_STATUS_AFTER — suspension cascaded${RESET}"
  FAIL=1
fi

if [[ "$NEW_LINK_RESULT" == "err:org_not_active" ]]; then
  echo "  ${GREEN}✓ issuing a new link on a suspended org fails with org_not_active${RESET}"
else
  echo "  ${RED}✗ new link on suspended org returned $NEW_LINK_RESULT — expected err:org_not_active${RESET}"
  FAIL=1
fi

if [[ "$TOKEN_RESOLVE_RESULT" == "null" ]]; then
  echo "  ${GREEN}✓ a previously-issued token no longer resolves on a suspended org${RESET}"
else
  echo "  ${RED}✗ token still resolved after suspension — new registrations not blocked${RESET}"
  FAIL=1
fi

if [[ "$GENERATE_RESULT" == "ok" ]] && [[ "$GENERATE_COUNT" == "3" ]]; then
  echo "  ${GREEN}✓ generate_for_group on a suspended org still produces 3 sessions${RESET}"
else
  echo "  ${RED}✗ generate_for_group returned $GENERATE_RESULT count=$GENERATE_COUNT — expected ok/3${RESET}"
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
