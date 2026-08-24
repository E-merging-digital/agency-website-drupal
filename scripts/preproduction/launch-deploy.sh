#!/usr/bin/env bash
set -Eeuo pipefail

REQUEST_ID="${1:-}"
CANDIDATE_SHA="${2:-}"
ARTIFACT_SHA256="${3:-}"
COMPOSER_LOCK_SHA256="${4:-}"
PROJECT_ROOT="${PROJECT_ROOT:-/var/www/agency-preprod}"
JOBS_DIR="$PROJECT_ROOT/shared/deploy-jobs"
LOCK_FILE="$PROJECT_ROOT/shared/deploy.lock"
REQUEST_DIR="$JOBS_DIR/$REQUEST_ID"
DEPLOY_SCRIPT="$REQUEST_DIR/deploy-artifact.sh"
PAYLOAD="$REQUEST_DIR/agency-release-candidate.tar.gz"
METADATA="$REQUEST_DIR/candidate.json"
WORKER_SCRIPT="$REQUEST_DIR/worker.sh"
RESULT_FILE="$REQUEST_DIR/result.env"
PID_FILE="$REQUEST_DIR/pid"
OUTPUT_FILE="$REQUEST_DIR/output.log"

[[ "$REQUEST_ID" =~ ^run-[0-9]+-[0-9]+-[0-9a-f]{40}$ ]] || { echo "Invalid request id." >&2; exit 2; }
[[ "$CANDIDATE_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "Invalid candidate SHA." >&2; exit 2; }
[[ "$REQUEST_ID" == *"-$CANDIDATE_SHA" ]] || { echo "Request SHA mismatch." >&2; exit 2; }
[[ "$ARTIFACT_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo "Invalid artifact digest." >&2; exit 2; }
[[ "$COMPOSER_LOCK_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo "Invalid lock digest." >&2; exit 2; }
[[ -d "$REQUEST_DIR" && ! -L "$REQUEST_DIR" ]] || { echo "Unsafe request directory." >&2; exit 2; }
for file in "$DEPLOY_SCRIPT" "$PAYLOAD" "$METADATA"; do
  [[ -f "$file" && ! -L "$file" ]] || { echo "Required request file is missing or unsafe: $file" >&2; exit 2; }
done
[[ ! -e "$RESULT_FILE" && ! -e "$PID_FILE" ]] || { echo "Request was already launched." >&2; exit 2; }

umask 077
chmod 700 "$DEPLOY_SCRIPT"
: > "$OUTPUT_FILE"

cat > "$WORKER_SCRIPT" <<'EOF_WORKER'
#!/usr/bin/env bash
set -Eeuo pipefail
REQUEST_DIR="${1:?}"
REQUEST_ID="${2:?}"
CANDIDATE_SHA="${3:?}"
ARTIFACT_SHA256="${4:?}"
COMPOSER_LOCK_SHA256="${5:?}"
LOCK_FILE="${6:?}"
RESULT_FILE="$REQUEST_DIR/result.env"
PID_FILE="$REQUEST_DIR/pid"
OUTPUT_FILE="$REQUEST_DIR/output.log"
RESULT_WRITTEN=0

write_result() {
  local outcome="$1" exit_code="$2"
  local tmp="${RESULT_FILE}.tmp.$$"
  RESULT_WRITTEN=1
  {
    printf 'schema_version=1\n'
    printf 'request_id=%s\n' "$REQUEST_ID"
    printf 'candidate_sha=%s\n' "$CANDIDATE_SHA"
    printf 'artifact_sha256=%s\n' "$ARTIFACT_SHA256"
    printf 'composer_lock_sha256=%s\n' "$COMPOSER_LOCK_SHA256"
    printf 'outcome=%s\n' "$outcome"
    printf 'exit_code=%s\n' "$exit_code"
    printf 'finished_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  } > "$tmp"
  mv -f "$tmp" "$RESULT_FILE"
}
trap 'rc=$?; [[ "$RESULT_WRITTEN" -eq 1 ]] || write_result FAILURE "$rc" || true' EXIT
printf '%s\n' "$$" > "$PID_FILE"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  write_result LOCKED 75
  exit 75
fi
set +e
"$REQUEST_DIR/deploy-artifact.sh" \
  "$CANDIDATE_SHA" "$ARTIFACT_SHA256" "$COMPOSER_LOCK_SHA256" \
  "$REQUEST_DIR/agency-release-candidate.tar.gz" "$REQUEST_DIR/candidate.json" \
  >> "$OUTPUT_FILE" 2>&1
rc="$?"
set -e
if [[ "$rc" -eq 0 ]]; then write_result SUCCESS 0; else write_result FAILURE "$rc"; fi
exit "$rc"
EOF_WORKER
chmod 700 "$WORKER_SCRIPT"
nohup setsid --wait "$WORKER_SCRIPT" \
  "$REQUEST_DIR" "$REQUEST_ID" "$CANDIDATE_SHA" "$ARTIFACT_SHA256" "$COMPOSER_LOCK_SHA256" "$LOCK_FILE" \
  </dev/null >> "$REQUEST_DIR/bootstrap.log" 2>&1 &

printf 'request_id=%s\ncandidate_sha=%s\nlauncher_outcome=DETACHED\n' "$REQUEST_ID" "$CANDIDATE_SHA"
