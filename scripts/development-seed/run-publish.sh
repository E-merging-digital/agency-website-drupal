#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REQUEST_ID="${REQUEST_ID:-}"
REPOSITORY_SHA="${REPOSITORY_SHA:-}"
SOURCE_PREPROD_REFRESH_ID="${SOURCE_PREPROD_REFRESH_ID:-}"
SOURCE_PREPROD_RELEASE_SHA="${SOURCE_PREPROD_RELEASE_SHA:-}"
PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
RUNNER_TEMP="${RUNNER_TEMP:-}"
GITHUB_WORKSPACE="${GITHUB_WORKSPACE:-}"
GITHUB_RUN_ID="${GITHUB_RUN_ID:-}"
RUNNER_ENVIRONMENT="${RUNNER_ENVIRONMENT:-}"

[[ "$REQUEST_ID" =~ ^seed-956-[A-Za-z0-9._-]{8,40}-r1$ ]]
[[ "$REPOSITORY_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$SOURCE_PREPROD_REFRESH_ID" =~ ^[A-Za-z0-9._:-]{8,80}$ ]]
[[ "$SOURCE_PREPROD_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$PREPROD_SSH_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "$PREPROD_SSH_KEY" && ! -L "$PREPROD_SSH_KEY" ]]
[[ -n "$RUNNER_TEMP" && -n "$GITHUB_WORKSPACE" && "$GITHUB_RUN_ID" =~ ^[0-9]+$ ]]
[[ "$RUNNER_ENVIRONMENT" == self-hosted ]]
[[ "$(git rev-parse HEAD)" == "$REPOSITORY_SHA" ]]

SOURCE_SCRIPT='scripts/development-seed/remote-readonly-preprod-source.sh'
STORAGE_SCRIPT='scripts/development-seed/remote-storage.sh'
READER_SCRIPT='scripts/development-seed/remote-read-only-scp.sh'
READER_KEY_SCRIPT='scripts/development-seed/remote-reader-key.sh'
PREPROD_TRUST='scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh'
PINNED_KEY='scripts/preproduction-ssh-trust/preprod-ed25519.pub'
SEED_ID="agency-development-seed-v1-$REQUEST_ID"
REMOTE_ROOT='/var/www/agency-preprod/shared/development-seeds'
REMOTE_INCOMING="$REMOTE_ROOT/.incoming/$REQUEST_ID"

for path in "$SOURCE_SCRIPT" "$STORAGE_SCRIPT" "$READER_SCRIPT" "$READER_KEY_SCRIPT" "$PREPROD_TRUST" "$PINNED_KEY"; do
  [[ -f "$path" && ! -L "$path" ]]
done
for command_name in ddev git gzip jq php scp sha256sum ssh ssh-add ssh-agent ssh-keygen; do
  command -v "$command_name" >/dev/null 2>&1
 done

workspace_abs="$(realpath -m "$GITHUB_WORKSPACE")"
temp_abs="$(realpath -m "$RUNNER_TEMP")"
case "$temp_abs/" in "$workspace_abs/"*) echo 'RUNNER_TEMP must remain outside the repository workspace.' >&2; exit 80;; esac

raw="$temp_abs/$REQUEST_ID.raw-preprod.sql"
known_hosts="$temp_abs/$REQUEST_ID.known_hosts"
reader_key="$temp_abs/$REQUEST_ID.reader"
generation="$temp_abs/$REQUEST_ID-generation"
proof="$temp_abs/$REQUEST_ID-proof"
evidence_dir="$workspace_abs/artifacts/development-seed"
evidence="$evidence_dir/result.env"
reader_installed=0
generation_added=0
proof_added=0
incoming_may_exist=0
ssh_agent_started=0

read -r pinned_type pinned_blob _ < "$PINNED_KEY"
[[ "$pinned_type" == ssh-ed25519 && "$pinned_blob" =~ ^[A-Za-z0-9+/=]+$ ]]
printf '%s %s %s\n' "$PREPROD_SSH_HOST" "$pinned_type" "$pinned_blob" > "$known_hosts"
chmod 600 "$known_hosts"
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$known_hosts" bash "$PREPROD_TRUST" >/dev/null

ssh_args=(-i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts" -o ConnectTimeout=15)
scp_args=(-i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts" -o ConnectTimeout=15)
remote_target="agency-preprod@$PREPROD_SSH_HOST"

source_action() {
  local action="$1"
  "${ssh_args[@]}" "$remote_target" \
    "bash -s -- '$action' '$SOURCE_PREPROD_REFRESH_ID' '$SOURCE_PREPROD_RELEASE_SHA'" \
    < "$SOURCE_SCRIPT"
}
storage_action() {
  local action="$1" database_sha="${2:-NONE}" reader_sha="${3:-NONE}"
  "${ssh_args[@]}" "$remote_target" \
    "bash -s -- '$action' '$REQUEST_ID' '$database_sha' '$reader_sha'" \
    < "$STORAGE_SCRIPT"
}
reader_action() {
  local action="$1" blob="$2" reader_sha="$3"
  "${ssh_args[@]}" "$remote_target" \
    "bash -s -- '$action' '$REQUEST_ID' '$blob' '$reader_sha'" \
    < "$READER_KEY_SCRIPT"
}

delete_ddev_worktree() {
  local path="$1"
  if [[ -d "$path" ]]; then
    (cd "$path" && ddev delete -Oy >/dev/null 2>&1) || return 1
    git -C "$workspace_abs" worktree remove --force "$path" >/dev/null 2>&1 || return 1
  fi
}

cleanup() {
  local original=$? final=$?
  trap - EXIT HUP INT TERM
  set +e
  if (( reader_installed == 1 )); then
    reader_action REMOVE "$reader_blob" "$reader_sha" >/dev/null 2>&1 || final=98
    reader_installed=0
  fi
  if (( proof_added == 1 )); then
    delete_ddev_worktree "$proof" || final=98
    proof_added=0
  fi
  if (( generation_added == 1 )); then
    delete_ddev_worktree "$generation" || final=98
    generation_added=0
  fi
  if (( incoming_may_exist == 1 )); then
    storage_action CLEANUP NONE NONE >/dev/null 2>&1 || final=98
    incoming_may_exist=0
  fi
  if (( ssh_agent_started == 1 )); then
    ssh-agent -k >/dev/null 2>&1 || final=98
  fi
  rm -f -- "$raw" "$known_hosts" "$reader_key" "$reader_key.pub"
  [[ ! -e "$raw" && ! -e "$reader_key" && ! -e "$reader_key.pub" ]] || final=98
  if [[ "$original" -ne 0 ]]; then final="$original"; fi
  exit "$final"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

# JIT source proof is performed on PREPROD immediately before the read stream.
probe="$(source_action PROBE)"
grep -Fxq "source_preprod_refresh_identity=$SOURCE_PREPROD_REFRESH_ID" <<< "$probe"
grep -Fxq "source_preprod_application_release_sha=$SOURCE_PREPROD_RELEASE_SHA" <<< "$probe"
grep -Fxq 'preprod_runtime_db_write=NONE' <<< "$probe"
grep -Fxq 'prod_access=NONE' <<< "$probe"
grep -Fxq 'source_identity=PASS' <<< "$probe"

: > "$raw"
chmod 600 "$raw"
source_action STREAM > "$raw"
[[ "$(stat -c '%a' "$raw")" == 600 ]]
raw_bytes="$(stat -c '%s' "$raw")"
[[ "$raw_bytes" =~ ^[1-9][0-9]*$ && "$raw_bytes" -le 1099511627776 ]]
if LC_ALL=C grep -Eiq '^[[:space:]]*(USE[[:space:]]|CREATE[[:space:]]+(DATABASE|SCHEMA|USER)[[:space:]]|DROP[[:space:]]+(DATABASE|SCHEMA|USER)[[:space:]]|ALTER[[:space:]]+(DATABASE|SCHEMA|USER)[[:space:]]|GRANT[[:space:]]|REVOKE[[:space:]]|SET[[:space:]]+GLOBAL[[:space:]]|FLUSH[[:space:]]|INSTALL[[:space:]]+PLUGIN[[:space:]]|UNINSTALL[[:space:]]+PLUGIN[[:space:]]|SHUTDOWN([[:space:]]|;|$))' "$raw"; then
  echo 'PREPROD snapshot contains server-scoped SQL outside the isolated DDEV boundary.' >&2
  exit 81
fi

# Isolated generation uses a temporary DDEV worktree. PREPROD live Drupal never
# points to this database and all destructive sanitization occurs here.
git worktree add --detach "$generation" "$REPOSITORY_SHA" >/dev/null
generation_added=1
generation_name="agency-seed-956-${GITHUB_RUN_ID}"
sed -i "1s/^name:.*/name: $generation_name/" "$generation/.ddev/config.yaml"
(
  cd "$generation"
  ddev start -y >/dev/null
  ddev import-db --file="$raw" >/dev/null
)
rm -f -- "$raw"
[[ ! -e "$raw" ]]

seed_password="$(openssl rand -hex 32)"
(
  cd "$generation"
  ddev drush sql:sanitize -y \
    --sanitize-email='user+%uid@example.invalid' \
    --sanitize-password="$seed_password" >/dev/null
  ddev drush --quiet php:script scripts/preproduction-refresh/governed-successor/agency-sanitize.php >/dev/null
  ddev drush --quiet php:script scripts/development-seed/agency-development-sanitize.php >/dev/null
)
unset seed_password

build_dir="$generation/.ddev/.seed-build"
database="$build_dir/database.sql.gz"
metadata="$build_dir/seed.json"
mkdir -p "$build_dir"
chmod 700 "$build_dir"
(
  set -o pipefail
  cd "$generation"
  ddev drush sql:dump --no-interaction \
    --extra-dump='--single-transaction --quick --skip-lock-tables --no-tablespaces' 2>/dev/null \
    | gzip -9 > "$database"
)
chmod 600 "$database"
[[ -s "$database" ]]
created_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
(
  cd "$generation"
  ddev exec php scripts/development-seed/build-seed-metadata.php \
    --database=/var/www/html/.ddev/.seed-build/database.sql.gz \
    --seed-id="$SEED_ID" \
    --created-at="$created_at" \
    --source-refresh="$SOURCE_PREPROD_REFRESH_ID" \
    --source-release="$SOURCE_PREPROD_RELEASE_SHA" \
    --output=/var/www/html/.ddev/.seed-build/seed.json >/dev/null
  ddev exec php scripts/development-seed/verify-seed.php \
    --metadata=/var/www/html/.ddev/.seed-build/seed.json \
    --database=/var/www/html/.ddev/.seed-build/database.sql.gz \
    --repository=/var/www/html \
    --checkout-ref=HEAD >/dev/null
)
chmod 600 "$metadata"
database_sha="$(sha256sum "$database" | awk '{print $1}')"
reader_sha="$(sha256sum "$READER_SCRIPT" | awk '{print $1}')"
[[ "$database_sha" =~ ^[0-9a-f]{64}$ && "$reader_sha" =~ ^[0-9a-f]{64}$ ]]
[[ "$(jq -r '.database_sha256' "$metadata")" == "$database_sha" ]]
[[ "$(jq -r '.seed_id' "$metadata")" == "$SEED_ID" ]]
[[ "$(jq -r '.source_preprod_refresh_identity' "$metadata")" == "$SOURCE_PREPROD_REFRESH_ID" ]]
[[ "$(jq -r '.source_preprod_application_release_sha' "$metadata")" == "$SOURCE_PREPROD_RELEASE_SHA" ]]

# Publish only the fully sanitized/verified database and metadata. No raw PREPROD
# copy and no database artifact ever enters GitHub-hosted infrastructure.
storage_action PREPARE NONE NONE >/dev/null
incoming_may_exist=1
"${scp_args[@]}" -q -- "$database" "$remote_target:$REMOTE_INCOMING/database.sql.gz"
"${scp_args[@]}" -q -- "$metadata" "$remote_target:$REMOTE_INCOMING/seed.json"
"${scp_args[@]}" -q -- "$READER_SCRIPT" "$remote_target:$REMOTE_INCOMING/read-only-scp.sh"
storage_action COMMIT "$database_sha" "$reader_sha" >/dev/null
incoming_may_exist=0
storage_action VERIFY "$database_sha" "$reader_sha" >/dev/null

# Destroy the isolated generation DB and its local files before proving the
# distribution path. Only the immutable sanitized seed remains published.
delete_ddev_worktree "$generation"
generation_added=0
[[ ! -e "$generation" ]]

ssh-keygen -q -t ed25519 -N '' -C "$REQUEST_ID" -f "$reader_key"
chmod 600 "$reader_key"
read -r reader_type reader_blob _ < "$reader_key.pub"
[[ "$reader_type" == ssh-ed25519 && "$reader_blob" =~ ^[A-Za-z0-9+/=]{32,}$ ]]
reader_action INSTALL "$reader_blob" "$reader_sha" >/dev/null
reader_installed=1
reader_action VERIFY "$reader_blob" "$reader_sha" >/dev/null

# Real proof consumes the exact same DDEV-native provider and rollback contract
# delivered by #873; no custom download/import engine is introduced.
git worktree add --detach "$proof" "$REPOSITORY_SHA" >/dev/null
proof_added=1
proof_name="agency-seed-proof-956-${GITHUB_RUN_ID}"
sed -i "1s/^name:.*/name: $proof_name/" "$proof/.ddev/config.yaml"
cat > "$proof/.ddev/config.local.yaml" <<EOF_LOCAL
web_environment:
  - AGENCY_SEED_SSH_TARGET=agency-preprod@$PREPROD_SSH_HOST
EOF_LOCAL
chmod 600 "$proof/.ddev/config.local.yaml"

eval "$(ssh-agent -s)" >/dev/null
ssh_agent_started=1
ssh-add "$reader_key" >/dev/null
(
  cd "$proof"
  ddev auth ssh >/dev/null
  ddev start -y >/dev/null
  ddev pull agency -y >/dev/null
  ddev drush status --field=bootstrap 2>/dev/null | grep -q Successful
)
state="$proof/.ddev/.state-agency-seed.json"
[[ -s "$state" ]]
[[ "$(jq -r '.seed_id' "$state")" == "$SEED_ID" ]]
[[ "$(jq -r '.source_preprod_refresh_identity' "$state")" == "$SOURCE_PREPROD_REFRESH_ID" ]]
[[ "$(jq -r '.source_preprod_application_release_sha' "$state")" == "$SOURCE_PREPROD_RELEASE_SHA" ]]

reader_action REMOVE "$reader_blob" "$reader_sha" >/dev/null
reader_installed=0
delete_ddev_worktree "$proof"
proof_added=0
[[ ! -e "$proof" ]]
storage_action CLEANUP NONE NONE >/dev/null

mkdir -p "$evidence_dir"
cat > "$evidence.tmp" <<EOF_EVIDENCE
schema_version=1
request_id=$REQUEST_ID
repository_sha=$REPOSITORY_SHA
source_preprod_refresh_id=$SOURCE_PREPROD_REFRESH_ID
source_preprod_release_sha=$SOURCE_PREPROD_RELEASE_SHA
seed_id=$SEED_ID
database_sha256=$database_sha
source_identity_binding=CURRENT_JIT_FAIL_CLOSED
preprod_runtime_db_write=NONE
prod_access=NONE
raw_preprod_on_github_hosted=NONE
development_sanitization=PASS
seed_storage=PUBLISHED
current_pointer=VERIFIED
read_only_distribution=PROVEN
ddev_pull_agency=REAL_SUCCESS
local_side_effect_assertions=PASS
temporary_generation_material=ABSENT
temporary_reader_identity=ABSENT
public_files=NONE
private_files=NONE
push_path=NONE
EOF_EVIDENCE
chmod 600 "$evidence.tmp"
mv -f "$evidence.tmp" "$evidence"
chmod 600 "$evidence"

trap - EXIT HUP INT TERM
cleanup_status=0
cleanup || cleanup_status=$?
[[ "$cleanup_status" -eq 0 ]]
printf '%s\n' \
  "SEED_ID=$SEED_ID" \
  "DATABASE_SHA256=$database_sha" \
  'SOURCE_IDENTITY_BINDING=CURRENT_JIT_FAIL_CLOSED' \
  'PREPROD_RUNTIME_DB_WRITE=NONE' \
  'PROD_ACCESS=NONE' \
  'RAW_PREPROD_ON_GITHUB_HOSTED=NONE' \
  'DEVELOPMENT_SANITIZATION=PASS' \
  'SEED_STORAGE=PUBLISHED' \
  'CURRENT_POINTER=VERIFIED' \
  'READ_ONLY_DISTRIBUTION=PROVEN' \
  'DDEV_PULL_AGENCY=REAL_SUCCESS' \
  'LOCAL_SIDE_EFFECT_ASSERTIONS=PASS' \
  'TEMPORARY_GENERATION_MATERIAL=ABSENT'
