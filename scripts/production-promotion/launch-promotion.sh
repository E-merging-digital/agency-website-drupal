#!/usr/bin/env bash
set -Eeuo pipefail

REQUEST_ID="${1:-}"
EXPECTED_SHA="${2:-}"
EXPECTED_ARTIFACT_SHA256="${3:-}"
EXPECTED_COMPOSER_LOCK_SHA256="${4:-}"
AUTH_COMMENT_ID="${5:-}"
AUTH_BODY_SHA256="${6:-}"
JOBS_DIR="/var/www/agency/shared/deploy-jobs"
LOCK_FILE="/var/www/agency/shared/deploy.lock"
REQUEST_DIR="$JOBS_DIR/$REQUEST_ID"
DEPLOY_SCRIPT="$REQUEST_DIR/promote-candidate.sh"
WORKER_SCRIPT="$REQUEST_DIR/worker.sh"
RESULT_FILE="$REQUEST_DIR/result.env"
PID_FILE="$REQUEST_DIR/pid"
METADATA_FILE="$REQUEST_DIR/request.env"
OUTPUT_FILE="$REQUEST_DIR/output.log"
BOOTSTRAP_LOG="$REQUEST_DIR/bootstrap.log"

[[ "$REQUEST_ID" =~ ^promote-[0-9]+-[0-9]+-[0-9a-f]{40}$ ]] || exit 2
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || exit 2
[[ "$EXPECTED_ARTIFACT_SHA256" =~ ^[0-9a-f]{64}$ ]] || exit 2
[[ "$EXPECTED_COMPOSER_LOCK_SHA256" =~ ^[0-9a-f]{64}$ ]] || exit 2
[[ "$AUTH_COMMENT_ID" =~ ^[0-9]+$ ]] || exit 2
[[ "$AUTH_BODY_SHA256" =~ ^[0-9a-f]{64}$ ]] || exit 2
[[ "$REQUEST_ID" == *"-$EXPECTED_SHA" ]] || exit 2
[[ -d "$REQUEST_DIR" && ! -L "$REQUEST_DIR" ]] || exit 2
[[ -f "$DEPLOY_SCRIPT" && ! -L "$DEPLOY_SCRIPT" ]] || exit 2
[[ ! -e "$RESULT_FILE" && ! -e "$PID_FILE" ]] || exit 2

for command in flock nohup setsid; do
  command -v "$command" >/dev/null 2>&1 || exit 2
done
setsid --help 2>&1 | grep -q -- '--wait' || exit 2

umask 077
chmod 700 "$DEPLOY_SCRIPT"
: > "$OUTPUT_FILE"
: > "$BOOTSTRAP_LOG"

{
  printf 'schema_version=1\n'
  printf 'request_id=%s\n' "$REQUEST_ID"
  printf 'expected_sha=%s\n' "$EXPECTED_SHA"
  printf 'expected_artifact_sha256=%s\n' "$EXPECTED_ARTIFACT_SHA256"
  printf 'expected_composer_lock_sha256=%s\n' "$EXPECTED_COMPOSER_LOCK_SHA256"
  printf 'authorization_comment_id=%s\n' "$AUTH_COMMENT_ID"
  printf 'authorization_body_sha256=%s\n' "$AUTH_BODY_SHA256"
  printf 'launched_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} > "$METADATA_FILE"

cat > "$WORKER_SCRIPT" <<'WORKER'
#!/usr/bin/env bash
set -Eeuo pipefail

REQUEST_DIR="${1:?}"
REQUEST_ID="${2:?}"
EXPECTED_SHA="${3:?}"
EXPECTED_ARTIFACT_SHA256="${4:?}"
EXPECTED_COMPOSER_LOCK_SHA256="${5:?}"
AUTH_COMMENT_ID="${6:?}"
AUTH_BODY_SHA256="${7:?}"
LOCK_FILE="${8:?}"
DEPLOY_SCRIPT="$REQUEST_DIR/promote-candidate.sh"
RESULT_FILE="$REQUEST_DIR/result.env"
PID_FILE="$REQUEST_DIR/pid"
OUTPUT_FILE="$REQUEST_DIR/output.log"
RESULT_WRITTEN=0

write_result() {
  local outcome="$1"
  local exit_code="$2"
  local tmp="${RESULT_FILE}.tmp.$$"
  RESULT_WRITTEN=1
  {
    printf 'schema_version=1\n'
    printf 'request_id=%s\n' "$REQUEST_ID"
    printf 'expected_sha=%s\n' "$EXPECTED_SHA"
    printf 'expected_artifact_sha256=%s\n' "$EXPECTED_ARTIFACT_SHA256"
    printf 'expected_composer_lock_sha256=%s\n' "$EXPECTED_COMPOSER_LOCK_SHA256"
    printf 'authorization_comment_id=%s\n' "$AUTH_COMMENT_ID"
    printf 'authorization_body_sha256=%s\n' "$AUTH_BODY_SHA256"
    printf 'outcome=%s\n' "$outcome"
    printf 'exit_code=%s\n' "$exit_code"
    printf 'worker_pid=%s\n' "$$"
    printf 'finished_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  } > "$tmp"
  mv -f "$tmp" "$RESULT_FILE"
}

trap 'status=$?; if [[ "$RESULT_WRITTEN" -eq 0 ]]; then write_result FAILURE "$status" || true; fi' EXIT
printf '%s\n' "$$" > "$PID_FILE"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  write_result LOCKED 75
  exit 75
fi

set +e
"$DEPLOY_SCRIPT" \
  "$EXPECTED_SHA" \
  "$EXPECTED_ARTIFACT_SHA256" \
  "$EXPECTED_COMPOSER_LOCK_SHA256" \
  "$AUTH_COMMENT_ID" \
  "$AUTH_BODY_SHA256" \
  "$REQUEST_DIR" >> "$OUTPUT_FILE" 2>&1
status="$?"
set -e
if [[ "$status" -eq 0 ]]; then
  write_result SUCCESS 0
else
  write_result FAILURE "$status"
fi
exit "$status"
WORKER
chmod 700 "$WORKER_SCRIPT"

nohup setsid --wait \
  "$WORKER_SCRIPT" \
  "$REQUEST_DIR" \
  "$REQUEST_ID" \
  "$EXPECTED_SHA" \
  "$EXPECTED_ARTIFACT_SHA256" \
  "$EXPECTED_COMPOSER_LOCK_SHA256" \
  "$AUTH_COMMENT_ID" \
  "$AUTH_BODY_SHA256" \
  "$LOCK_FILE" \
  </dev/null >> "$BOOTSTRAP_LOG" 2>&1 &

printf 'request_id=%s\n' "$REQUEST_ID"
printf 'expected_sha=%s\n' "$EXPECTED_SHA"
printf 'expected_artifact_sha256=%s\n' "$EXPECTED_ARTIFACT_SHA256"
printf 'launcher_outcome=DETACHED\n'
