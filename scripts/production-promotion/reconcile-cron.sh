#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROJECT_ROOT='/var/www/agency'
CURRENT="$PROJECT_ROOT/current"
DRUSH="$CURRENT/vendor/bin/drush"
LOCK_FILE="$PROJECT_ROOT/shared/cron.lock"
MARKER='# agency-drupal-cron'
SCHEDULE='*/15 * * * *'
EXPECTED_LINE="$SCHEDULE cd $CURRENT && flock -n $LOCK_FILE vendor/bin/drush cron -q $MARKER"
SCHEDULER_ACTION="${SCHEDULER_ACTION:-${1:-VERIFY_ONLY}}"

fail() {
  printf '[prod-cron] ERROR: %s\n' "$1" >&2
  exit 1
}

[[ "$SCHEDULER_ACTION" == 'VERIFY_ONLY' ]] \
  || fail 'Functional promotion may only VERIFY_ONLY the PROD scheduler.'

command -v crontab >/dev/null 2>&1 || fail 'crontab is unavailable.'
command -v flock >/dev/null 2>&1 || fail 'flock is unavailable.'
[[ -L "$CURRENT" ]] || fail 'Production current symlink is missing.'
[[ -x "$DRUSH" ]] || fail 'Drush is unavailable on the current release.'

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
