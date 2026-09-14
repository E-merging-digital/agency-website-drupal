#!/usr/bin/env bash
set -Eeuo pipefail

ISSUE_NUMBER="${ISSUE_NUMBER:-}"
PAYLOAD_SHA256="${PAYLOAD_SHA256:-}"
PAYLOAD_FILE="${PAYLOAD_FILE:-}"
PROFILE_SHA256="${PROFILE_SHA256:-}"
PROFILE_FILE="${PROFILE_FILE:-}"
ASSET_SHA256="${ASSET_SHA256:-}"
ASSET_FILE="${ASSET_FILE:-}"
SERVER_HOST="${SERVER_HOST:-}"
SERVER_USER="${SERVER_USER:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/editorial-publication}"
RUN_ID="${GITHUB_RUN_ID:-0}"
RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-1}"

[[ "$ISSUE_NUMBER" =~ ^[1-9][0-9]*$ ]] || { echo 'ISSUE_NUMBER must be positive numeric.' >&2; exit 1; }
[[ "$PAYLOAD_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo 'PAYLOAD_SHA256 must be lowercase SHA-256.' >&2; exit 1; }
[[ "$PROFILE_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo 'PROFILE_SHA256 must be lowercase SHA-256.' >&2; exit 1; }
[[ "$ASSET_SHA256" =~ ^[0-9a-f]{64}$ ]] || { echo 'ASSET_SHA256 must be lowercase SHA-256.' >&2; exit 1; }
[[ "$RUN_ID" =~ ^[0-9]+$ ]]
[[ "$RUN_ATTEMPT" =~ ^[0-9]+$ ]]
[[ -n "$SERVER_HOST" ]]
[[ -n "$SERVER_USER" ]]
[[ -f "$PAYLOAD_FILE" ]]
[[ -f "$PROFILE_FILE" ]]
[[ -f "$ASSET_FILE" ]]
[[ "$(sha256sum "$PAYLOAD_FILE" | awk '{print $1}')" == "$PAYLOAD_SHA256" ]]
[[ "$(sha256sum "$ASSET_FILE" | awk '{print $1}')" == "$ASSET_SHA256" ]]

actual_profile_sha="$(python3 - "$PROFILE_FILE" "$ISSUE_NUMBER" <<'PY_PROFILE'
import hashlib
import json
import sys
from pathlib import Path

registry = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
profile = registry.get('profiles', {}).get(sys.argv[2])
if not isinstance(profile, dict):
    raise SystemExit('Missing exact image profile.')
canonical = json.dumps(profile, ensure_ascii=False, sort_keys=True, separators=(',', ':')) + '\n'
print(hashlib.sha256(canonical.encode('utf-8')).hexdigest())
PY_PROFILE
)"
[[ "$actual_profile_sha" == "$PROFILE_SHA256" ]]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PUBLICATION_LIBRARY="$SCRIPT_DIR/editorial-publication.php"
IMAGE_LIBRARY="$SCRIPT_DIR/editorial-feature-image.php"
PROMOTION_LIBRARY="$SCRIPT_DIR/editorial-promotion.php"
RUNTIME_SCRIPT="$SCRIPT_DIR/editorial-promotion-runtime.php"
for required in "$PUBLICATION_LIBRARY" "$IMAGE_LIBRARY" "$PROMOTION_LIBRARY" "$RUNTIME_SCRIPT"; do
  [[ -f "$required" ]]
done
php -l "$PUBLICATION_LIBRARY" >/dev/null
php -l "$IMAGE_LIBRARY" >/dev/null
php -l "$PROMOTION_LIBRARY" >/dev/null
php -l "$RUNTIME_SCRIPT" >/dev/null
mkdir -p "$ARTIFACT_DIR"

remote_stem="/tmp/agency-editorial-promotion-${ISSUE_NUMBER}-${RUN_ID}-${RUN_ATTEMPT}"
remote_publication="${remote_stem}-publication.php"
remote_image="${remote_stem}-image.php"
remote_promotion="${remote_stem}-promotion.php"
remote_runtime="${remote_stem}-runtime.php"
remote_payload="${remote_stem}-payload.json"
remote_profile="${remote_stem}-profiles.json"
remote_asset="${remote_stem}-asset.png"
remote_result="${remote_stem}-result.json"
remote_target="${SERVER_USER}@${SERVER_HOST}"
ssh_opts=(-o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=15 -o ServerAliveCountMax=4)

cleanup_remote() {
  set +e
  ssh "${ssh_opts[@]}" "$remote_target" \
    "rm -f '$remote_publication' '$remote_image' '$remote_promotion' '$remote_runtime' '$remote_payload' '$remote_profile' '$remote_asset' '$remote_result'" \
    >/dev/null 2>&1
}
trap cleanup_remote EXIT

scp "${ssh_opts[@]}" "$PUBLICATION_LIBRARY" "$remote_target:$remote_publication" >/dev/null
scp "${ssh_opts[@]}" "$IMAGE_LIBRARY" "$remote_target:$remote_image" >/dev/null
scp "${ssh_opts[@]}" "$PROMOTION_LIBRARY" "$remote_target:$remote_promotion" >/dev/null
scp "${ssh_opts[@]}" "$RUNTIME_SCRIPT" "$remote_target:$remote_runtime" >/dev/null
scp "${ssh_opts[@]}" "$PAYLOAD_FILE" "$remote_target:$remote_payload" >/dev/null
scp "${ssh_opts[@]}" "$PROFILE_FILE" "$remote_target:$remote_profile" >/dev/null
scp "${ssh_opts[@]}" "$ASSET_FILE" "$remote_target:$remote_asset" >/dev/null

# A recoverable partial failure may leave only an unpublished Article staged in
# PROD. Backup is taken before any Drupal mutation.
timestamp="$(date -u +%Y%m%d%H%M%S)"
backup_stem="/var/www/agency/shared/backups/editorial-promotion-${ISSUE_NUMBER}-${timestamp}.sql"
backup_file="${backup_stem}.gz"
ssh "${ssh_opts[@]}" "$remote_target" \
  "set -euo pipefail; cd /var/www/agency/current; mkdir -p /var/www/agency/shared/backups; vendor/bin/drush status --fields=bootstrap >/dev/null; vendor/bin/drush sql:dump --gzip --result-file='$backup_stem' >/dev/null; test -s '$backup_file'"

ssh "${ssh_opts[@]}" "$remote_target" \
  "set -euo pipefail; cd /var/www/agency/current; AGENCY_EDITORIAL_ISSUE='$ISSUE_NUMBER' AGENCY_EDITORIAL_PAYLOAD_SHA='$PAYLOAD_SHA256' AGENCY_EDITORIAL_PAYLOAD_PATH='$remote_payload' AGENCY_EDITORIAL_IMAGE_PROFILE_PATH='$remote_profile' AGENCY_EDITORIAL_IMAGE_ASSET_PATH='$remote_asset' AGENCY_EDITORIAL_RESULT_PATH='$remote_result' AGENCY_EDITORIAL_LIBRARY_PATH='$remote_publication' AGENCY_EDITORIAL_IMAGE_LIBRARY_PATH='$remote_image' AGENCY_EDITORIAL_PROMOTION_LIBRARY_PATH='$remote_promotion' timeout 180s vendor/bin/drush php:script '$remote_runtime'; vendor/bin/drush cr >/dev/null"

scp "${ssh_opts[@]}" "$remote_target:$remote_result" "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.status == "PASS" and (.verdict == "PROMOTED" or .verdict == "IDEMPOTENT") and .visual_completeness == "PASS"' \
  "$ARTIFACT_DIR/result.json" >/dev/null

tmp_result="$ARTIFACT_DIR/result.tmp.json"
jq --arg backup_file "$backup_file" '. + {backup_file:$backup_file}' \
  "$ARTIFACT_DIR/result.json" > "$tmp_result"
mv "$tmp_result" "$ARTIFACT_DIR/result.json"
