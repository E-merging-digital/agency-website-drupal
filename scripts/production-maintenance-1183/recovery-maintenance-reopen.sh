#!/usr/bin/env bash
set -Eeuo pipefail

current_root="${1:-}"
maintenance_before="${2:-}"

[[ -n "$current_root" ]]
[[ -d "$current_root" ]]
[[ ! -L "$current_root" ]]

case "$maintenance_before" in
  0)
    printf 'MAINTENANCE_MODE_CHANGE=NONE\n'
    ;;
  1)
    (
      cd "$current_root"
      vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer >/dev/null
      vendor/bin/drush cr >/dev/null
    )
    maintenance_after="$(cd "$current_root" && vendor/bin/drush state:get system.maintenance_mode | tail -n 1 | tr -d '[:space:]')"
    [[ "$maintenance_after" == '0' ]]
    printf 'MAINTENANCE_MODE_CHANGE=1_TO_0\n'
    ;;
  *)
    printf 'Unsupported maintenance state: %s\n' "$maintenance_before" >&2
    exit 65
    ;;
esac
