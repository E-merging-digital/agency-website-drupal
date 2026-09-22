#!/usr/bin/env bash
set -euo pipefail

current_root="${1:-}"
maintenance_before="${2:-}"

fail_contract() {
  printf 'MAINTENANCE_REOPEN_FAILURE=%s\n' "$1" >&2
  exit "${2:-65}"
}

[[ -n "$current_root" ]] || fail_contract CURRENT_ROOT_MISSING
[[ "$current_root" == /* ]] || fail_contract CURRENT_ROOT_NOT_ABSOLUTE
[[ "$(basename -- "$current_root")" == 'current' ]] || fail_contract CURRENT_ROOT_NOT_CANONICAL_NAME
[[ -L "$current_root" ]] || fail_contract CURRENT_NOT_SYMLINK

agency_root="$(dirname -- "$current_root")"
releases_root="$agency_root/releases"
[[ -d "$releases_root" && ! -L "$releases_root" ]] || fail_contract RELEASES_ROOT_INVALID

resolved_root="$(readlink -f -- "$current_root" 2>/dev/null || true)"
[[ -n "$resolved_root" && -d "$resolved_root" ]] || fail_contract CURRENT_TARGET_MISSING
release_name="$(basename -- "$resolved_root")"
[[ "$release_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] || fail_contract RELEASE_NAME_INVALID
[[ "$resolved_root" == "$releases_root/$release_name" ]] || fail_contract CURRENT_TARGET_OUTSIDE_RELEASES

drush="$resolved_root/vendor/bin/drush"
[[ -x "$drush" ]] || fail_contract DRUSH_NOT_EXECUTABLE

case "$maintenance_before" in
  0)
    printf 'MAINTENANCE_MODE_CHANGE=NONE\n'
    ;;
  1)
    (
      cd "$resolved_root"
      "$drush" state:set system.maintenance_mode 0 --input-format=integer >/dev/null
      "$drush" cr >/dev/null
    )
    maintenance_after="$(cd "$resolved_root" && "$drush" state:get system.maintenance_mode | tail -n 1 | tr -d '[:space:]')"
    [[ "$maintenance_after" == '0' ]] || fail_contract MAINTENANCE_REOPEN_DID_NOT_STICK
    printf 'MAINTENANCE_MODE_CHANGE=1_TO_0\n'
    ;;
  *)
    fail_contract UNSUPPORTED_MAINTENANCE_STATE 65
    ;;
esac
