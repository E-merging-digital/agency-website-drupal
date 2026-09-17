#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APPROVED_PLAN="${1:-}"
EXPECTED_DIGEST="${2:-}"
EXPECTED_MAIN="${3:-}"
PLAN_SCRIPT="${4:-}"
NGINX_TEMPLATE="${5:-}"
SETTINGS_TEMPLATE="${6:-}"
TOKEN_PROVISIONER="${7:-}"

PROJECT_ROOT='/var/www/agency-preprod'
SETTINGS_FILE="$PROJECT_ROOT/shared/settings/settings.php"
NGINX_SITE='/etc/nginx/sites-available/agency-preprod'
TOKEN_FILE='/etc/agency-preprod/cockpit-state-token'
ENDPOINT='https://preprod.emergingdigital.be/api/agency-operations/v1/environment-data-state'
BACKUP_DIR="$(mktemp -d /root/agency-1218-apply.XXXXXX)"
MUTATION_STARTED='NO'
COMMITTED='NO'

fail() {
  printf '[cockpit-transport-apply] ERROR: %s\n' "$1" >&2
  exit 1
}

rollback() {
  local rc="$?"
  if [[ "$rc" -ne 0 && "$MUTATION_STARTED" == 'YES' && "$COMMITTED" != 'YES' ]]; then
    rm -f -- "$TOKEN_FILE"
    if [[ -f "$BACKUP_DIR/settings.php" ]]; then
      cp -a -- "$BACKUP_DIR/settings.php" "$SETTINGS_FILE"
    fi
    if [[ -f "$BACKUP_DIR/nginx.conf" ]]; then
      cp -a -- "$BACKUP_DIR/nginx.conf" "$NGINX_SITE"
      nginx -t >/dev/null 2>&1 || true
      systemctl reload nginx >/dev/null 2>&1 || true
    fi
  fi
  rm -rf -- "$BACKUP_DIR"
  exit "$rc"
}
trap rollback EXIT

[[ "$(id -u)" -eq 0 ]] || fail 'Root authority is required.'
[[ "$EXPECTED_DIGEST" =~ ^[0-9a-f]{64}$ ]] || fail 'Expected PLAN digest is invalid.'
[[ "$EXPECTED_MAIN" =~ ^[0-9a-f]{40}$ ]] || fail 'Expected main SHA is invalid.'
for file in "$APPROVED_PLAN" "$PLAN_SCRIPT" "$NGINX_TEMPLATE" "$SETTINGS_TEMPLATE" "$TOKEN_PROVISIONER"; do
  [[ -f "$file" && ! -L "$file" ]] || fail "Required source is missing or unsafe: $file"
done
[[ -f "$SETTINGS_FILE" && ! -L "$SETTINGS_FILE" ]] || fail 'Shared settings.php is missing or unsafe.'
[[ -f "$NGINX_SITE" && ! -L "$NGINX_SITE" ]] || fail 'Nginx site is missing or unsafe.'
for command in curl jq nginx openssl php python3 sha256sum systemctl; do
  command -v "$command" >/dev/null 2>&1 || fail "$command is required."
done

jq -e --arg main "$EXPECTED_MAIN" --arg digest "$EXPECTED_DIGEST" '
  .STATUS == "PASS"
  and .ISSUE == 1218
  and .TARGET == "PREPROD"
  and .MODE == "PLAN"
  and .MAIN_SHA == $main
  and .PLAN_DIGEST == $digest
  and .PREPROD_MUTATION == "NONE"
  and .PROD_ACCESS == "NONE"
  and .SECRET_CONTENT_EXPOSED == false
' "$APPROVED_PLAN" >/dev/null

stale_json="$($PLAN_SCRIPT "$EXPECTED_MAIN" 'plan-1218-stale-check')"
stale_digest="$(jq -r '.PLAN_DIGEST' <<<"$stale_json")"
[[ "$stale_digest" == "$EXPECTED_DIGEST" ]] || fail 'STALE_PLAN: live PREPROD state no longer matches the approved PLAN.'

cp -a -- "$SETTINGS_FILE" "$BACKUP_DIR/settings.php"
cp -a -- "$NGINX_SITE" "$BACKUP_DIR/nginx.conf"

settings_tmp="$(mktemp "$(dirname "$SETTINGS_FILE")/.settings.php.1218.XXXXXX")"
nginx_tmp="$(mktemp "$(dirname "$NGINX_SITE")/.agency-preprod.1218.XXXXXX")"
cp -a -- "$SETTINGS_FILE" "$settings_tmp"
cp -a -- "$NGINX_SITE" "$nginx_tmp"

python3 - "$nginx_tmp" "$NGINX_TEMPLATE" <<'PY'
import re
import sys
from pathlib import Path

live_path = Path(sys.argv[1])
source_path = Path(sys.argv[2])
live = live_path.read_text(encoding='utf-8')
source = source_path.read_text(encoding='utf-8')
pattern = re.compile(
    r'(?ms)^\s*location\s*=\s*/api/agency-operations/v1/environment-data-state\s*\{.*?^\s*\}\n?'
)
source_matches = pattern.findall(source)
if len(source_matches) != 1:
    raise SystemExit('approved Nginx template does not contain exactly one machine-route block')
if pattern.search(live):
    raise SystemExit('live Nginx unexpectedly already contains the machine-route block')

socket_paths = set(re.findall(r'(?m)^\s*fastcgi_pass\s+unix:([^;]+);\s*$', live))
if len(socket_paths) != 1:
    raise SystemExit('live Nginx must expose exactly one unique PHP-FPM unix socket')
php_socket = next(iter(socket_paths))
if not php_socket.startswith('/'):
    raise SystemExit('live PHP-FPM socket path is unexpected')

marker = re.search(r'(?m)^\s*location\s+/\s*\{', live)
if marker is None:
    raise SystemExit('live Nginx insertion marker is missing')
block = source_matches[0].replace('@@PHP_SOCKET@@', php_socket)
if '@@' in block:
    raise SystemExit('approved machine-route block still contains an unresolved placeholder')
block = block.strip('\n') + '\n\n'
live_path.write_text(live[:marker.start()] + block + live[marker.start():], encoding='utf-8')
PY

python3 - "$settings_tmp" "$SETTINGS_TEMPLATE" <<'PY'
import re
import sys
from pathlib import Path

live_path = Path(sys.argv[1])
source_path = Path(sys.argv[2])
live = live_path.read_text(encoding='utf-8')
source = source_path.read_text(encoding='utf-8')
if '/etc/agency-preprod/cockpit-state-token' in live or 'agency_operations_cockpit_state_token' in live:
    raise SystemExit('live settings unexpectedly already contains the cockpit token reader')
pattern = re.compile(
    r'(?ms)^// The cockpit bearer is runtime-only server state\..*?^unset\(\$agency_cockpit_token_file\);\n'
)
match = pattern.search(source)
if match is None:
    raise SystemExit('approved settings template token-reader block is missing')
marker = '// PREPROD is fail-safe: never inherit production-only configuration.'
pos = live.find(marker)
if pos < 0:
    raise SystemExit('live settings insertion marker is missing')
block = match.group(0).rstrip('\n') + '\n\n'
live_path.write_text(live[:pos] + block + live[pos:], encoding='utf-8')
PY

php -l "$settings_tmp" >/dev/null
nginx_candidate_sha="$(sha256sum "$nginx_tmp" | awk '{print $1}')"
settings_candidate_sha="$(sha256sum "$settings_tmp" | awk '{print $1}')"
[[ "$nginx_candidate_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'Candidate Nginx SHA is invalid.'
[[ "$settings_candidate_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'Candidate settings SHA is invalid.'

MUTATION_STARTED='YES'
mv -f -- "$settings_tmp" "$SETTINGS_FILE"
mv -f -- "$nginx_tmp" "$NGINX_SITE"
php -l "$SETTINGS_FILE" >/dev/null
nginx -t >/dev/null

bearer="$(openssl rand -hex 32)"
[[ ${#bearer} -ge 32 ]] || fail 'Generated bearer is unexpectedly short.'
printf '%s\n' "$bearer" | "$TOKEN_PROVISIONER" >/dev/null
[[ "$(stat -c '%U:%G:%a' "$TOKEN_FILE")" == 'root:www-data:640' ]] || fail 'Runtime token ownership/mode mismatch.'

systemctl reload nginx

probe() {
  local label="$1"
  shift
  local body headers status kind
  body="$(mktemp)"
  headers="$(mktemp)"
  status="$(curl --silent --show-error --connect-timeout 10 --max-time 20 \
    --output "$body" --dump-header "$headers" --write-out '%{http_code}' \
    "$@" "$ENDPOINT")"
  kind="$(python3 - "$body" <<'PY'
import json
import sys
from pathlib import Path
raw = Path(sys.argv[1]).read_bytes()[:262144]
try:
    value = json.loads(raw.decode('utf-8'))
except Exception:
    print('NON_JSON')
else:
    print('JSON' if isinstance(value, (dict, list)) else 'JSON_SCALAR')
PY
)"
  rm -f -- "$body" "$headers"
  printf '%s|%s' "$status" "$kind"
}

no_auth="$(probe NO_AUTH)"
fake_bearer="$(probe FAKE_BEARER -H 'Authorization: Bearer invalid-cockpit-token-1218')"
real_bearer="$(probe REAL_BEARER -H "Authorization: Bearer $bearer")"
[[ "$no_auth" == '401|JSON' ]] || fail "No-auth contract mismatch: $no_auth"
[[ "$fake_bearer" == '401|JSON' ]] || fail "Fake-bearer contract mismatch: $fake_bearer"
[[ "$real_bearer" == '200|JSON' ]] || fail "Real-bearer contract mismatch: $real_bearer"

unset bearer
rm -f -- "$TOKEN_FILE"
cleanup_probe="$(probe CLEANUP_NO_AUTH)"
[[ "$cleanup_probe" == '503|JSON' || "$cleanup_probe" == '401|JSON' ]] || fail "Post-cleanup fail-closed contract mismatch: $cleanup_probe"

COMMITTED='YES'

jq -n \
  --arg main_sha "$EXPECTED_MAIN" \
  --arg plan_digest "$EXPECTED_DIGEST" \
  --arg nginx_sha "$nginx_candidate_sha" \
  --arg settings_sha "$settings_candidate_sha" \
  --arg no_auth "$no_auth" \
  --arg fake_bearer "$fake_bearer" \
  --arg real_bearer "$real_bearer" \
  --arg cleanup_probe "$cleanup_probe" \
  '{
    STATUS: "PASS",
    ISSUE: 1218,
    TARGET: "PREPROD",
    MODE: "APPLY",
    MAIN_SHA: $main_sha,
    PLAN_DIGEST: $plan_digest,
    STALE_PLAN: "PASS",
    NGINX_CONVERGED: true,
    SETTINGS_READER_CONVERGED: true,
    NGINX_SHA256: $nginx_sha,
    SETTINGS_SHA256: $settings_sha,
    TOKEN_PERMISSIONS_DURING_PROOF: "root:www-data:640",
    HTTP: {
      no_auth: $no_auth,
      fake_bearer: $fake_bearer,
      real_bearer: $real_bearer,
      post_cleanup_no_auth: $cleanup_probe
    },
    TOKEN_CLEANUP: "PASS",
    TOKEN_PERSISTED: false,
    PROD_ACCESS: "NONE",
    DB_MUTATION: "NONE",
    SECRET_CONTENT_EXPOSED: false,
    ROLLBACK_REQUIRED: false
  }'