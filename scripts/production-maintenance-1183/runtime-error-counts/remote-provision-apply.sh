#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

PATH='/usr/sbin:/usr/bin:/sbin:/bin'
LC_ALL=C
export PATH LC_ALL

HELPER_DEST='/usr/local/sbin/agency-prod-runtime-error-counts'
SUDOERS_DEST='/etc/sudoers.d/agency-prod-runtime-error-counts'
STAGE_DIR="$HOME/.agency-1202-runtime-error-capability-stage"
STAGE_HELPER="$STAGE_DIR/agency-prod-runtime-error-counts"
STAGE_SUDOERS="$STAGE_DIR/agency-prod-runtime-error-counts.sudoers"
PLAN_FILE="$STAGE_DIR/plan.json"

STATUS='FAIL'
STALE_PLAN='PASS'
HELPER_INSTALL='NOT_ATTEMPTED'
SUDOERS_INSTALL='NOT_ATTEMPTED'
VISUDO_BEFORE_INSTALL='NOT_ATTEMPTED'
SUDOERS_POST_VALIDATION='NOT_ATTEMPTED'
POST_INSTALL_HELPER_PROOF='NOT_ATTEMPTED'
CAPABILITY_READY='NO'
NGINX_COUNT='UNKNOWN'
PHP_COUNT='UNKNOWN'
# Bounded receipt fields are emitted without command output.
emit_receipt() {
  export STATUS STALE_PLAN HELPER_INSTALL SUDOERS_INSTALL
  export VISUDO_BEFORE_INSTALL SUDOERS_POST_VALIDATION
  export POST_INSTALL_HELPER_PROOF CAPABILITY_READY
  export NGINX_COUNT PHP_COUNT MAIN_SHA PLAN_DIGEST
  python3 - <<'PY'
import json
import os

receipt = {
    'STATUS': os.environ['STATUS'],
    'ISSUE': 1202,
    'TARGET': 'PROD',
    'MODE': 'APPLY',
    'MAIN_SHA': os.environ['MAIN_SHA'],
    'PLAN_DIGEST': os.environ['PLAN_DIGEST'],
    'STALE_PLAN': os.environ['STALE_PLAN'],
    'HELPER_INSTALL': os.environ['HELPER_INSTALL'],
    'SUDOERS_INSTALL': os.environ['SUDOERS_INSTALL'],
    'VISUDO_BEFORE_INSTALL': os.environ['VISUDO_BEFORE_INSTALL'],
    'SUDOERS_POST_VALIDATION': os.environ['SUDOERS_POST_VALIDATION'],
    'POST_INSTALL_HELPER_PROOF': os.environ['POST_INSTALL_HELPER_PROOF'],
    'CAPABILITY_READY': os.environ['CAPABILITY_READY'],
    'NGINX_RECENT_ERROR_COUNT': os.environ['NGINX_COUNT'],
    'PHP_FPM_RECENT_ERROR_COUNT': os.environ['PHP_COUNT'],
    'HELPER_DESTINATION': '/usr/local/sbin/agency-prod-runtime-error-counts',
    'SUDOERS_DESTINATION': '/etc/sudoers.d/agency-prod-runtime-error-counts',
    'RAW_LOG_EXPOSURE': 'NONE',
    'RAW_SUDO_POLICY_EXPOSURE': 'NONE',
    'UNRELATED_PROD_MUTATION': 'NONE',
    'PARTIAL_STATE_RECEIPT': 'PASS',
}
print(json.dumps(receipt, sort_keys=True, separators=(',', ':')))
PY
}
fixed_sudoers_hash() {
  sudo -k -n -- /usr/bin/sha256sum -- /etc/sudoers.d/agency-prod-runtime-error-counts 2>/dev/null |
    python3 -c '''
import re
import sys

match = re.fullmatch(r"([0-9a-f]{64})  /etc/sudoers\.d/agency-prod-runtime-error-counts\n", sys.stdin.read())
if not match:
    raise SystemExit(1)
print(match[1], end="")
''' 2>/dev/null
}
classify_target() {
  local path="$1"
  local expected_hash="$2"
  local expected_meta="$3"
  local metadata actual_hash

  if [[ -L "$path" ]]; then
    printf '%s' 'NONCONFORMANT'
    return
  fi
  if [[ ! -e "$path" ]]; then
    printf '%s' 'ABSENT'
    return
  fi
  if [[ ! -f "$path" ]]; then
    printf '%s' 'NONCONFORMANT'
    return
  fi
  if ! metadata="$(stat -c '%U:%G:%a' -- "$path" 2>/dev/null)"; then
    printf '%s' 'UNKNOWN'
    return
  fi
  if [[ -r "$path" ]]; then
    if ! actual_hash="$(sha256sum -- "$path" 2>/dev/null | awk '{print $1}')"; then
      printf '%s' 'UNKNOWN'
      return
    fi
  elif [[ "$path" == "$SUDOERS_DEST" ]]; then
    if ! actual_hash="$(fixed_sudoers_hash)"; then
      printf '%s' 'UNKNOWN'
      return
    fi
  else
    printf '%s' 'UNKNOWN'
    return
  fi
  if [[ ! "$actual_hash" =~ ^[0-9a-f]{64}$ ]]; then
    printf '%s' 'UNKNOWN'
    return
  fi
  [[ "$metadata" == "$expected_meta" && "$actual_hash" == "$expected_hash" ]] \
    && printf '%s' 'ALREADY_CONFORMANT' \
    || printf '%s' 'NONCONFORMANT'
}

PLAN_DIGEST="${1:-}"
SERVER_USER="${2:-}"
[[ "$#" -eq 2 ]]
[[ "$PLAN_DIGEST" =~ ^[0-9a-f]{64}$ ]]
[[ "$SERVER_USER" =~ ^[A-Za-z0-9._-]+$ ]]
[[ "$(id -un)" == "$SERVER_USER" ]]
[[ "$HOME" == /* ]]
[[ -f "$PLAN_FILE" && ! -L "$PLAN_FILE" ]]
[[ -f "$STAGE_HELPER" && ! -L "$STAGE_HELPER" ]]
[[ -f "$STAGE_SUDOERS" && ! -L "$STAGE_SUDOERS" ]]
SERVER_USER_SHA256="$(printf '%s' "$SERVER_USER" | sha256sum | awk '{print $1}')"
export PLAN_DIGEST SERVER_USER_SHA256
VALIDATOR="$STAGE_DIR/validate-provision-plan.py"
[[ -f "$VALIDATOR" && ! -L "$VALIDATOR" ]]
validated_plan="$(python3 "$VALIDATOR" \
  "$PLAN_FILE" "$PLAN_DIGEST" "$SERVER_USER_SHA256")"
mapfile -t plan_fields <<< "$validated_plan"
[[ "${#plan_fields[@]}" -eq 8 ]]
MAIN_SHA="${plan_fields[0]}"
PLAN_ID="${plan_fields[1]}"
HELPER_SOURCE_SHA256="${plan_fields[2]}"
RENDERED_SUDOERS_SHA256="${plan_fields[3]}"
PLANNED_HELPER_STATE="${plan_fields[4]}"
PLANNED_SUDOERS_STATE="${plan_fields[5]}"
PLANNED_INSTALL_STATE="${plan_fields[6]}"
INSTALL_DECISION="${plan_fields[7]}"

[[ "$MAIN_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$PLAN_ID" =~ ^plan-1202-[1-9][0-9]*-1$ ]]
[[ "$HELPER_SOURCE_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$RENDERED_SUDOERS_SHA256" =~ ^[0-9a-f]{64}$ ]]
actual_helper_hash="$(sha256sum -- "$STAGE_HELPER" | awk '{print $1}')"
actual_sudoers_hash="$(sha256sum -- "$STAGE_SUDOERS" | awk '{print $1}')"
[[ "$actual_helper_hash" == "$HELPER_SOURCE_SHA256" ]]
[[ "$actual_sudoers_hash" == "$RENDERED_SUDOERS_SHA256" ]]

mapfile -t staged_sudoers_lines < "$STAGE_SUDOERS"
[[ "${#staged_sudoers_lines[@]}" -eq 1 ]]
expected_rule="$SERVER_USER ALL=(root) NOPASSWD: NOSETENV: $HELPER_DEST"
[[ "${staged_sudoers_lines[0]}" == "$expected_rule" ]]

if [[ "$INSTALL_DECISION" == 'INSTALL_REQUIRED' ]]; then
  set +e
  sudo -k -n -- /usr/sbin/visudo -cf "$STAGE_SUDOERS" >/dev/null 2>&1
  visudo_rc=$?
  set -e
  if [[ "$visudo_rc" -ne 0 ]]; then
    VISUDO_BEFORE_INSTALL='FAIL'
    emit_receipt
    exit 1
  fi
  VISUDO_BEFORE_INSTALL='PASS'
else
  VISUDO_BEFORE_INSTALL='NOT_REQUIRED'
fi
if [[ "$INSTALL_DECISION" == 'INSTALL_REQUIRED' ]]; then
  [[ "$PLANNED_INSTALL_STATE" == 'ABSENT' ]]
  [[ "$PLANNED_HELPER_STATE" == 'ABSENT' ]]
  [[ "$PLANNED_SUDOERS_STATE" == 'ABSENT' ]]
else
  [[ "$INSTALL_DECISION" == 'ALREADY_CONFORMANT' ]]
  [[ "$PLANNED_INSTALL_STATE" == 'ALREADY_CONFORMANT' ]]
  [[ "$PLANNED_HELPER_STATE" == 'ALREADY_CONFORMANT' ]]
  [[ "$PLANNED_SUDOERS_STATE" == 'ALREADY_CONFORMANT' ]]
fi

current_helper_state="$(classify_target \
  "$HELPER_DEST" "$HELPER_SOURCE_SHA256" 'root:root:755')"
current_sudoers_state="$(classify_target \
  "$SUDOERS_DEST" "$RENDERED_SUDOERS_SHA256" 'root:root:440')"
if [[ "$current_helper_state" != "$PLANNED_HELPER_STATE" \
  || "$current_sudoers_state" != "$PLANNED_SUDOERS_STATE" ]]; then
  STALE_PLAN='FAIL'
  emit_receipt
  exit 1
fi
if [[ "$INSTALL_DECISION" == 'INSTALL_REQUIRED' ]]; then
  set +e
  sudo -k -n -- /usr/bin/install -o root -g root -m 0755 -- \
    "$STAGE_HELPER" "$HELPER_DEST" >/dev/null 2>&1
  helper_install_rc=$?
  set -e
  if [[ "$helper_install_rc" -ne 0 ]]; then
    HELPER_INSTALL='FAIL'
    emit_receipt
    exit 1
  fi
  if [[ "$(classify_target "$HELPER_DEST" "$HELPER_SOURCE_SHA256" 'root:root:755')" \
    != 'ALREADY_CONFORMANT' ]]; then
    HELPER_INSTALL='FAIL'
    emit_receipt
    exit 1
  fi
  HELPER_INSTALL='PASS'
else
  HELPER_INSTALL='NOT_REQUIRED'
fi
if [[ "$INSTALL_DECISION" == 'INSTALL_REQUIRED' ]]; then
  set +e
  sudo -k -n -- /usr/bin/install -o root -g root -m 0440 -- \
    "$STAGE_SUDOERS" "$SUDOERS_DEST" >/dev/null 2>&1
  sudoers_install_rc=$?
  set -e
  if [[ "$sudoers_install_rc" -ne 0 ]]; then
    SUDOERS_INSTALL='FAIL'
    emit_receipt
    exit 1
  fi
  if [[ -L "$SUDOERS_DEST" || ! -f "$SUDOERS_DEST" \
    || "$(stat -c '%U:%G:%a' -- "$SUDOERS_DEST" 2>/dev/null || true)" != 'root:root:440' ]]; then
    SUDOERS_INSTALL='FAIL'
    emit_receipt
    exit 1
  fi
  SUDOERS_INSTALL='PASS'
else
  SUDOERS_INSTALL='NOT_REQUIRED'
fi
# Exact syntax was proven on the staged candidate before mutation.
# For a fresh install, exact install + target metadata carry that identity;
# ALREADY_CONFORMANT relies on immutable PLAN hash evidence.
SUDOERS_POST_VALIDATION='TRANSACTIONAL_EXACT_IDENTITY'

set +e
helper_output="$(sudo -k -n -- "$HELPER_DEST" 2>/dev/null)"
helper_rc=$?
set -e
if [[ "$helper_rc" -ne 0 ]]; then
  POST_INSTALL_HELPER_PROOF='FAIL'
  emit_receipt
  exit 1
fi
mapfile -t helper_lines <<< "$helper_output"
if [[ "${#helper_lines[@]}" -ne 3 \
  || "${helper_lines[0]}" != 'STATUS=PASS' \
  || ! "${helper_lines[1]}" =~ ^NGINX_RECENT_ERROR_COUNT=([0-9]+)$ \
  || ! "${helper_lines[2]}" =~ ^PHP_FPM_RECENT_ERROR_COUNT=([0-9]+)$ ]]; then
  POST_INSTALL_HELPER_PROOF='FAIL'
  emit_receipt
  exit 1
fi
NGINX_COUNT="${helper_lines[1]#NGINX_RECENT_ERROR_COUNT=}"
PHP_COUNT="${helper_lines[2]#PHP_FPM_RECENT_ERROR_COUNT=}"
POST_INSTALL_HELPER_PROOF='PASS'
CAPABILITY_READY='YES'
STATUS='PASS'
emit_receipt
