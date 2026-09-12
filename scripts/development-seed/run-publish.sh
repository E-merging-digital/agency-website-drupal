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
SNAPSHOT_NAME='database-mariadb_11.8.zst'
REMOTE_ROOT='/var/www/agency-preprod/shared/development-seeds'
REMOTE_INCOMING="$REMOTE_ROOT/.incoming/$REQUEST_ID"

for path in "$SOURCE_SCRIPT" "$STORAGE_SCRIPT" "$READER_SCRIPT" "$READER_KEY_SCRIPT" "$PREPROD_TRUST" "$PINNED_KEY"; do
  [[ -f "$path" && ! -L "$path" ]]
done
for command_name in ddev git jq openssl scp sha256sum ssh ssh-add ssh-agent ssh-keygen; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    printf 'MISSING_REQUIRED_COMMAND=%s\n' "$command_name" >&2
    exit 82
  fi
done

workspace_abs="$(realpath -m "$GITHUB_WORKSPACE")"
temp_abs="$(realpath -m "$RUNNER_TEMP")"
case "$temp_abs/" in "$workspace_abs/"*) echo 'RUNNER_TEMP must remain outside the repository workspace.' >&2; exit 80;; esac

raw="$temp_abs/$REQUEST_ID.raw-preprod.sql"
sanitize_diagnostic="$temp_abs/$REQUEST_ID.sql-sanitize.diagnostic"
known_hosts="$temp_abs/$REQUEST_ID.known_hosts"
reader_key="$temp_abs/$REQUEST_ID.reader"
generation="$temp_abs/$REQUEST_ID-generation"
proof="$temp_abs/$REQUEST_ID-proof"
proof_cache="$temp_abs/$REQUEST_ID-proof-cache"
evidence_dir="$workspace_abs/artifacts/development-seed"
evidence="$evidence_dir/result.env"
reader_installed=0
generation_added=0
proof_added=0
incoming_may_exist=0
ssh_agent_started=0
reader_blob=''
reader_sha=''

read -r pinned_type pinned_blob _ < "$PINNED_KEY"
[[ "$pinned_type" == ssh-ed25519 && "$pinned_blob" =~ ^[A-Za-z0-9+/=]+$ ]]
printf '%s %s %s\n' "$PREPROD_SSH_HOST" "$pinned_type" "$pinned_blob" > "$known_hosts"
chmod 600 "$known_hosts"
PREPROD_SERVER_HOST="$PREPROD_SSH_HOST" PREPROD_KNOWN_HOSTS_FILE="$known_hosts" bash "$PREPROD_TRUST" >/dev/null

ssh_args=(ssh -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts" -o ConnectTimeout=15)
scp_args=(scp -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts" -o ConnectTimeout=15)
remote_target="agency-preprod@$PREPROD_SSH_HOST"

source_action() {
  local action="$1"
  "${ssh_args[@]}" "$remote_target" \
    "bash -s -- '$action' '$SOURCE_PREPROD_REFRESH_ID' '$SOURCE_PREPROD_RELEASE_SHA'" \
    < "$SOURCE_SCRIPT"
}
storage_action() {
  local action="$1" database_sha="${2:-NONE}" expected_reader_sha="${3:-NONE}"
  "${ssh_args[@]}" "$remote_target" \
    "bash -s -- '$action' '$REQUEST_ID' '$database_sha' '$expected_reader_sha'" \
    < "$STORAGE_SCRIPT"
}
reader_action() {
  local action="$1" blob="$2" expected_reader_sha="$3"
  "${ssh_args[@]}" "$remote_target" \
    "bash -s -- '$action' '$REQUEST_ID' '$blob' '$expected_reader_sha'" \
    < "$READER_KEY_SCRIPT"
}

classify_sanitize_component() {
  local diagnostic_path="$1"
  local failure_class="$2"
  local line=''
  local database_frame=0
  local in_exception_trace=0

  case "$failure_class" in
    COMMAND|BOOTSTRAP)
      printf 'COMMAND_OR_BOOTSTRAP\n'
      return
      ;;
  esac
  while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" == *'sanitize plugin is using a deprecated API'* ]]; then
      continue
    fi
    if [[ "$line" == *'Exception trace:'* ]]; then
      in_exception_trace=1
      continue
    fi
    (( in_exception_trace == 1 )) || continue

    case "$line" in
      *'Drupal\webform\Commands\WebformSanitizeSubmissionsCommands->sanitize('*|*'Drupal\webform\Commands\WebformSanitizeSubmissionsCommands::sanitize('*)
        printf 'WEBFORM_SUBMISSIONS\n'; return ;;
      *'Drush\Commands\sql\sanitize\SanitizeCommentsCommands->sanitize('*|*'Drush\Commands\sql\sanitize\SanitizeCommentsCommands::sanitize('*)
        printf 'COMMENTS\n'; return ;;
      *'Drush\Commands\sql\sanitize\SanitizeSessionsCommands->sanitize('*|*'Drush\Commands\sql\sanitize\SanitizeSessionsCommands::sanitize('*)
        printf 'SESSIONS\n'; return ;;
      *'Drush\Commands\sql\sanitize\SanitizeUserTableCommands->sanitize('*|*'Drush\Commands\sql\sanitize\SanitizeUserTableCommands::sanitize('*)
        printf 'USER_TABLE\n'; return ;;
      *'Drush\Commands\sql\sanitize\SanitizeUserFieldsCommands->sanitize('*|*'Drush\Commands\sql\sanitize\SanitizeUserFieldsCommands::sanitize('*)
        printf 'USER_FIELDS\n'; return ;;
      *'Drupal\Core\Database\'*)
        database_frame=1 ;;
    esac
  done < "$diagnostic_path"

  if (( database_frame == 1 )); then
    printf 'DRUPAL_DATABASE\n'
  else
    printf 'UNKNOWN\n'
  fi
}

classify_sanitize_metadata() {
  local diagnostic_path="$1"
  local trace_present='NO'
  local abnormal_termination='NO'
  local drupal_error_signal='NO'
  local comments_completed='NO'
  local sessions_completed='NO'
  local user_table_completed='NO'
  local user_fields_activity='NO'

  if LC_ALL=C grep -Fq -- 'Exception trace:' "$diagnostic_path"; then
    trace_present='YES'
  fi
  if LC_ALL=C grep -Fq -- 'Drush command terminated abnormally.' "$diagnostic_path"; then
    abnormal_termination='YES'
  fi
  if LC_ALL=C grep -Eq -- '(^|[[:space:]])\[error\][[:space:]]' "$diagnostic_path"; then
    drupal_error_signal='YES'
  fi
  if LC_ALL=C grep -Fq -- 'Comment display names and emails removed.' "$diagnostic_path"; then
    comments_completed='YES'
  fi
  if LC_ALL=C grep -Fq -- 'Sessions table truncated.' "$diagnostic_path"; then
    sessions_completed='YES'
  fi
  if LC_ALL=C grep -Fq -- 'User passwords sanitized.' "$diagnostic_path" \
    && LC_ALL=C grep -Fq -- 'User emails sanitized.' "$diagnostic_path"; then
    user_table_completed='YES'
  fi
  if LC_ALL=C grep -Eq -- '(^|[[:space:]])[A-Za-z0-9_]+ table sanitized\.[[:space:]]*$' "$diagnostic_path"; then
    user_fields_activity='YES'
  fi
  printf 'SANITIZE_TRACE_PRESENT=%s\n' "$trace_present"
  printf 'SANITIZE_ABNORMAL_TERMINATION=%s\n' "$abnormal_termination"
  printf 'SANITIZE_DRUPAL_ERROR_SIGNAL=%s\n' "$drupal_error_signal"
  printf 'SANITIZE_CORE_COMMENTS_COMPLETED=%s\n' "$comments_completed"
  printf 'SANITIZE_CORE_SESSIONS_COMPLETED=%s\n' "$sessions_completed"
  printf 'SANITIZE_CORE_USER_TABLE_COMPLETED=%s\n' "$user_table_completed"
  printf 'SANITIZE_CORE_USER_FIELDS_ACTIVITY=%s\n' "$user_fields_activity"
}

drush_user_email_sanitizer_completed() {
  local diagnostic_path="$1"
  if LC_ALL=C grep -Fxq -- 'User emails sanitized.' "$diagnostic_path"; then
    printf 'YES\n'
  else
    printf 'NO\n'
  fi
}

delete_sanitize_diagnostic() {
  local diagnostic_path="$1"
  if ! rm -f -- "$diagnostic_path" || [[ -e "$diagnostic_path" ]]; then
    printf 'SANITIZE_DIAGNOSTIC_CLEANUP=FAIL\n' >&2
    return 98
  fi
}

classify_sanitize_failure() {
  local diagnostic_path="$1"
  local exit_code="$2"
  local failure_class='UNCLASSIFIED'
  local failure_component='UNKNOWN'

  if LC_ALL=C grep -Eiq -- '(command .* is not defined|there are no commands defined|option .* does not exist|unknown option|too many arguments|not enough arguments)' "$diagnostic_path"; then
    failure_class='COMMAND'
  elif LC_ALL=C grep -Eiq -- '(bootstrap failed|could not bootstrap|unable to bootstrap|failed to bootstrap|drupal bootstrap)' "$diagnostic_path"; then
    failure_class='BOOTSTRAP'
  elif LC_ALL=C grep -Eiq -- '(SQLSTATE\[(42S02|42S22)\]|base table or view not found|unknown column|no such table|table .* doesn.?t exist)' "$diagnostic_path"; then
    failure_class='SCHEMA'
  elif LC_ALL=C grep -Eiq -- '(SQLSTATE\[|PDOException|DatabaseException|QueryException|deadlock|lock wait timeout|server has gone away|connection refused|fatal error|uncaught .*exception|allowed memory size)' "$diagnostic_path"; then
    failure_class='RUNTIME'
  fi

  failure_component="$(classify_sanitize_component "$diagnostic_path" "$failure_class")"
  case "$failure_component" in
    WEBFORM_SUBMISSIONS|COMMENTS|SESSIONS|USER_TABLE|USER_FIELDS|DRUPAL_DATABASE|COMMAND_OR_BOOTSTRAP|UNKNOWN) ;;
    *) failure_component='UNKNOWN' ;;
  esac

  if [[ ! "$exit_code" =~ ^[1-9][0-9]*$ ]]; then
    exit_code=255
  fi
  printf 'SANITIZE_FAILURE=YES\n' >&2
  printf 'SANITIZE_FAILURE_CLASS=%s\n' "$failure_class" >&2
  printf 'SANITIZE_FAILURE_COMPONENT=%s\n' "$failure_component" >&2
  printf 'SANITIZE_FAILURE_EXIT=%s\n' "$exit_code" >&2
  classify_sanitize_metadata "$diagnostic_path" >&2
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
    ssh_agent_started=0
  fi
  rm -rf -- "$proof_cache"
  rm -f -- "$raw" "$sanitize_diagnostic" "$known_hosts" "$reader_key" "$reader_key.pub"
  [[ ! -e "$raw" && ! -e "$sanitize_diagnostic" && ! -e "$known_hosts" && ! -e "$reader_key" && ! -e "$reader_key.pub" && ! -e "$proof_cache" ]] || final=98
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

# The source SQL stream remains the existing read-only PREPROD acquisition
# boundary. It is imported once into an isolated DDEV DB; no post-sanitization
# logical SQL export is created or distributed.
git worktree add --detach "$generation" "$REPOSITORY_SHA" >/dev/null
generation_added=1
generation_name="agency-seed-956-${GITHUB_RUN_ID}"
sed -i "1s/^name:.*/name: $generation_name/" "$generation/.ddev/config.yaml"
[[ -f "$generation/composer.lock" && ! -L "$generation/composer.lock" ]]
(
  cd "$generation"
  ddev start -y >/dev/null
  ddev composer install --no-interaction --no-progress --prefer-dist >/dev/null
  ddev import-db --file="$raw" >/dev/null
)
rm -f -- "$raw"
[[ ! -e "$raw" ]]

seed_password="$(openssl rand -hex 32)"
(umask 077; set -o noclobber; : > "$sanitize_diagnostic")
[[ -f "$sanitize_diagnostic" && ! -L "$sanitize_diagnostic" ]]
[[ "$(stat -c '%a' "$sanitize_diagnostic")" == 600 ]]
if (
  cd "$generation"
  ddev drush -vvv sql:sanitize -y \
    --sanitize-email='user+%uid@example.invalid' \
    --sanitize-password="$seed_password"
) > "$sanitize_diagnostic" 2>&1; then
  drush_user_email_completed="$(drush_user_email_sanitizer_completed "$sanitize_diagnostic")"
  printf 'DRUSH_USER_EMAIL_SANITIZER_COMPLETED = %s\n' "$drush_user_email_completed"
  if ! delete_sanitize_diagnostic "$sanitize_diagnostic"; then
    unset seed_password drush_user_email_completed
    exit 98
  fi
  unset drush_user_email_completed
else
  sanitize_exit=$?
  classify_sanitize_failure "$sanitize_diagnostic" "$sanitize_exit"
  if ! delete_sanitize_diagnostic "$sanitize_diagnostic"; then
    unset seed_password
    exit 98
  fi
  unset seed_password
  exit "$sanitize_exit"
fi
unset seed_password

(
  cd "$generation"
  ddev drush --quiet php:script scripts/preproduction-refresh/governed-successor/agency-sanitize.php >/dev/null
  ddev drush --quiet php:script scripts/development-seed/agency-development-sanitize.php >/dev/null
)

# Snapshot only after every existing sanitization/assertion has passed.
build_dir="$generation/.ddev/.seed-build"
database="$build_dir/$SNAPSHOT_NAME"
metadata="$build_dir/seed.json"
mkdir -p "$build_dir"
chmod 700 "$build_dir"
(
  cd "$generation"
  ddev snapshot --name="$SEED_ID" -y >/dev/null
)
generated_snapshot="$generation/.ddev/db_snapshots/${SEED_ID}-mariadb_11.8.zst"
[[ -s "$generated_snapshot" ]]
mv -- "$generated_snapshot" "$database"
chmod 600 "$database"
created_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
(
  cd "$generation"
  ddev exec php scripts/development-seed/build-seed-metadata.php \
    --database="/var/www/html/.ddev/.seed-build/$SNAPSHOT_NAME" \
    --seed-id="$SEED_ID" \
    --created-at="$created_at" \
    --source-refresh="$SOURCE_PREPROD_REFRESH_ID" \
    --source-release="$SOURCE_PREPROD_RELEASE_SHA" \
    --output=/var/www/html/.ddev/.seed-build/seed.json >/dev/null
  ddev exec php scripts/development-seed/verify-seed.php \
    --metadata=/var/www/html/.ddev/.seed-build/seed.json \
    --database="/var/www/html/.ddev/.seed-build/$SNAPSHOT_NAME" \
    --repository=/var/www/html \
    --checkout-ref=HEAD >/dev/null
)
chmod 600 "$metadata"
database_sha="$(sha256sum "$database" | awk '{print $1}')"
reader_sha="$(sha256sum "$READER_SCRIPT" | awk '{print $1}')"
[[ "$database_sha" =~ ^[0-9a-f]{64}$ && "$reader_sha" =~ ^[0-9a-f]{64}$ ]]
[[ "$(jq -r '.database_sha256' "$metadata")" == "$database_sha" ]]
[[ "$(jq -r '.compatibility.ddev_minimum_version' "$metadata")" == '1.25.4' ]]
[[ "$(jq -r '.compatibility.database' "$metadata")" == 'mariadb:11.8' ]]
[[ "$(jq -r '.compatibility.snapshot_filename' "$metadata")" == "$SNAPSHOT_NAME" ]]
[[ "$(jq -r '.seed_id' "$metadata")" == "$SEED_ID" ]]
[[ "$(jq -r '.source_preprod_refresh_identity' "$metadata")" == "$SOURCE_PREPROD_REFRESH_ID" ]]
[[ "$(jq -r '.source_preprod_application_release_sha' "$metadata")" == "$SOURCE_PREPROD_RELEASE_SHA" ]]

# Publish only the fully sanitized/verified native snapshot and metadata.
storage_action PREPARE NONE NONE >/dev/null
incoming_may_exist=1
"${scp_args[@]}" -q -- "$database" "$remote_target:$REMOTE_INCOMING/$SNAPSHOT_NAME"
"${scp_args[@]}" -q -- "$metadata" "$remote_target:$REMOTE_INCOMING/seed.json"
"${scp_args[@]}" -q -- "$READER_SCRIPT" "$remote_target:$REMOTE_INCOMING/read-only-scp.sh"
storage_action COMMIT "$database_sha" "$reader_sha" >/dev/null
incoming_may_exist=0
storage_action VERIFY "$database_sha" "$reader_sha" >/dev/null

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

# The later #956 proof consumes the same immutable external snapshot through the
# local-first wrapper and DDEV's native seed primitive. Delivery #1108 does not
# execute this runtime path before merge.
git worktree add --detach "$proof" "$REPOSITORY_SHA" >/dev/null
proof_added=1
proof_name="agency-seed-proof-956-${GITHUB_RUN_ID}"
sed -i "1s/^name:.*/name: $proof_name/" "$proof/.ddev/config.yaml"

eval "$(ssh-agent -s)" >/dev/null
ssh_agent_started=1
ssh-add "$reader_key" >/dev/null
(
  cd "$proof"
  AGENCY_SEED_SSH_TARGET="agency-preprod@$PREPROD_SSH_HOST" \
  AGENCY_SEED_CACHE_DIR="$proof_cache" \
    bash scripts/development-seed/use-native-seed.sh fresh >/dev/null
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
rm -rf -- "$proof_cache"
[[ ! -e "$proof_cache" ]]
storage_action CLEANUP NONE NONE >/dev/null

ssh-agent -k >/dev/null
ssh_agent_started=0
rm -f -- "$raw" "$sanitize_diagnostic" "$known_hosts" "$reader_key" "$reader_key.pub"
[[ ! -e "$raw" && ! -e "$sanitize_diagnostic" && ! -e "$known_hosts" && ! -e "$reader_key" && ! -e "$reader_key.pub" ]]

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
ddev_native_snapshot=PASS
seed_storage=PUBLISHED
current_pointer=VERIFIED
read_only_distribution=PROVEN
ddev_native_seed=REAL_SUCCESS
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
printf '%s\n' \
  "SEED_ID=$SEED_ID" \
  "DATABASE_SHA256=$database_sha" \
  'SOURCE_IDENTITY_BINDING=CURRENT_JIT_FAIL_CLOSED' \
  'PREPROD_RUNTIME_DB_WRITE=NONE' \
  'PROD_ACCESS=NONE' \
  'RAW_PREPROD_ON_GITHUB_HOSTED=NONE' \
  'DEVELOPMENT_SANITIZATION=PASS' \
  'DDEV_NATIVE_SNAPSHOT=PASS' \
  'SEED_STORAGE=PUBLISHED' \
  'CURRENT_POINTER=VERIFIED' \
  'READ_ONLY_DISTRIBUTION=PROVEN' \
  'DDEV_NATIVE_SEED=REAL_SUCCESS' \
  'LOCAL_SIDE_EFFECT_ASSERTIONS=PASS' \
  'TEMPORARY_GENERATION_MATERIAL=ABSENT'
