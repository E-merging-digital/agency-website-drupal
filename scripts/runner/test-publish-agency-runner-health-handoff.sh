#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=publish-agency-runner-health-handoff.sh
source "$SCRIPT_DIR/publish-agency-runner-health-handoff.sh"

tmp_root="$(mktemp -d)"
trap 'rm -rf -- "$tmp_root"' EXIT

source_file="$tmp_root/source.json"
target="$tmp_root/safe/agency-runner-health.json"

cat > "$source_file" <<'JSON'
{
  "schema_version": 1,
  "project_id": "agency-website",
  "receipt_id": "agency-health-20260920T100000Z",
  "observed_at": "2026-09-20T10:00:00Z",
  "runner": {"status": "healthy", "name": "agency-browser-runner-01", "host": "preflight-runner-01"}
}
JSON

publish_agency_runner_health_handoff "$source_file" "$target"
cmp -s "$source_file" "$target"
[[ "$(stat -c '%a' "$target")" == '600' ]]
[[ "$(stat -c '%a' "$(dirname "$target")")" == '700' ]]

if find "$(dirname "$target")" -maxdepth 1 -name '.agency-runner-health.json.tmp.*' | grep -q .; then
  echo 'temporary file leaked after atomic publication' >&2
  exit 1
fi

before_sha="$(sha256sum "$target" | awk '{print $1}')"

missing="$tmp_root/missing.json"
if publish_agency_runner_health_handoff "$missing" "$target"; then
  echo 'missing source was accepted' >&2
  exit 1
fi

invalid="$tmp_root/invalid.json"
printf '{broken' > "$invalid"
if publish_agency_runner_health_handoff "$invalid" "$target"; then
  echo 'invalid JSON was accepted' >&2
  exit 1
fi

secret="$tmp_root/secret.json"
printf '{"token":"should-not-publish"}\n' > "$secret"
if publish_agency_runner_health_handoff "$secret" "$target"; then
  echo 'secret-like receipt was accepted' >&2
  exit 1
fi

after_sha="$(sha256sum "$target" | awk '{print $1}')"
[[ "$before_sha" == "$after_sha" ]]

oversized="$tmp_root/oversized.json"
python3 - "$oversized" <<'PY'
import json
import sys
with open(sys.argv[1], "w", encoding="utf-8") as stream:
    json.dump({"payload": "x" * 9000}, stream)
PY
if publish_agency_runner_health_handoff "$oversized" "$target"; then
  echo 'oversized receipt was accepted' >&2
  exit 1
fi

wrong_target="$tmp_root/safe/not-allowed.json"
if publish_agency_runner_health_handoff "$source_file" "$wrong_target"; then
  echo 'unexpected target filename was accepted' >&2
  exit 1
fi

symlink_parent_real="$tmp_root/real-parent"
mkdir "$symlink_parent_real"
ln -s "$symlink_parent_real" "$tmp_root/link-parent"
if publish_agency_runner_health_handoff "$source_file" "$tmp_root/link-parent/agency-runner-health.json"; then
  echo 'symlink parent was accepted' >&2
  exit 1
fi

target_parent="$tmp_root/symlink-target"
mkdir "$target_parent"
victim="$tmp_root/victim.json"
printf '{"victim":true}\n' > "$victim"
ln -s "$victim" "$target_parent/agency-runner-health.json"
if publish_agency_runner_health_handoff "$source_file" "$target_parent/agency-runner-health.json"; then
  echo 'symlink target was accepted' >&2
  exit 1
fi

replacement="$tmp_root/replacement.json"
sed 's/"healthy"/"warning"/' "$source_file" > "$replacement"
publish_agency_runner_health_handoff "$replacement" "$target"
cmp -s "$replacement" "$target"

echo 'RUNNER_HEALTH_HANDOFF_TEST=PASS'