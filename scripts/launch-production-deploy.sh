#!/usr/bin/env bash
set -Eeuo pipefail

REQUEST_ID="${1:-}"
EXPECTED_SHA="${2:-}"
JOBS_DIR="/var/www/agency/shared/deploy-jobs"
LOCK_FILE="/var/www/agency/shared/deploy.lock"
REQUEST_DIR="$JOBS_DIR/$REQUEST_ID"
DEPLOY_SCRIPT="$REQUEST_DIR/deploy-production.sh"
WORKER_SCRIPT="$REQUEST_DIR/worker.sh"
RESULT_FILE="$REQUEST_DIR/result.env"
PID_FILE="$REQUEST_DIR/pid"
METADATA_FILE="$REQUEST_DIR/request.env"
OUTPUT_FILE="$REQUEST_DIR/output.log"
BOOTSTRAP_LOG="$REQUEST_DIR/bootstrap.log"

if [[ ! "$REQUEST_ID" =~ ^run-[0-9]+-[0-9]+-[0-9a-f]{40}$ ]]; then
  echo "REQUEST_ID must match run-<run_id>-<attempt>-<sha>." >&2
  exit 2
fi

if [[ ! "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  echo "EXPECTED_SHA must be an exact 40-character lowercase Git commit SHA." >&2
  exit 2
fi

if [[ "$REQUEST_ID" != *"-$EXPECTED_SHA" ]]; then
  echo "REQUEST_ID does not contain EXPECTED_SHA." >&2
  exit 2
fi

for command in flock nohup setsid; do
  command -v "$command" >/dev/null 2>&1 || {
    echo "Required command is unavailable: $command" >&2
    exit 2
  }
done

setsid --help 2>&1 | grep -q -- '--wait' || {
  echo "setsid --wait is required for detached worker lifecycle tracking." >&2
  exit 2
}

[[ -d "$REQUEST_DIR" ]] || {
  echo "Request directory does not exist: $REQUEST_DIR" >&2
  exit 2
}
[[ ! -L "$REQUEST_DIR" ]] || {
  echo "Request directory must not be a symlink: $REQUEST_DIR" >&2
  exit 2
}
[[ -f "$DEPLOY_SCRIPT" && ! -L "$DEPLOY_SCRIPT" ]] || {
  echo "Exact deployment script is missing or unsafe: $DEPLOY_SCRIPT" >&2
  exit 2
}
[[ ! -e "$RESULT_FILE" ]] || {
  echo "Refusing to relaunch a request with an existing result: $RESULT_FILE" >&2
  exit 2
}
[[ ! -e "$PID_FILE" ]] || {
  echo "Refusing to relaunch a request with an existing pid file: $PID_FILE" >&2
  exit 2
}

umask 077
chmod 700 "$DEPLOY_SCRIPT"
: > "$OUTPUT_FILE"
: > "$BOOTSTRAP_LOG"

metadata_tmp="${METADATA_FILE}.tmp.$$"
{
  printf 'schema_version=1\n'
  printf 'request_id=%s\n' "$REQUEST_ID"
  printf 'expected_sha=%s\n' "$EXPECTED_SHA"
  printf 'launched_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} > "$metadata_tmp"
mv -f "$metadata_tmp" "$METADATA_FILE"

cat > "$WORKER_SCRIPT" <<'WORKER'
#!/usr/bin/env bash
set -Eeuo pipefail

REQUEST_DIR="${1:?request directory required}"
REQUEST_ID="${2:?request id required}"
EXPECTED_SHA="${3:?expected sha required}"
LOCK_FILE="${4:?lock file required}"
DEPLOY_SCRIPT="$REQUEST_DIR/deploy-production.sh"
RESULT_FILE="$REQUEST_DIR/result.env"
PID_FILE="$REQUEST_DIR/pid"
OUTPUT_FILE="$REQUEST_DIR/output.log"
RESULT_WRITTEN=0

write_result() {
  local outcome="$1"
  local exit_code="$2"
  local result_tmp="${RESULT_FILE}.tmp.$$"

  RESULT_WRITTEN=1
  {
    printf 'schema_version=1\n'
    printf 'request_id=%s\n' "$REQUEST_ID"
    printf 'expected_sha=%s\n' "$EXPECTED_SHA"
    printf 'outcome=%s\n' "$outcome"
    printf 'exit_code=%s\n' "$exit_code"
    printf 'worker_pid=%s\n' "$$"
    printf 'finished_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  } > "$result_tmp"
  mv -f "$result_tmp" "$RESULT_FILE"
}

finalize_unexpected_exit() {
  local exit_code="$?"
  if [[ "$RESULT_WRITTEN" -eq 0 ]]; then
    write_result FAILURE "$exit_code" || true
  fi
}
trap finalize_unexpected_exit EXIT

pid_tmp="${PID_FILE}.tmp.$$"
printf '%s\n' "$$" > "$pid_tmp"
mv -f "$pid_tmp" "$PID_FILE"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  printf '[%s] production deploy lock is already held\n' "$(date '+%Y-%m-%d %H:%M:%S')" >> "$OUTPUT_FILE"
  write_result LOCKED 75
  exit 75
fi

printf '[%s] production deploy lock acquired for %s\n' \
  "$(date '+%Y-%m-%d %H:%M:%S')" \
  "$REQUEST_ID" >> "$OUTPUT_FILE"

set +e
"$DEPLOY_SCRIPT" main "$EXPECTED_SHA" >> "$OUTPUT_FILE" 2>&1
deploy_exit="$?"
set -e

if [[ "$deploy_exit" -eq 0 ]]; then
  write_result SUCCESS 0
else
  write_result FAILURE "$deploy_exit"
fi

exit "$deploy_exit"
WORKER

chmod 700 "$WORKER_SCRIPT"

nohup setsid --wait \
  "$WORKER_SCRIPT" \
  "$REQUEST_DIR" \
  "$REQUEST_ID" \
  "$EXPECTED_SHA" \
  "$LOCK_FILE" \
  </dev/null >> "$BOOTSTRAP_LOG" 2>&1 &

printf 'request_id=%s\n' "$REQUEST_ID"
printf 'expected_sha=%s\n' "$EXPECTED_SHA"
printf 'launcher_outcome=DETACHED\n'
