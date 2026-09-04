#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

MODE="${AGENCY_PREPROD_IMAGE_REHYDRATE_MODE:-}"
ISSUE_NUMBER="${ISSUE_NUMBER:-}"
PREPROD_SERVER_HOST="${PREPROD_SERVER_HOST:-}"
PREPROD_SSH_KEY="${PREPROD_SSH_KEY:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-artifacts/preprod-editorial-image-rehydrate-971}"

[[ "$ISSUE_NUMBER" == '971' ]] || {
  echo 'This rehydration runner is bound to issue #971.' >&2
  exit 1
}
case "$MODE" in
  dry-run|apply) ;;
  *) echo 'Unsupported #971 rehydration mode.' >&2; exit 1 ;;
esac
[[ -n "$PREPROD_SERVER_HOST" ]]
[[ "$PREPROD_SERVER_HOST" =~ ^[A-Za-z0-9.-]+$ ]]
[[ -f "$PREPROD_SSH_KEY" ]]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
RUNTIME="$SCRIPT_DIR/preprod-editorial-image-rehydrate-971.php"
REGISTRY="$SCRIPT_DIR/editorial-feature-image-profiles.json"
GENERATOR="$SCRIPT_DIR/generate-editorial-feature-image-401.py"
ASSET="$REPO_ROOT/assets/editorial/issue-401-redesign-checklist.png"
EXPECTED_SHA='70bf17abe69d9b817b610de1e9529d468270a9a8206268bf0bb5736f82e6b898'
REMOTE_ASSET='/tmp/agency-preprod-image-rehydrate-971-asset.png'
PREPROD_ROOT='/var/www/agency-preprod/current'
PREPROD_TRUST_PROVISION="$REPO_ROOT/scripts/preproduction-ssh-trust/manage-known-host.sh"
PREPROD_TRUST_VERIFY="$REPO_ROOT/scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh"

[[ -f "$RUNTIME" ]]
[[ -f "$REGISTRY" ]]
[[ -f "$GENERATOR" ]]
[[ -f "$ASSET" ]]
[[ "$(sha256sum "$ASSET" | awk '{print $1}')" == "$EXPECTED_SHA" ]]
php -l "$RUNTIME" >/dev/null
mkdir -p "$ARTIFACT_DIR"

profile_check="$(python3 - "$REGISTRY" <<'PY'
import json
import sys
from pathlib import Path

registry = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
profile = registry.get('profiles', {}).get('401')
if not isinstance(profile, dict):
    raise SystemExit(2)
expected = {
    'issue_number': 401,
    'bundle': 'article',
    'article_payload_sha256': '489bf57f89e519862d5a1ae46f2d3335dd213993be0d2cf83b47694cfab22acf',
    'field_name': 'field_feature_image',
}
for key, value in expected.items():
    if profile.get(key) != value:
        raise SystemExit(3)
asset = profile.get('asset', {})
if asset.get('path') != 'assets/editorial/issue-401-redesign-checklist.png':
    raise SystemExit(4)
if asset.get('filename') != 'issue-401-redesign-checklist-70bf17abe69d.png':
    raise SystemExit(5)
if asset.get('sha256') != '70bf17abe69d9b817b610de1e9529d468270a9a8206268bf0bb5736f82e6b898':
    raise SystemExit(6)
if profile.get('alt') != {
    'fr': 'Checklist de préparation avant la refonte d’un site web',
    'en': 'Website redesign preparation checklist',
}:
    raise SystemExit(7)
print('PASS')
PY
)"
[[ "$profile_check" == 'PASS' ]]

PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" bash "$PREPROD_TRUST_PROVISION" PROVISION >/dev/null
PREPROD_SERVER_HOST="$PREPROD_SERVER_HOST" \
  PREPROD_KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts" \
  bash "$PREPROD_TRUST_VERIFY" >/dev/null

ssh_common=(
  -i "$PREPROD_SSH_KEY"
  -o IdentitiesOnly=yes
  -o BatchMode=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
  -o ConnectTimeout=15
)
remote_target="agency-preprod@$PREPROD_SERVER_HOST"
remote_cleanup_armed=0

cleanup_remote() {
  if [[ "$remote_cleanup_armed" != '1' ]]; then
    return 0
  fi
  set +e
  ssh "${ssh_common[@]}" "$remote_target" "rm -f '$REMOTE_ASSET'" >/dev/null 2>&1
}
trap cleanup_remote EXIT

remote_runtime_validate() {
  ssh "${ssh_common[@]}" "$remote_target" \
    "set -euo pipefail; test -x '$PREPROD_ROOT/scripts/preproduction/validate-runtime.sh'; '$PREPROD_ROOT/scripts/preproduction/validate-runtime.sh' >/dev/null"
}

php_code="$(tail -n +2 "$RUNTIME")"
encoded_code="$(printf '%s' "$php_code" | base64 -w 0)"
registry_b64="$(base64 -w 0 "$REGISTRY")"
[[ "$encoded_code" =~ ^[A-Za-z0-9+/=]+$ ]]
[[ "$registry_b64" =~ ^[A-Za-z0-9+/=]+$ ]]

remote_eval() {
  local run_mode="$1"
  local output_file="$2"
  local remote_command
  printf -v remote_command \
    "set -euo pipefail; cd '$PREPROD_ROOT'; test -x vendor/bin/drush; vendor/bin/drush status --fields=bootstrap >/dev/null; code=\$(printf '%%s' '%s' | base64 -d); AGENCY_PREPROD_IMAGE_REHYDRATE_MODE='%s' AGENCY_PREPROD_IMAGE_REHYDRATE_REGISTRY_B64='%s' vendor/bin/drush php:eval \"\$code\"" \
    "$encoded_code" "$run_mode" "$registry_b64"
  ssh "${ssh_common[@]}" "$remote_target" "$remote_command" > "$output_file"
  jq -e '
    .schema_version == 1
    and .status == "PASS"
    and .target == "PREPROD"
    and .issue_number == 971
    and .profile_issue == 401
    and .node.node_id == 37
    and .node.fid == 2
    and .node.uri == "public://articles/issue-401-redesign-checklist-70bf17abe69d.png"
    and .source_sha256 == "70bf17abe69d9b817b610de1e9529d468270a9a8206268bf0bb5736f82e6b898"
    and .node.article_payload_sha256 == "489bf57f89e519862d5a1ae46f2d3335dd213993be0d2cf83b47694cfab22acf"
    and .node.alt_fr == "Checklist de préparation avant la refonte d’un site web"
    and .node.alt_en == "Website redesign preparation checklist"
    and .derivatives_generated == false
    and .prod_access == "NONE"
    and .prod_write == "NONE"
  ' "$output_file" >/dev/null
}

remote_runtime_validate

if [[ "$MODE" == 'dry-run' ]]; then
  remote_eval dry-run "$ARTIFACT_DIR/result.json"
  jq -e '.mode == "dry-run" and (.verdict == "READY_TO_REHYDRATE" or .verdict == "IDEMPOTENT")' \
    "$ARTIFACT_DIR/result.json" >/dev/null
else
  remote_eval dry-run "$ARTIFACT_DIR/preapply.json"
  preapply_verdict="$(jq -r '.verdict' "$ARTIFACT_DIR/preapply.json")"
  case "$preapply_verdict" in
    READY_TO_REHYDRATE)
      ssh "${ssh_common[@]}" "$remote_target" "test ! -e '$REMOTE_ASSET'"
      remote_cleanup_armed=1
      scp "${ssh_common[@]}" "$ASSET" "$remote_target:$REMOTE_ASSET" >/dev/null
      ;;
    IDEMPOTENT)
      ;;
    *)
      echo 'The #971 apply precondition is not satisfied.' >&2
      exit 1
      ;;
  esac

  remote_eval apply "$ARTIFACT_DIR/result.json"
  jq -e '.mode == "apply" and (.verdict == "REHYDRATED" or .verdict == "IDEMPOTENT")' \
    "$ARTIFACT_DIR/result.json" >/dev/null
  remote_eval dry-run "$ARTIFACT_DIR/postapply.json"
  jq -e '.mode == "dry-run" and .verdict == "IDEMPOTENT"' \
    "$ARTIFACT_DIR/postapply.json" >/dev/null
  tmp="$ARTIFACT_DIR/result.tmp.json"
  jq --arg preapply "$preapply_verdict" \
    '. + {preapply_verdict:$preapply,post_apply_verdict:"IDEMPOTENT"}' \
    "$ARTIFACT_DIR/result.json" > "$tmp"
  mv "$tmp" "$ARTIFACT_DIR/result.json"
fi

remote_runtime_validate
current_release="$(ssh "${ssh_common[@]}" "$remote_target" \
  "set -euo pipefail; basename \"\$(readlink -f '$PREPROD_ROOT')\"")"
[[ "$current_release" =~ ^[A-Za-z0-9._-]+$ ]]
tmp="$ARTIFACT_DIR/result.tmp.json"
jq --arg current_release "$current_release" \
  '. + {preprod_runtime_validation:"PASS",preprod_current_release:$current_release}' \
  "$ARTIFACT_DIR/result.json" > "$tmp"
mv "$tmp" "$ARTIFACT_DIR/result.json"
