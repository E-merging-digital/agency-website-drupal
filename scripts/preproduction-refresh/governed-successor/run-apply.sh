#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

BASE='scripts/preproduction-refresh/governed-successor'
PROD_REMOTE='scripts/production-readonly-snapshot/remote-stream.sh'
PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'
PREPROD_TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
SANITIZER="$BASE/agency-sanitize.php"
WORKER="$BASE/remote-apply-worker.sh"

for var in AUTHORITY_ISSUE REQUEST_ID REPOSITORY_SHA SOURCE_PROD_RELEASE_SHA OPERATION_PROFILE \
  PROD_SSH_HOST PROD_SSH_USER PROD_SSH_KEY PREPROD_SSH_HOST PREPROD_SSH_KEY RUNNER_TEMP GITHUB_WORKSPACE GITHUB_RUN_ID; do
  [[ -n "${!var:-}" ]] || { echo "Missing $var" >&2; exit 64; }
done
[[ "$REQUEST_ID" == "apply-${AUTHORITY_ISSUE}-"*'-r1' ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ && "$SOURCE_PROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$OPERATION_PROFILE" == agency-preprod-refresh-simple-v1 ]]
[[ "${GITHUB_RUN_ATTEMPT:-}" == 1 && "${RUNNER_NAME:-}" == agency-browser-runner-01 ]]
[[ "$(git rev-parse HEAD)" == "$REPOSITORY_SHA" ]]
[[ -f "$SANITIZER" && -f "$WORKER" && -f "$PROD_REMOTE" ]]

workspace="$(realpath -m "$GITHUB_WORKSPACE")"
temp="$(realpath -m "$RUNNER_TEMP")"
case "$temp/" in "$workspace/"*) echo 'RUNNER_TEMP must be outside workspace' >&2; exit 65;; esac
stage="$temp/agency-914-$GITHUB_RUN_ID-${GITHUB_RUN_ATTEMPT}"
raw="$stage/raw-prod.sql"
sanitized="$stage/sanitized.sql"
repo="$stage/prod-release"
project="agency914-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
network="agency914-isolated-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
mkdir -p "$stage"
chmod 700 "$stage"

cleanup() {
  local status=$?
  trap - EXIT HUP INT TERM
  set +e
  rm -f -- "$raw" "$sanitized"
  if [[ -d "$repo" ]]; then (cd "$repo" && ddev delete -Oy >/dev/null 2>&1) || true; fi
  docker network rm "$network" >/dev/null 2>&1 || true
  git worktree remove --force "$repo" >/dev/null 2>&1 || rm -rf "$repo"
  rm -rf "$stage"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# Prepare exact PROD release code and dependencies before raw data exists.
git fetch --no-tags origin "$SOURCE_PROD_RELEASE_SHA" >/dev/null
git worktree add --detach "$repo" "$SOURCE_PROD_RELEASE_SHA" >/dev/null
cp "$SANITIZER" "$repo/.agency-914-sanitize.php"
(
  cd "$repo"
  ddev config --project-name "$project" >/dev/null
  ddev start >/dev/null
  ddev composer install --no-interaction --no-progress --prefer-dist >/dev/null
  ddev drush --version | grep -Fq 'Drush Commandline Tool 13.7.6'
)

# ISOLATE FIRST: web+db are moved to one Docker --internal network before any
# raw PROD byte is imported or any Drupal bootstrap sees the PROD database.
docker network create --internal "$network" >/dev/null
web="ddev-$project-web"; db="ddev-$project-db"
for container in "$web" "$db"; do
  docker inspect "$container" >/dev/null
  alias_name=web; [[ "$container" == "$db" ]] && alias_name=db
  docker network connect --alias "$alias_name" "$network" "$container"
  mapfile -t attached < <(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' "$container" | sed '/^$/d')
  for old in "${attached[@]}"; do [[ "$old" == "$network" ]] || docker network disconnect -f "$old" "$container"; done
  [[ "$(docker inspect -f '{{len .NetworkSettings.Networks}}' "$container")" == 1 ]]
done
[[ "$(docker network inspect -f '{{.Internal}}' "$network")" == true ]]
(cd "$repo" && ddev exec getent hosts db >/dev/null)
printf '%s\n' 'RAW_STAGING_EGRESS_ISOLATION=PASS' 'RAW_STAGING_ISOLATE_BEFORE_IMPORT=PASS'

# Existing reviewed PROD primitive: read-only Drush sql:dump over pinned SSH.
SERVER_HOST="$PROD_SSH_HOST" bash "$PROD_TRUST" VERIFY_ONLY >/dev/null
ssh_common=(-o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts" -o ConnectTimeout=15)
ssh -i "$PROD_SSH_KEY" "${ssh_common[@]}" "$PROD_SSH_USER@$PROD_SSH_HOST" \
  "bash -s -- '$SOURCE_PROD_RELEASE_SHA'" < "$PROD_REMOTE" > "$raw" 2>"$stage/prod.stderr"
rm -f "$stage/prod.stderr"
chmod 600 "$raw"
[[ -s "$raw" ]]

# Raw data never leaves the internal DDEV staging surface until sanitized.
(
  cd "$repo"
  ddev import-db --file="$raw" >/dev/null
  ddev drush sql:sanitize -y --sanitize-email='preprod-user+%uid@example.invalid' >/dev/null
  ddev drush php:script /var/www/html/.agency-914-sanitize.php | grep -Fxq 'AGENCY_CUSTOM_SANITIZATION=PASS'
  ddev drush sql:dump --no-interaction --extra-dump='--single-transaction --quick --skip-lock-tables --no-tablespaces' > "$sanitized"
)
chmod 600 "$sanitized"
[[ -s "$sanitized" ]]
rm -f -- "$raw"
[[ ! -e "$raw" ]]
san_sha="$(sha256sum "$sanitized" | awk '{print $1}')"
[[ "$san_sha" =~ ^[0-9a-f]{64}$ ]]
printf '%s\n' 'DRUSH_GENERIC_SANITIZATION=REUSED' 'AGENCY_CUSTOM_SANITIZATION=BOUNDED_GAPS_ONLY' 'RAW_PROD_CLEANUP=PASS'

# Only sanitized SQL crosses the PREPROD boundary.
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" bash "$PREPROD_TRUST" >/dev/null
remote_base="/var/www/agency-preprod/shared/refresh-jobs/$REQUEST_ID"
ssh -i "$PREPROD_SSH_KEY" "${ssh_common[@]}" "agency-preprod@$PREPROD_SSH_HOST" \
  "umask 077; mkdir -p '$remote_base'; test ! -e '$remote_base/result.env'; chmod 700 '$remote_base'"
scp -q -i "$PREPROD_SSH_KEY" "${ssh_common[@]}" "$sanitized" "agency-preprod@$PREPROD_SSH_HOST:$remote_base/sanitized.sql"
scp -q -i "$PREPROD_SSH_KEY" "${ssh_common[@]}" "$WORKER" "agency-preprod@$PREPROD_SSH_HOST:$remote_base/worker.sh"
ssh -i "$PREPROD_SSH_KEY" "${ssh_common[@]}" "agency-preprod@$PREPROD_SSH_HOST" \
  "chmod 600 '$remote_base/sanitized.sql'; chmod 700 '$remote_base/worker.sh'; test \"\$(sha256sum '$remote_base/sanitized.sql' | awk '{print \$1}')\" = '$san_sha'"
rm -f -- "$sanitized"

# Detach before any PREPROD mutation. Runner loss after this point cannot kill
# the worker; before this point PREPROD runtime has not been mutated.
ssh -i "$PREPROD_SSH_KEY" "${ssh_common[@]}" "agency-preprod@$PREPROD_SSH_HOST" \
  "nohup setsid --wait '$remote_base/worker.sh' '$REQUEST_ID' '$REPOSITORY_SHA' '$san_sha' </dev/null >'$remote_base/bootstrap.log' 2>&1 & echo \$! >'$remote_base/pid'"
printf '%s\n' 'SANITIZED_ONLY_TO_PREPROD=PASS' 'PREPROD_WORKER=DETACHED' 'HARD_RUNNER_LOSS_MODEL=PASS'

# Normal run waits for terminal metadata; disappearance of this runner does not
# participate in recovery semantics.
for _ in $(seq 1 360); do
  result="$(ssh -i "$PREPROD_SSH_KEY" "${ssh_common[@]}" "agency-preprod@$PREPROD_SSH_HOST" "test -f '$remote_base/result.env' && cat '$remote_base/result.env' || true")"
  outcome="$(sed -n 's/^outcome=//p' <<<"$result" | head -n1)"
  if [[ "$outcome" =~ ^(COMMITTED|ROLLED_BACK|HUMAN_RECOVERY_REQUIRED)$ ]]; then
    printf 'PREPROD_WORKER_OUTCOME=%s\n' "$outcome"
    [[ "$outcome" == COMMITTED ]]
    exit
  fi
  sleep 10
done
echo 'Detached PREPROD worker still running; no blind retry/recovery reconstruction is authorized.' >&2
exit 75
