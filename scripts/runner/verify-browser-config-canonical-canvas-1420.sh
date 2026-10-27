#!/usr/bin/env bash
set -euo pipefail

# R26: one trusted fail-closed canonical config gate for all three browser stages.
: "${CANVAS_BROWSER_PR_NUMBER:?Missing canonical browser PR number}"
[[ "$CANVAS_BROWSER_PR_NUMBER" =~ ^(0|[1-9][0-9]*)$ ]] || exit 1
test "${GITHUB_REPOSITORY:-}" = "E-merging-digital/agency-website-drupal"

# Never execute a gate, filter or helper supplied by a mutable PR.
for pair in \
  'scripts/runner/canvas-runtime-diff-paths-995.php|bdc6016b208fdad332880e4b943aa2aa0af2e94b' \
  'scripts/runner/canvas-ai-provider-config-drift-gate-1373.php|92f1bc7def35e2c632d1a0e70d95579fe7365dcf' \
  'scripts/runner/run-canvas-ai-provider-config-drift-gate-1375.sh|c514da23bc6f87e0ce27c86bbb2c8761d0a9e67c' \
  'scripts/runner/filter-config-status-metadata.php|2c4efe9742fcca89d8169f2edcdc0f2dad99e579'
do
  IFS='|' read -r path expected_sha <<< "$pair"
  test "$(git hash-object "$path")" = "$expected_sha" || {
    echo "Unreviewed trusted Canvas gate source: $path" >&2
    exit 1
  }
done

# Preserve the existing canonical export artifact on any unrecognized drift.
# Never print the exported YAML, the patch or configuration scalar values.
fail_closed() {
  mkdir -p artifacts/config-canonicalization
  if ! ddev drush config:status > artifacts/config-canonicalization/config-status.txt 2>/dev/null; then
    printf 'CONFIG_STATUS_UNAVAILABLE\n' > artifacts/config-canonicalization/config-status.txt
  fi
  if ddev drush cex -y >/dev/null 2>&1; then
    git status --short config/sync > artifacts/config-canonicalization/git-status.txt
    git diff --binary -- config/sync > artifacts/config-canonicalization/canonical-config.patch
  fi
  echo 'Canonical configuration drift not admitted by trusted Canvas gate.' >&2
  exit 1
}

# Admit zero-difference status for canonical inputs. The reviewed main (0),
# MCP #1421, homepage #1458, Services #1472 and IA & Drupal #1474 may
# submit nonzero drift only to the SAME strict #1373/#995 classifier;
# no name/path/shape bypass.
if ! status_json="$(ddev drush config:status --format=json 2>/dev/null)"; then
  fail_closed
fi
[[ -n "$status_json" ]] || fail_closed
if ! status_metadata="$(printf '%s' "$status_json" | php scripts/runner/filter-config-status-metadata.php PREPROD 2>/dev/null)"; then
  fail_closed
fi
if ! count="$(jq -er '
  if (.schema_version == 1)
    and (.environment == "PREPROD")
    and (.config_values_exposed == false)
    and (.items | type == "array")
    and (.items | all(
      (keys | sort) == ["classification","config_name","environment","operation","state"]
      and (.config_name | type == "string")
      and (.state | type == "string")
    ))
  then .items | length else error("Invalid status metadata") end
' <<< "$status_metadata" 2>/dev/null)"; then
  fail_closed
fi
if [[ "$count" == "0" ]]; then
  echo 'CANVAS_BROWSER_CONFIG_GATE=PASS_CLEAN'
  exit 0
fi
case "$CANVAS_BROWSER_PR_NUMBER" in
  0|1421|1458|1472|1474) ;;
  *) fail_closed ;;
esac

# Reuse exactly #1373 metadata admission + #995 real active/sync comparator.
if ! status_payload="$(jq -ce '{items: [.items[] | {config_name, state}]}' \
  <<< "$status_metadata" 2>/dev/null)"; then
  fail_closed
fi
status_b64="$(printf '%s' "$status_payload" | base64 -w 0)"
gate_code="$(
  tail -n +2 scripts/runner/canvas-runtime-diff-paths-995.php
  sed '1,/^declare(strict_types=1);$/d' \
    scripts/runner/canvas-ai-provider-config-drift-gate-1373.php
)"
gate_code_b64="$(printf '%s' "$gate_code" | base64 -w 0)"
unset gate_code status_payload status_json status_metadata

if ! evidence="$(ddev exec env \
  AGENCY_CANVAS_1373_EXECUTE=1 \
  AGENCY_CANVAS_1373_STATUS_B64="$status_b64" \
  AGENCY_CANVAS_1375_CODE_B64="$gate_code_b64" \
  bash /var/www/html/scripts/runner/run-canvas-ai-provider-config-drift-gate-1375.sh \
  2>/dev/null)"; then
  fail_closed
fi
unset status_b64 gate_code_b64

if ! jq -e '
  (keys | sort) == ["items","summary"]
  and (.items | type == "array" and length == 19)
  and (.items | all(
    (keys | sort) == ["classification","config_name","differing_paths"]
    and (.config_name | type == "string")
    and (.differing_paths | type == "array" and length > 0)
    and (.differing_paths | all(type == "string" and length > 0))
    and .classification == "KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN"
  ))
  and .summary == {"total":19,"known":19,"unexpected":0}
' <<< "$evidence" >/dev/null 2>&1; then
  fail_closed
fi

echo 'CANVAS_BROWSER_CONFIG_GATE=PASS_KNOWN_19'
