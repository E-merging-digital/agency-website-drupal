#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

TOKEN_FILE='/etc/agency-preprod/cockpit-state-token'

fail() {
  printf '[agency-cockpit-consumer-lease] ERROR: %s\n' "$1" >&2
  exit 80
}

[[ "$(id -u)" -eq 0 ]] || fail 'Root authority is required.'

mode="${1:-}"
session="${2:-}"
[[ "$session" =~ ^[0-9a-f]{16}$ ]] || fail 'Session must be exactly 16 lowercase hex characters.'

unit="agency-cockpit-consumer-${session}"
lease_marker="/run/${unit}.lease"
self_path="$(readlink -f "$0")"

[[ "$self_path" =~ ^/root/agency-1261-[1-9][0-9]*-[1-9][0-9]*/remote-lease-root\.sh$ ]] || {
  fail 'Lease helper path is outside bounded staging.'
}
[[ -f "$self_path" && ! -L "$self_path" && -x "$self_path" ]] || fail 'Lease helper is unsafe.'
[[ "$(stat -c '%U:%G' "$self_path")" == 'root:root' ]] || fail 'Lease helper ownership is invalid.'

marker_is_owned() {
  [[ -f "$lease_marker" && ! -L "$lease_marker" ]] || fail 'Lease marker is not a regular file.'
  [[ "$(stat -c '%U:%G:%a' "$lease_marker")" == 'root:root:600' ]] || fail 'Lease marker metadata mismatch.'
  [[ "$(cat "$lease_marker")" == "$session" ]] || fail 'Lease marker session mismatch.'
}

stop_units() {
  systemctl stop "${unit}.timer" "${unit}.service" >/dev/null 2>&1 || true
  systemctl reset-failed "${unit}.timer" "${unit}.service" >/dev/null 2>&1 || true
}

prove_terminal_absence() {
  [[ ! -e "$TOKEN_FILE" && ! -L "$TOKEN_FILE" ]] || fail 'Token file remains.'
  [[ ! -e "$lease_marker" && ! -L "$lease_marker" ]] || fail 'Lease marker remains.'
  if systemctl is-active --quiet "${unit}.timer"; then
    fail 'Expiry timer remains active.'
  fi
  if systemctl is-active --quiet "${unit}.service"; then
    fail 'Expiry service remains active.'
  fi
}

if [[ "$mode" == 'EXPIRE' ]]; then
  [[ "$#" -eq 2 ]] || fail 'EXPIRE accepts only the session.'
  if [[ -e "$lease_marker" || -L "$lease_marker" ]]; then
    marker_is_owned
    rm -f -- "$TOKEN_FILE" "$lease_marker"
  elif [[ -e "$TOKEN_FILE" || -L "$TOKEN_FILE" ]]; then
    fail 'Token exists without this session owning a lease.'
  fi
  [[ ! -e "$TOKEN_FILE" && ! -L "$TOKEN_FILE" ]] || fail 'Token remains after expiry.'
  [[ ! -e "$lease_marker" && ! -L "$lease_marker" ]] || fail 'Lease marker remains after expiry.'
  printf 'HARD_EXPIRY=PASS\n'
  exit 0
fi

if [[ "$mode" == 'CLEANUP' ]]; then
  [[ "$#" -eq 2 ]] || fail 'CLEANUP accepts only the session.'

  if [[ -e "$lease_marker" || -L "$lease_marker" ]]; then
    marker_is_owned
    stop_units
    rm -f -- "$TOKEN_FILE" "$lease_marker"
  elif [[ -e "$TOKEN_FILE" || -L "$TOKEN_FILE" ]]; then
    fail 'Token exists without this session owning a lease.'
  else
    stop_units
  fi

  prove_terminal_absence
  printf 'TOKEN_FILE_STATE=ABSENT\n'
  printf 'LEASE_MARKER_STATE=ABSENT\n'
  printf 'TRANSIENT_TIMER_STATE=INACTIVE\n'
  printf 'TRANSIENT_SERVICE_STATE=INACTIVE\n'
  printf 'OWNERSHIP_CLEANUP=PASS\n'
  exit 0
fi

[[ "$mode" == 'ARM' && "$#" -eq 3 ]] || fail 'ARM requires session and lease seconds.'
lease_seconds="$3"
[[ "$lease_seconds" == '600' ]] || fail 'Lease must be exactly 600 seconds.'
command -v systemd-run >/dev/null 2>&1 || fail 'systemd-run is unavailable.'
command -v systemctl >/dev/null 2>&1 || fail 'systemctl is unavailable.'

[[ ! -e "$TOKEN_FILE" && ! -L "$TOKEN_FILE" ]] || fail 'Refusing to overwrite an existing token.'
[[ ! -e "$lease_marker" && ! -L "$lease_marker" ]] || fail 'Lease marker already exists.'

armed=0
cleanup_arm_failure() {
  local rc=$?
  if [[ "$rc" -ne 0 && "$armed" -eq 0 ]]; then
    stop_units
    if [[ -e "$lease_marker" || -L "$lease_marker" ]]; then
      if [[ -f "$lease_marker" && ! -L "$lease_marker" ]] \
        && [[ "$(stat -c '%U:%G:%a' "$lease_marker")" == 'root:root:600' ]] \
        && [[ "$(cat "$lease_marker")" == "$session" ]]; then
        rm -f -- "$lease_marker"
      fi
    fi
  fi
  exit "$rc"
}
trap cleanup_arm_failure EXIT

install -m 600 -o root -g root /dev/null "$lease_marker"
printf '%s\n' "$session" > "$lease_marker"
marker_is_owned

stop_units
systemd-run --quiet \
  --unit="$unit" \
  --on-active="${lease_seconds}s" \
  --timer-property=AccuracySec=1s \
  --property=Type=oneshot \
  "$self_path" EXPIRE "$session"

systemctl is-active --quiet "${unit}.timer" || fail 'Independent expiry timer is not active.'
armed=1
trap - EXIT

printf 'LEASE_MARKER_CONTRACT=root:root:600\n'
printf 'LEASE_SECONDS=%s\n' "$lease_seconds"
printf 'TRANSIENT_TIMER_STATE=ACTIVE\n'
printf 'EXPIRY_ARMED=PASS\n'
