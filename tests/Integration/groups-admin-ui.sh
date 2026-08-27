#!/usr/bin/env bash
# Manual test of the Groups admin UI — spec-groups-v1 §8.
#
# Creates a group, adds 5 members, and tries to add a 6th. Every step
# hits real HTTP endpoints on the running wp-env instance, with an
# authenticated admin cookie, so the entire nonce + capability + service
# pipeline is exercised.

set -euo pipefail

WP_ENV=${WP_ENV:-wp-env}
BASE_URL=${BASE_URL:-http://localhost:8888}
COOKIE_JAR=$(mktemp)
GREEN=$'\033[32m'
RED=$'\033[31m'
BOLD=$'\033[1m'
DIM=$'\033[2m'
RESET=$'\033[0m'

trap 'rm -f "$COOKIE_JAR"' EXIT

wpc() {
  "$WP_ENV" run cli wp "$@" 2>/dev/null
}

# --------------------------------------------------------------- setup ------

echo "${BOLD}== Reset groups tables ==${RESET}"
wpc db query "DELETE FROM wp_minhaj_group_audit;
DELETE FROM wp_minhaj_group_members;
DELETE FROM wp_minhaj_groups;" >/dev/null

echo "${BOLD}== Seed six student users ==${RESET}"
STAMP=$(date +%s)
STUDENTS=()
for slot in 1 2 3 4 5 6; do
  login="student_${STAMP}_${slot}"
  email="${login}@example.test"
  UID_OUT=$(wpc user create "$login" "$email" --role=minhaj_student --user_pass=x --porcelain 2>/dev/null | tr -d '\r' | tail -1)
  if [[ -z "$UID_OUT" || ! "$UID_OUT" =~ ^[0-9]+$ ]]; then
    echo "${RED}✗ failed to create student '$login'${RESET}"
    exit 1
  fi
  STUDENTS+=( "$UID_OUT" )
done
echo "  student ids: ${STUDENTS[*]}"

# --------------------------------------------------------------- login ------

echo "${BOLD}== Log in as admin ==${RESET}"
ADMIN_USER=${ADMIN_USER:-admin}
ADMIN_PASS=${ADMIN_PASS:-password}

# WordPress needs the "testcookie" round-trip to accept a login POST, so we
# send it in the same request rather than doing a real two-step handshake.
LOGIN_STATUS=$(curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -L \
  -b 'wordpress_test_cookie=WP+Cookie+check' \
  --data-urlencode "log=$ADMIN_USER" \
  --data-urlencode "pwd=$ADMIN_PASS" \
  --data-urlencode 'wp-submit=Log In' \
  --data-urlencode "redirect_to=${BASE_URL}/wp-admin/" \
  --data-urlencode 'testcookie=1' \
  -o /dev/null -w "%{http_code} %{url_effective}" \
  "${BASE_URL}/wp-login.php")

curl_admin() {
  curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$@"
}

if ! printf '%s' "$LOGIN_STATUS" | grep -q '/wp-admin/'; then
  echo "${RED}✗ login did not land in /wp-admin/ — got: $LOGIN_STATUS${RESET}"
  exit 1
fi
echo "  logged in: $LOGIN_STATUS"

# --------------------------------------------------------------- create ------

echo "${BOLD}== Fetch new-group form to grab a fresh nonce ==${RESET}"
NEW_URL="${BASE_URL}/wp-admin/admin.php?page=minhaj-groups&view=new"
NEW_HTML=$(curl_admin -L "$NEW_URL")
NONCE=$(printf '%s' "$NEW_HTML" | grep -oE 'name="_wpnonce" value="[^"]+"' | head -1 | sed 's/.*value="\([^"]*\)".*/\1/')

if [[ -z "$NONCE" ]]; then
  echo "${RED}✗ failed to grab nonce from the new-group form${RESET}"
  echo "-- first 20 lines of received HTML --"
  printf '%s' "$NEW_HTML" | head -20
  exit 1
fi
echo "  nonce: ${NONCE:0:8}…"

echo "${BOLD}== Submit create group (POST) ==${RESET}"
CREATE_URL="${BASE_URL}/wp-admin/admin.php?page=minhaj-groups"
CREATE_RESP=$(curl_admin -X POST "$CREATE_URL" \
  --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode '_wp_http_referer=/wp-admin/admin.php?page=minhaj-groups&view=new' \
  --data-urlencode 'minhaj_action=create' \
  --data-urlencode 'code=UI-TEST-01' \
  --data-urlencode 'type=group' \
  --data-urlencode 'level=A1' \
  --data-urlencode 'teaching_language=nl' \
  --data-urlencode 'timezone=UTC' \
  --data-urlencode 'capacity_min=3' \
  --data-urlencode 'capacity_max=5' \
  --data-urlencode 'batch_id=0' \
  -o /dev/null -w "%{http_code} %{redirect_url}")

echo "  → $CREATE_RESP"
REDIRECT=$(printf '%s' "$CREATE_RESP" | awk '{print $2}')
GROUP_ID=$(printf '%s' "$REDIRECT" | grep -oE 'group_id=[0-9]+' | head -1 | cut -d= -f2 || echo '')
if [[ -z "$GROUP_ID" ]]; then
  echo "${RED}✗ create redirect missing group_id — dumping redirect${RESET}"
  echo "$REDIRECT"
  exit 1
fi
echo "  ${GREEN}✓ created group id=$GROUP_ID${RESET}"

# --------------------------------------------------------------- add members ------

fetch_nonce_for_group() {
  local group_id=$1
  local html
  html=$(curl_admin -L "${BASE_URL}/wp-admin/admin.php?page=minhaj-groups&view=single&group_id=${group_id}")
  # The single page has many nonce fields (one per form). They're all
  # for the same action so any one works.
  printf '%s' "$html" | grep -oE 'name="_wpnonce" value="[^"]+"' | head -1 | sed 's/.*value="\([^"]*\)".*/\1/'
}

for i in 1 2 3 4 5; do
  STUDENT_ID=${STUDENTS[$i-1]}
  NONCE=$(fetch_nonce_for_group "$GROUP_ID")
  RESP=$(curl_admin -X POST "${BASE_URL}/wp-admin/admin.php?page=minhaj-groups" \
    --data-urlencode "_wpnonce=$NONCE" \
    --data-urlencode 'minhaj_action=add_member' \
    --data-urlencode "group_id=$GROUP_ID" \
    --data-urlencode "student_id=$STUDENT_ID" \
    -o /dev/null -w "%{http_code} %{redirect_url}")
  CODE=$(printf '%s' "$RESP" | awk '{print $1}')
  URL=$(printf '%s' "$RESP" | awk '{print $2}')
  NOTICE=$(printf '%s' "$URL" | grep -oE 'minhaj_notice=[a-z_]+' | head -1 | cut -d= -f2 || echo '?')
  echo "  add student ${STUDENT_ID} → $CODE notice=$NOTICE"
done

ACTIVE_AFTER=$(wpc db query "SELECT COUNT(*) FROM wp_minhaj_group_members WHERE group_id=$GROUP_ID AND status='active'" --skip-column-names | tr -d '[:space:]')
if [[ "$ACTIVE_AFTER" != "5" ]]; then
  echo "${RED}✗ expected 5 active members, got $ACTIVE_AFTER${RESET}"
  exit 1
fi
echo "  ${GREEN}✓ five active members recorded${RESET}"

# --------------------------------------------------------------- sixth ------

echo
echo "${BOLD}== Attempt to add the sixth student (should fail with human-readable notice) ==${RESET}"
SIXTH=${STUDENTS[5]}
NONCE=$(fetch_nonce_for_group "$GROUP_ID")
RESP=$(curl_admin -X POST "${BASE_URL}/wp-admin/admin.php?page=minhaj-groups" \
  --data-urlencode "_wpnonce=$NONCE" \
  --data-urlencode 'minhaj_action=add_member' \
  --data-urlencode "group_id=$GROUP_ID" \
  --data-urlencode "student_id=$SIXTH" \
  -o /dev/null -w "%{http_code} %{redirect_url}")
CODE=$(printf '%s' "$RESP" | awk '{print $1}')
URL=$(printf '%s' "$RESP" | awk '{print $2}')
NOTICE=$(printf '%s' "$URL" | grep -oE 'minhaj_notice=[a-z_]+' | head -1 | cut -d= -f2 || echo '')

echo "  HTTP $CODE"
echo "  redirected to notice code: ${BOLD}${NOTICE}${RESET}"

if [[ "$NOTICE" != "group_full" ]]; then
  echo "${RED}✗ expected notice=group_full, got '$NOTICE'${RESET}"
  exit 1
fi

# --------------------------------------------------------------- fetch HTML ------

echo
echo "${BOLD}== Follow the redirect and extract the rendered admin notice ==${RESET}"
SINGLE_HTML=$(curl_admin -L "$URL")

# Extract the notice-error block.
NOTICE_BLOCK=$(printf '%s' "$SINGLE_HTML" | tr -d '\n' | grep -oE '<div class="notice notice-error is-dismissible"><p>[^<]+</p></div>' | head -1)

echo "${DIM}--- rendered <div class=\"notice notice-error\"> block ---${RESET}"
echo "  $NOTICE_BLOCK"
echo "${DIM}---------------------------------------------------------${RESET}"

if printf '%s' "$NOTICE_BLOCK" | grep -q "group is full"; then
  echo "  ${GREEN}✓ user sees the sentence 'The group is full — no free seats.'${RESET}"
else
  echo "${RED}✗ notice block did not carry the group-full message${RESET}"
  exit 1
fi

# --------------------------------------------------------------- state ------

echo
echo "${BOLD}== Final DB state ==${RESET}"
wpc db query "SELECT id, student_id, status, seat_index, active_seat_index FROM wp_minhaj_group_members WHERE group_id=$GROUP_ID ORDER BY id"

ACTIVE_END=$(wpc db query "SELECT COUNT(*) FROM wp_minhaj_group_members WHERE group_id=$GROUP_ID AND status='active'" --skip-column-names | tr -d '[:space:]')

echo
echo "${BOLD}== Assertions ==${RESET}"
if [[ "$ACTIVE_END" == "5" ]]; then
  echo "  ${GREEN}✓ active member count = 5 (capacity_max), sixth attempt did NOT leak a seat${RESET}"
else
  echo "  ${RED}✗ active count changed to $ACTIVE_END — sixth attempt leaked${RESET}"
  exit 1
fi

echo
echo "${GREEN}${BOLD}ADMIN UI MANUAL TEST PASSED${RESET}"
