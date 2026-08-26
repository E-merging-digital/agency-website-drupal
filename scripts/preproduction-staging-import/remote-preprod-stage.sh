#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ACTION="${1:-}"
REQUEST_ID="${2:-}"
SNAPSHOT_BYTES="${3:-}"
EXPECTED_HELPER_SHA256="${4:-}"
if [[ "$#" -ne 4 ]] || [[ "$ACTION" != 'PRECHECK' && "$ACTION" != 'IMPORT' && "$ACTION" != 'CLEANUP' && "$ACTION" != 'VERIFY_ABSENCE' ]]; then
  echo 'Invalid fixed staging action.' >&2
  exit 64
fi
[[ "$REQUEST_ID" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || { echo 'Invalid request identity.' >&2; exit 65; }
[[ "$SNAPSHOT_BYTES" =~ ^[0-9]+$ ]] || { echo 'Invalid snapshot byte count.' >&2; exit 66; }
[[ "$SNAPSHOT_BYTES" -le 1099511627776 ]] || { echo 'Snapshot byte count exceeds bounded policy.' >&2; exit 67; }
[[ "$EXPECTED_HELPER_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo 'Invalid repository helper digest.' >&2; exit 68; }

PROJECT_ROOT='/var/www/agency-preprod'
CURRENT_LINK="$PROJECT_ROOT/current"
RUNTIME_SETTINGS="$PROJECT_ROOT/shared/settings/settings.php"
ACTIVE_SETTINGS="$CURRENT_LINK/web/sites/default/settings.php"
RUNTIME_DB='agency_preprod'
PRIVILEGED_HELPER='/usr/local/sbin/agency-preprod-staging-db'

test -f "$RUNTIME_SETTINGS"
test -f "$PRIVILEGED_HELPER"
[[ ! -L "$PRIVILEGED_HELPER" ]] || { echo 'Privileged helper must not be a symlink.' >&2; exit 69; }
helper_identity="$(stat -c '%u:%g:%a' "$PRIVILEGED_HELPER")"
[[ "$helper_identity" == '0:0:755' ]] || {
  echo 'Privileged helper owner/group/mode does not match repository contract.' >&2
  exit 70
}
actual_helper_sha256="$(sha256sum "$PRIVILEGED_HELPER" | awk '{print $1}')"
[[ "$actual_helper_sha256" == "$EXPECTED_HELPER_SHA256" ]] || {
  echo 'Privileged helper digest does not match repository authority.' >&2
  exit 71
}
command -v sudo >/dev/null

run_helper() {
  sudo -n -- "$PRIVILEGED_HELPER" "$ACTION" "$REQUEST_ID" "$SNAPSHOT_BYTES"
}

runtime_isolated() {
  test -L "$CURRENT_LINK" && \
    test -L "$ACTIVE_SETTINGS" && \
    [[ "$(readlink -f "$ACTIVE_SETTINGS")" == "$(readlink -f "$RUNTIME_SETTINGS")" ]] && \
    grep -Fq "'database' => '$RUNTIME_DB'" "$RUNTIME_SETTINGS" && \
    ! grep -Fq "'database' => 'agency_preprod_stage_" "$RUNTIME_SETTINGS"
}

capacity_check() {
  local free required multiplier margin minimum
  free="$(df -PB1 /var/lib/mysql | awk 'NR==2 {print $4}')"
  multiplier=4
  margin=1073741824
  minimum=2147483648
  required=$((SNAPSHOT_BYTES * multiplier + margin))
  if [[ "$required" -lt "$minimum" ]]; then
    required="$minimum"
  fi
  [[ "$free" =~ ^[0-9]+$ && "$free" -ge "$required" ]]
}

case "$ACTION" in
  PRECHECK)
    capacity_check || { echo 'PREPROD capacity preflight failed.' >&2; exit 72; }
    runtime_isolated || { echo 'PREPROD runtime isolation preflight failed.' >&2; exit 73; }
    helper_out="$(run_helper)"
    grep -Fxq 'staging_admin_capability=PASS' <<< "$helper_out"
    grep -Fxq 'staging_db_present=NO' <<< "$helper_out"
    printf '%s\n' \
      'preprod_capacity=PASS' \
      'preprod_runtime_points_to_staging=NO' \
      'staging_admin_capability=PASS' \
      'privileged_helper_identity=PASS' \
      'privileged_helper_digest=PASS'
    ;;

  VERIFY_ABSENCE)
    runtime_isolated || { echo 'PREPROD runtime isolation verification failed.' >&2; exit 74; }
    helper_out="$(run_helper)"
    grep -Fxq 'staging_db_present_after_cleanup=NO' <<< "$helper_out"
    printf '%s\n' \
      'preprod_runtime_points_to_staging=NO' \
      'staging_db_present_after_cleanup=NO' \
      'privileged_helper_identity=PASS' \
      'privileged_helper_digest=PASS'
    ;;

  CLEANUP)
    helper_out="$(run_helper)"
    grep -Fxq 'staging_db_present_after_cleanup=NO' <<< "$helper_out"
    runtime_isolated || { echo 'PREPROD runtime isolation cleanup verification failed.' >&2; exit 75; }
    printf '%s\n' \
      'preprod_runtime_points_to_staging=NO' \
      'staging_db_present_after_cleanup=NO' \
      'privileged_helper_identity=PASS' \
      'privileged_helper_digest=PASS'
    ;;

  IMPORT)
    [[ "$SNAPSHOT_BYTES" -gt 0 ]] || { echo 'IMPORT requires a positive snapshot byte count.' >&2; exit 76; }
    capacity_check || { echo 'PREPROD capacity preflight failed before import.' >&2; exit 77; }
    runtime_isolated || { echo 'PREPROD runtime unexpectedly references staging.' >&2; exit 78; }

    # Raw SQL remains an encrypted stdin stream. The root helper never executes
    # it as root: it imports through an ephemeral MariaDB account whose grants
    # are limited to the request-derived staging database only.
    helper_out="$(run_helper)"
    grep -Fxq 'staging_import_result=PASS' <<< "$helper_out"
    grep -Fxq 'schema_proof=PASS' <<< "$helper_out"
    grep -Eq '^safe_table_count=[1-9][0-9]*$' <<< "$helper_out"
    grep -Fxq 'staging_db_present_after_cleanup=NO' <<< "$helper_out"

    runtime_isolated || { echo 'PREPROD runtime points to staging after import.' >&2; exit 79; }

    printf '%s\n' 'preprod_runtime_points_to_staging_before=NO'
    printf '%s\n' "$helper_out"
    printf '%s\n' \
      'preprod_runtime_points_to_staging_after=NO' \
      'privileged_helper_identity=PASS' \
      'privileged_helper_digest=PASS'
    ;;
esac
