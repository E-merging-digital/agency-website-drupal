#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROJECT_ROOT='/var/www/agency'
RELEASES_DIR="$PROJECT_ROOT/releases"
CURRENT="$PROJECT_ROOT/current"
DRUSH="$CURRENT/vendor/bin/drush"
PROMOTIONS_DIR="$PROJECT_ROOT/shared/promotions"
LOCK_FILE="$PROJECT_ROOT/shared/cron.lock"
MARKER='# agency-drupal-cron'
SCHEDULE='*/15 * * * *'
EXPECTED_LINE="$SCHEDULE cd $CURRENT && flock -n $LOCK_FILE vendor/bin/drush cron -q $MARKER"
SCHEDULER_ACTION="${SCHEDULER_ACTION:-${1:-VERIFY_ONLY}}"
SCHEDULER_CONTEXT="${SCHEDULER_CONTEXT:-STANDALONE}"
SCHEDULER_EXPECTED_CANDIDATE_SHA="${SCHEDULER_EXPECTED_CANDIDATE_SHA:-}"
SCHEDULER_EXPECTED_CURRENT_RELEASE="${SCHEDULER_EXPECTED_CURRENT_RELEASE:-}"

fail() {
  printf '[prod-cron] ERROR: %s\n' "$1" >&2
  exit 1
}

[[ "$SCHEDULER_ACTION" == 'VERIFY_ONLY' ]] \
  || fail 'Functional promotion may only VERIFY_ONLY the PROD scheduler.'
[[ "$SCHEDULER_CONTEXT" == 'STANDALONE' || "$SCHEDULER_CONTEXT" == 'IN_FLIGHT_PROMOTION' ]] \
  || fail 'Scheduler verification context is invalid.'

command -v crontab >/dev/null 2>&1 || fail 'crontab is unavailable.'
command -v flock >/dev/null 2>&1 || fail 'flock is unavailable.'
[[ -L "$CURRENT" ]] || fail 'Production current symlink is missing.'
[[ -x "$DRUSH" ]] || fail 'Drush is unavailable on the current release.'
[[ -d "$PROMOTIONS_DIR" ]] || fail 'Production promotion receipts are unavailable.'

current_release="$(readlink -f "$CURRENT")"
[[ -n "$current_release" ]] || fail 'Current production release cannot be resolved.'
current_release_sha=''

case "$SCHEDULER_CONTEXT" in
  STANDALONE)
    [[ -z "$SCHEDULER_EXPECTED_CANDIDATE_SHA" && -z "$SCHEDULER_EXPECTED_CURRENT_RELEASE" ]] \
      || fail 'Standalone scheduler verification does not accept in-flight identity.'

    matched_receipts=0
    shopt -s nullglob
    for receipt in "$PROMOTIONS_DIR"/*.env; do
      receipt_release="$(grep -m1 '^release_path=' "$receipt" | cut -d= -f2- || true)"
      [[ "$receipt_release" == "$current_release" ]] || continue
      receipt_sha="$(grep -m1 '^candidate_sha=' "$receipt" | cut -d= -f2- || true)"
      [[ "$receipt_sha" =~ ^[0-9a-f]{40}$ ]] \
        || fail 'Current production receipt has invalid candidate identity.'
      current_release_sha="$receipt_sha"
      matched_receipts=$((matched_receipts + 1))
    done
    shopt -u nullglob
    [[ "$matched_receipts" == '1' ]] \
      || fail 'Current production release must map to exactly one promotion receipt.'
    ;;
  IN_FLIGHT_PROMOTION)
    [[ "$SCHEDULER_EXPECTED_CANDIDATE_SHA" =~ ^[0-9a-f]{40}$ ]] \
      || fail 'In-flight promotion candidate identity is invalid.'
    [[ -n "$SCHEDULER_EXPECTED_CURRENT_RELEASE" ]] \
      || fail 'In-flight promotion expected current release is missing.'

    expected_current_release="$(readlink -f -- "$SCHEDULER_EXPECTED_CURRENT_RELEASE")"
    [[ -n "$expected_current_release" ]] \
      || fail 'In-flight promotion expected current release cannot be resolved.'
    [[ "$SCHEDULER_EXPECTED_CURRENT_RELEASE" == "$expected_current_release" ]] \
      || fail 'In-flight promotion expected current release must be canonical.'
    [[ "$expected_current_release" == "$RELEASES_DIR/"* ]] \
      || fail 'In-flight promotion expected current release must remain under the production releases directory.'
    [[ "$(dirname -- "$expected_current_release")" == "$RELEASES_DIR" ]] \
      || fail 'In-flight promotion expected current release must be a direct production release path.'

    expected_release_name="${expected_current_release##*/}"
    [[ "$expected_release_name" =~ ^[0-9]{14}-[0-9a-f]{12}$ ]] \
      || fail 'In-flight promotion expected current release identity is invalid.'
    [[ "${expected_release_name#*-}" == "${SCHEDULER_EXPECTED_CANDIDATE_SHA:0:12}" ]] \
      || fail 'In-flight promotion release identity differs from expected candidate identity.'
    [[ "$current_release" == "$expected_current_release" ]] \
      || fail 'Current production release differs from in-flight promotion expectation.'

    current_release_sha="$SCHEDULER_EXPECTED_CANDIDATE_SHA"
    ;;
esac

interval="$(
  cd "$CURRENT"
  "$DRUSH" --quiet php:eval \
    'echo (int) \Drupal::config("automated_cron.settings")->get("interval");'
)"
interval="$(printf '%s' "$interval" | tr -cd '0-9')"
[[ "$interval" == '0' ]] || fail 'Drupal automated cron must remain disabled in PROD.'

system_cron_count="$(
  {
    grep -ERils --exclude='*.dpkg-*' --exclude='*.bak' \
      '(drush|vendor/bin/drush)[[:space:]]+cron([[:space:]]|$)' \
      /etc/crontab /etc/cron.d /etc/cron.hourly /etc/cron.daily \
      /etc/cron.weekly /etc/cron.monthly 2>/dev/null || true
  } | wc -l | tr -d ' '
)"
systemd_cron_count="$(
  {
    grep -ERils --exclude='*.wants' \
      '(drush|vendor/bin/drush)[[:space:]]+cron([[:space:]]|$)' \
      /etc/systemd/system 2>/dev/null || true
  } | wc -l | tr -d ' '
)"
[[ "$system_cron_count" == '0' ]] || fail 'An unmanaged system cron Drupal scheduler exists.'
[[ "$systemd_cron_count" == '0' ]] || fail 'An unmanaged systemd Drupal scheduler exists.'

crontab_text="$(crontab -l 2>/dev/null || true)"
marker_count="$(printf '%s\n' "$crontab_text" | grep -Fc "$MARKER" || true)"
exact_count="$(printf '%s\n' "$crontab_text" | grep -Fxc "$EXPECTED_LINE" || true)"
controlled_count="$(
  printf '%s\n' "$crontab_text" \
    | grep -Eic '(drush|vendor/bin/drush)[[:space:]]+cron([[:space:]]|$)' \
    || true
)"
untagged_count="$(
  printf '%s\n' "$crontab_text" \
    | grep -Fv "$MARKER" \
    | grep -Eic '(drush|vendor/bin/drush)[[:space:]]+cron([[:space:]]|$)' \
    || true
)"

[[ "$marker_count" == '1' ]] || fail 'Controlled Agency scheduler marker count is not exactly one.'
[[ "$exact_count" == '1' ]] || fail 'Controlled Agency scheduler does not match the exact expected contract.'
[[ "$controlled_count" == '1' ]] || fail 'Deploy-user Drupal scheduler count is not exactly one.'
[[ "$untagged_count" == '0' ]] || fail 'An unmanaged deploy-user Drupal cron scheduler exists.'

printf 'production_current_release=%s\n' "$current_release"
printf 'production_current_release_sha=%s\n' "$current_release_sha"
printf 'production_scheduler_context=%s\n' "$SCHEDULER_CONTEXT"
printf 'production_scheduler_action=VERIFY_ONLY\n'
printf 'production_scheduler=DEPLOY_USER_CRONTAB\n'
printf 'production_scheduler_entries=1\n'
printf 'production_scheduler_marker=%s\n' "$MARKER"
printf 'production_scheduler_interval_minutes=15\n'
printf 'production_scheduler_flock=%s\n' "$LOCK_FILE"
printf 'production_scheduler_runtime_state=CONTROLLED\n'
printf 'prod_automated_cron_interval=0\n'
printf 'system_cron_drush_cron_files=0\n'
printf 'systemd_drush_cron_units=0\n'
