#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

ACTION="${1:-}"
EXPECTED_REFRESH="${2:-}"
EXPECTED_RELEASE="${3:-}"
[[ "$#" -eq 3 ]]
[[ "$ACTION" == PROBE || "$ACTION" == STREAM ]]
[[ "$EXPECTED_REFRESH" =~ ^[A-Za-z0-9._:-]{8,80}$ ]]
[[ "$EXPECTED_RELEASE" =~ ^[0-9a-f]{40}$ ]]

PROJECT_ROOT='/var/www/agency-preprod'
CURRENT="$PROJECT_ROOT/current"
SHARED="$PROJECT_ROOT/shared"
REFRESH_JOBS="$SHARED/refresh-jobs"
ARTIFACTS="$SHARED/artifacts"
DRUSH="$CURRENT/vendor/bin/drush"

fail() {
  printf 'Development Seed PREPROD source rejected: %s\n' "$1" >&2
  exit 80
}

field() {
  local file="$1" name="$2" value
  value="$(grep -m1 "^${name}=" "$file" | cut -d= -f2- || true)"
  [[ "$value" != *$'\n'* && "$value" != *$'\r'* ]]
  printf '%s' "$value"
}

[[ -L "$CURRENT" ]] || fail 'current release symlink is absent'
[[ -x "$DRUSH" ]] || fail 'current PREPROD Drush is unavailable'
[[ -d "$REFRESH_JOBS" && ! -L "$REFRESH_JOBS" ]] || fail 'refresh evidence directory is unavailable'
[[ -d "$ARTIFACTS" && ! -L "$ARTIFACTS" ]] || fail 'PREPROD artifact archive is unavailable'

# Resolve current data identity from durable #914 terminal results. A later
# proven rollback leaves the preceding COMMITTED refresh current; an unresolved
# HUMAN_RECOVERY_REQUIRED state is never accepted as a distributable source.
records=()
shopt -s nullglob
for result in "$REFRESH_JOBS"/*/result.env; do
  [[ -f "$result" && ! -L "$result" && -r "$result" ]] || continue
  finished="$(field "$result" finished_at)"
  request="$(field "$result" request_id)"
  outcome="$(field "$result" outcome)"
  detail="$(field "$result" detail)"
  [[ "$finished" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || continue
  [[ "$request" =~ ^[A-Za-z0-9._-]{8,80}$ ]] || continue
  [[ "$outcome" =~ ^(COMMITTED|ROLLED_BACK|HUMAN_RECOVERY_REQUIRED)$ ]] || continue
  [[ "$detail" =~ ^[A-Z0-9_]+$ ]] || continue
  records+=("$finished|$request|$outcome|$detail")
done
shopt -u nullglob
[[ "${#records[@]}" -gt 0 ]] || fail 'no bounded refresh terminal evidence exists'

mapfile -t records < <(printf '%s\n' "${records[@]}" | LC_ALL=C sort -r)
CURRENT_REFRESH=''
for record in "${records[@]}"; do
  IFS='|' read -r _finished request outcome detail <<< "$record"
  if [[ "$outcome" == HUMAN_RECOVERY_REQUIRED ]]; then
    fail 'latest unresolved refresh state requires human recovery'
  fi
  if [[ "$outcome" == COMMITTED ]]; then
    [[ "$detail" == SANITIZED_DATABASE_ACTIVE_AND_VALIDATED ]] || fail 'committed refresh detail is not distributable'
    CURRENT_REFRESH="$request"
    break
  fi
  if [[ "$outcome" == ROLLED_BACK ]]; then
    case "$detail" in
      NO_PREPROD_RUNTIME_MUTATION*|EXACT_BACKUP_OR_UNCHANGED_RUNTIME_PROVEN) ;;
      *) fail 'rollback evidence does not prove the current runtime data boundary' ;;
    esac
  fi
done
[[ -n "$CURRENT_REFRESH" ]] || fail 'no current committed sanitized PREPROD refresh can be resolved'
[[ "$CURRENT_REFRESH" == "$EXPECTED_REFRESH" ]] || fail 'authorized PREPROD refresh identity is stale'

# PREPROD release payloads contain no .git. Bind the current release symlink to
# the durable full candidate SHA directory already archived by deploy-candidate.
current_release="$(readlink -f "$CURRENT")"
[[ -n "$current_release" && -d "$current_release" ]] || fail 'current PREPROD release cannot be resolved'
release_name="$(basename "$current_release")"
[[ "$release_name" =~ -([0-9a-f]{12})$ ]] || fail 'current PREPROD release name has no candidate prefix'
prefix="${BASH_REMATCH[1]}"
mapfile -t release_matches < <(
  find "$ARTIFACTS" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' \
    | grep -E "^${prefix}[0-9a-f]{28}$" \
    | LC_ALL=C sort
)
[[ "${#release_matches[@]}" -eq 1 ]] || fail 'current PREPROD release does not map to exactly one archived candidate'
CURRENT_RELEASE_SHA="${release_matches[0]}"
[[ "$CURRENT_RELEASE_SHA" == "$EXPECTED_RELEASE" ]] || fail 'authorized PREPROD application release is stale'

if [[ "$ACTION" == PROBE ]]; then
  printf '%s\n' \
    "source_preprod_refresh_identity=$CURRENT_REFRESH" \
    "source_preprod_application_release_sha=$CURRENT_RELEASE_SHA" \
    'source_preprod_runtime_db=agency_preprod' \
    'preprod_runtime_db_write=NONE' \
    'prod_access=NONE' \
    'source_identity=PASS'
  exit 0
fi

# STREAM is one fixed read-only logical snapshot. There is no caller-controlled
# database, SQL, result path or dump option and no PREPROD mutation command.
cd "$CURRENT"
"$DRUSH" status --field=bootstrap 2>/dev/null | grep -q Successful
exec "$DRUSH" sql:dump \
  --no-interaction \
  --extra-dump='--single-transaction --quick --skip-lock-tables --no-tablespaces'
