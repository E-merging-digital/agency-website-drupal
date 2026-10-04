#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
LC_ALL=C
export PATH LC_ALL

APPROVED_PLAN="${1:-}"
EXPECTED_HELPER_SHA256="${2:-}"
EXPECTED_PLAN_SHA256="${3:-}"
EXPECTED_MAIN="${4:-}"
EXPECTED_DIGEST="${5:-}"

HELPER='/usr/local/sbin/agency-prod-php85-1353-apply'
PLAN_SCRIPT='/usr/local/lib/agency-prod-php85-1353/remote-plan.sh'

[[ "$#" -eq 5 ]]
[[ -f "$APPROVED_PLAN" && ! -L "$APPROVED_PLAN" ]]
[[ "$EXPECTED_HELPER_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$EXPECTED_PLAN_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$EXPECTED_MAIN" =~ ^[0-9a-f]{40}$ ]]
[[ "$EXPECTED_DIGEST" =~ ^[0-9a-f]{64}$ ]]

emit_bootstrap_required() {
  jq -n \
    --arg main "$EXPECTED_MAIN" \
    --arg digest "$EXPECTED_DIGEST" \
    '{
      STATUS:"FAIL",ISSUE:1353,TARGET:"PROD",MODE:"APPLY",
      MAIN_SHA:$main,PLAN_DIGEST:$digest,
      FAILURE_STATE:"HUMAN_BOOTSTRAP_REQUIRED",
      CAPABILITY_READY:"NO",
      STALE_PLAN:"NOT_REACHED",
      EXACT_PACKAGE_SIMULATION:"NOT_REACHED",
      PACKAGE_APPLY:"NOT_STARTED",
      PHP85_FPM:"NOT_STARTED",
      PHP85_OPCACHE_AVAILABLE:"NOT_REACHED",
      PHP84_FPM:"ACTIVE_ROLLBACK_AVAILABLE",
      PHP84_REMOVAL:"NONE",
      NGINX_SOCKET_ONLY_DELTA:"NOT_REACHED",
      WEB_RUNTIME_PHP85:"NOT_REACHED",
      DRUPAL_HEALTH:"PRESERVED_PRE_APPLY",
      PUBLIC_HEALTH:"PRESERVED_PRE_APPLY",
      ROLLBACK:"NOT_REQUIRED",
      DRUPAL_DEPLOY:"NONE",
      DRUPAL_CONFIG_IMPORT:"NONE",
      DB_MUTATION:"NONE",
      OS_UPGRADE:"NONE",
      MARIADB_CHANGE:"NONE"
    }'
}

exact_file_ready() {
  local path="$1"
  local expected_hash="$2"
  local expected_meta="$3"
  [[ -f "$path" && ! -L "$path" ]] || return 1
  [[ "$(stat -c '%U:%G:%a' "$path" 2>/dev/null)" == "$expected_meta" ]] || return 1
  [[ "$(sha256sum "$path" 2>/dev/null | awk '{print $1}')" == "$expected_hash" ]]
}

if ! exact_file_ready "$HELPER" "$EXPECTED_HELPER_SHA256" 'root:root:755' \
  || ! exact_file_ready "$PLAN_SCRIPT" "$EXPECTED_PLAN_SHA256" 'root:root:755'; then
  emit_bootstrap_required
  exit 78
fi

ready_out="$(mktemp)"
ready_err="$(mktemp)"
apply_out="$(mktemp)"
apply_err="$(mktemp)"
cleanup() { rm -f -- "$ready_out" "$ready_err" "$apply_out" "$apply_err"; }
trap cleanup EXIT

set +e
sudo -k -n -- "$HELPER" READY >"$ready_out" 2>"$ready_err"
ready_rc=$?
set -e
if [[ "$ready_rc" -ne 0 ]]; then
  emit_bootstrap_required
  exit 78
fi

mapfile -t ready_lines < "$ready_out"
if [[ "${#ready_lines[@]}" -ne 6 \
  || "${ready_lines[0]}" != 'STATUS=PASS' \
  || "${ready_lines[1]}" != 'ISSUE=1353' \
  || "${ready_lines[2]}" != 'CAPABILITY_READY=YES' \
  || "${ready_lines[3]}" != 'HELPER_SCOPE=FIXED_PURPOSE' \
  || "${ready_lines[4]}" != 'PRIVILEGED_PATH_ARGUMENTS=NONE' \
  || "${ready_lines[5]}" != 'PHP_MUTATION=NONE' ]]; then
  emit_bootstrap_required
  exit 78
fi

set +e
sudo -k -n -- "$HELPER" APPLY < "$APPROVED_PLAN" >"$apply_out" 2>"$apply_err"
apply_rc=$?
set -e

if [[ -s "$apply_out" ]]; then
  cat "$apply_out"
else
  jq -n \
    --arg main "$EXPECTED_MAIN" \
    --arg digest "$EXPECTED_DIGEST" \
    '{
      STATUS:"FAIL",ISSUE:1353,TARGET:"PROD",MODE:"APPLY",
      MAIN_SHA:$main,PLAN_DIGEST:$digest,
      FAILURE_STATE:"APPLY_FAILED_NO_RECEIPT",
      ROLLBACK:"NOT_REQUIRED",
      PHP84_REMOVAL:"NONE",
      DRUPAL_DEPLOY:"NONE",DRUPAL_CONFIG_IMPORT:"NONE",
      DB_MUTATION:"NONE",OS_UPGRADE:"NONE",MARIADB_CHANGE:"NONE"
    }'
fi
exit "$apply_rc"
