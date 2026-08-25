#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ACTION="${1:-}"
EXPECTED_RELEASE_SHA="${2:-}"
EXPECTED_STATE="${3:-}"
AUTH_COMMENT_ID="${4:-}"
AUTH_BODY_SHA256="${5:-}"
REQUEST_DIR="${6:-}"
AUTHORITY_KIND="${SCHEDULER_AUTHORITY_KIND:-}"

PROJECT_ROOT='/var/www/agency'
CURRENT="$PROJECT_ROOT/current"
DRUSH="$CURRENT/vendor/bin/drush"
PROMOTIONS_DIR="$PROJECT_ROOT/shared/promotions"
LOCK_FILE="$PROJECT_ROOT/shared/cron.lock"
MARKER='# agency-drupal-cron'
SCHEDULE='*/15 * * * *'
EXPECTED_LINE="$SCHEDULE cd $CURRENT && flock -n $LOCK_FILE vendor/bin/drush cron -q $MARKER"
EVIDENCE="$REQUEST_DIR/scheduler-change-evidence.env"

fail() {
  printf '[prod-cron-mutate] ERROR: %s\n' "$1" >&2
  exit 1
}

case "$ACTION" in
  CREATE)
    [[ "$EXPECTED_STATE" == 'ABSENT' ]] || fail 'CREATE requires expected state ABSENT.'
    ;;
  UPDATE)
    [[ "$EXPECTED_STATE" == 'MANAGED_DRIFT' ]] || fail 'UPDATE requires expected state MANAGED_DRIFT.'
    ;;
  REMOVE)
    [[ "$EXPECTED_STATE" == 'CONTROLLED' ]] || fail 'REMOVE requires expected state CONTROLLED.'
    ;;
  *)
    fail 'Scheduler action must be CREATE, UPDATE or REMOVE.'
    ;;
esac

[[ "$AUTHORITY_KIND" == 'OWNER_ISSUE_COMMENT' ]] \
  || fail 'Separate owner issue-comment authority is required.'
[[ "$EXPECTED_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'Expected production release SHA is invalid.'
[[ "$AUTH_COMMENT_ID" =~ ^[0-9]+$ ]] || fail 'Authorization comment id is invalid.'
[[ "$AUTH_BODY_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail 'Authorization body digest is invalid.'
[[ -d "$REQUEST_DIR" && ! -L "$REQUEST_DIR" ]] || fail 'Scheduler request directory is invalid.'
[[ -L "$CURRENT" ]] || fail 'Production current symlink is missing.'
[[ -x "$DRUSH" ]] || fail 'Drush is unavailable on the current release.'
[[ -d "$PROMOTIONS_DIR" ]] || fail 'Production promotion receipts are unavailable.'
command -v crontab >/dev/null 2>&1 || fail 'crontab is unavailable.'
command -v flock >/dev/null 2>&1 || fail 'flock is unavailable.'

current_release="$(readlink -f "$CURRENT")"
[[ -n "$current_release" ]] || fail 'Current production release cannot be resolved.'

matched_receipts=0
current_release_sha=''
shopt -s nullglob
for receipt in "$PROMOTIONS_DIR"/*.env; do
  receipt_release="$(grep -m1 '^release_path=' "$receipt" | cut -d= -f2- || true)"
  [[ "$receipt_release" == "$current_release" ]] || continue
  receipt_sha="$(grep -m1 '^candidate_sha=' "$receipt" | cut -d= -f2- || true)"
  [[ "$receipt_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'Current production receipt has invalid candidate identity.'
  current_release_sha="$receipt_sha"
  matched_receipts=$((matched_receipts + 1))
done
shopt -u nullglob
[[ "$matched_receipts" == '1' ]] || fail 'Current production release must map to exactly one promotion receipt.'
[[ "$current_release_sha" == "$EXPECTED_RELEASE_SHA" ]] || fail 'Current production release identity differs from authorized identity.'

RUNTIME_STATE='INVALID'
SYSTEM_CRON_COUNT='0'
SYSTEMD_CRON_COUNT='0'
MARKER_COUNT='0'
EXACT_COUNT='0'
USER_CRON_COUNT='0'
UNTAGGED_COUNT='0'
CRONTAB_TEXT=''

inspect_state() {
  local interval

  interval="$(
    cd "$CURRENT"
    "$DRUSH" --quiet php:eval \
      'echo (int) \Drupal::config("automated_cron.settings")->get("interval");'
  )"
  interval="$(printf '%s' "$interval" | tr -cd '0-9')"
  [[ "$interval" == '0' ]] || {
    RUNTIME_STATE='INVALID'
    return
  }

  SYSTEM_CRON_COUNT="$(
    {
      grep -ERils --exclude='*.dpkg-*' --exclude='*.bak' \
        '(drush|vendor/bin/drush)[[:space:]]+cron([[:space:]]|$)' \
        /etc/crontab /etc/cron.d /etc/cron.hourly /etc/cron.daily \
        /etc/cron.weekly /etc/cron.monthly 2>/dev/null || true
    } | wc -l | tr -d ' '
  )"
  SYSTEMD_CRON_COUNT="$(
    {
      grep -ERils --exclude='*.wants' \
        '(drush|vendor/bin/drush)[[:space:]]+cron([[:space:]]|$)' \
        /etc/systemd/system 2>/dev/null || true
    } | wc -l | tr -d ' '
  )"

  CRONTAB_TEXT="$(crontab -l 2>/dev/null || true)"
  MARKER_COUNT="$(printf '%s\n' "$CRONTAB_TEXT" | grep -Fc "$MARKER" || true)"
  EXACT_COUNT="$(printf '%s\n' "$CRONTAB_TEXT" | grep -Fxc "$EXPECTED_LINE" || true)"
  USER_CRON_COUNT="$(
    printf '%s\n' "$CRONTAB_TEXT" \
      | grep -Eic '(drush|vendor/bin/drush)[[:space:]]+cron([[:space:]]|$)' \
      || true
  )"
  UNTAGGED_COUNT="$(
    printf '%s\n' "$CRONTAB_TEXT" \
      | grep -Fv "$MARKER" \
      | grep -Eic '(drush|vendor/bin/drush)[[:space:]]+cron([[:space:]]|$)' \
      || true
  )"

  if [[ "$SYSTEM_CRON_COUNT" != '0' || "$SYSTEMD_CRON_COUNT" != '0' || "$UNTAGGED_COUNT" != '0' ]]; then
    RUNTIME_STATE='UNMANAGED'
  elif [[ "$MARKER_COUNT" == '0' && "$USER_CRON_COUNT" == '0' ]]; then
    RUNTIME_STATE='ABSENT'
  elif [[ "$MARKER_COUNT" == '1' && "$USER_CRON_COUNT" == '1' && "$EXACT_COUNT" == '1' ]]; then
    RUNTIME_STATE='CONTROLLED'
  elif [[ "$MARKER_COUNT" == '1' && "$USER_CRON_COUNT" == '1' && "$EXACT_COUNT" == '0' ]]; then
    RUNTIME_STATE='MANAGED_DRIFT'
  else
    RUNTIME_STATE='UNMANAGED'
  fi
}

inspect_state
[[ "$RUNTIME_STATE" == "$EXPECTED_STATE" ]] \
  || fail "Scheduler state is $RUNTIME_STATE, expected authorized state $EXPECTED_STATE."

before_state="$RUNTIME_STATE"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

case "$ACTION" in
  CREATE)
    printf '%s\n' "$CRONTAB_TEXT" > "$tmp"
    printf '%s\n' "$EXPECTED_LINE" >> "$tmp"
    ;;
  UPDATE)
    printf '%s\n' "$CRONTAB_TEXT" | grep -Fv "$MARKER" > "$tmp" || true
    printf '%s\n' "$EXPECTED_LINE" >> "$tmp"
    ;;
  REMOVE)
    printf '%s\n' "$CRONTAB_TEXT" | grep -Fv "$MARKER" > "$tmp" || true
    ;;
esac

crontab "$tmp"
inspect_state

case "$ACTION" in
  CREATE|UPDATE)
    [[ "$RUNTIME_STATE" == 'CONTROLLED' ]] || fail 'Authorized scheduler mutation did not converge to CONTROLLED.'
    ;;
  REMOVE)
    [[ "$RUNTIME_STATE" == 'ABSENT' ]] || fail 'Authorized scheduler removal did not converge to ABSENT.'
    ;;
esac

after_state="$RUNTIME_STATE"
{
  printf 'schema_version=1\n'
  printf 'scheduler_action=%s\n' "$ACTION"
  printf 'production_release_sha=%s\n' "$EXPECTED_RELEASE_SHA"
  printf 'expected_scheduler_state=%s\n' "$EXPECTED_STATE"
  printf 'scheduler_state_before=%s\n' "$before_state"
  printf 'scheduler_state_after=%s\n' "$after_state"
  printf 'authorization_kind=OWNER_ISSUE_COMMENT\n'
  printf 'authorization_comment_id=%s\n' "$AUTH_COMMENT_ID"
  printf 'authorization_body_sha256=%s\n' "$AUTH_BODY_SHA256"
  printf 'production_scheduler_marker=%s\n' "$MARKER"
  printf 'production_scheduler_interval_minutes=15\n'
  printf 'production_scheduler_flock=%s\n' "$LOCK_FILE"
  printf 'changed_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} > "$EVIDENCE"
chmod 600 "$EVIDENCE"

printf 'scheduler_action=%s\n' "$ACTION"
printf 'scheduler_state_before=%s\n' "$before_state"
printf 'scheduler_state_after=%s\n' "$after_state"
