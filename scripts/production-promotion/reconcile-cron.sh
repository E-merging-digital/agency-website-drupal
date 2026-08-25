#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PROJECT_ROOT='/var/www/agency'
CURRENT="$PROJECT_ROOT/current"
DRUSH="$CURRENT/vendor/bin/drush"
LOCK_FILE="$PROJECT_ROOT/shared/cron.lock"
MARKER='# agency-drupal-cron'
SCHEDULE='*/15 * * * *'

fail() {
  printf '[prod-cron] ERROR: %s\n' "$1" >&2
  exit 1
}

command -v crontab >/dev/null 2>&1 || fail 'crontab is unavailable.'
command -v flock >/dev/null 2>&1 || fail 'flock is unavailable.'
[[ -L "$CURRENT" ]] || fail 'Production current symlink is missing.'
[[ -x "$DRUSH" ]] || fail 'Drush is unavailable on the current release.'

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
[[ "$system_cron_count" == '0' ]] || fail 'An unmanaged system cron Drupal scheduler already exists.'
[[ "$systemd_cron_count" == '0' ]] || fail 'An unmanaged systemd Drupal scheduler already exists.'

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
(crontab -l 2>/dev/null || true) | grep -Fv "$MARKER" > "$tmp" || true

untagged_count="$(
  grep -Eic '(^|[[:space:]])([^#]*)(drush|vendor/bin/drush)[[:space:]]+cron([[:space:]]|$)' "$tmp" || true
)"
[[ "$untagged_count" == '0' ]] || fail 'An unmanaged deploy-user Drupal cron scheduler already exists.'

printf '%s cd %s && flock -n %s vendor/bin/drush cron -q %s\n' \
  "$SCHEDULE" "$CURRENT" "$LOCK_FILE" "$MARKER" >> "$tmp"
crontab "$tmp"

marker_count="$(crontab -l | grep -Fc "$MARKER" || true)"
[[ "$marker_count" == '1' ]] || fail 'Controlled Drupal cron entry did not converge to exactly one entry.'

controlled_count="$(
  crontab -l \
    | grep -Eic '(drush|vendor/bin/drush)[[:space:]]+cron([[:space:]]|$)' \
    || true
)"
[[ "$controlled_count" == '1' ]] || fail 'Drupal cron did not converge to exactly one deploy-user scheduler.'

printf 'production_scheduler=DEPLOY_USER_CRONTAB\n'
printf 'production_scheduler_entries=1\n'
printf 'production_scheduler_interval_minutes=15\n'
