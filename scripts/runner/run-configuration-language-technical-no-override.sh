#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"
: "${TRUSTED_MAIN:?TRUSTED_MAIN is required}"

ARTIFACT_REL='artifacts/configuration-language-technical-no-override'

TARGET_DIR="$(cd "$TARGET_DIR" && pwd)"
mkdir -p "$ARTIFACT_DIR"
ARTIFACT_DIR="$(cd "$ARTIFACT_DIR" && pwd)"

if [[ "$ARTIFACT_DIR" != "$TARGET_DIR/$ARTIFACT_REL" ]]; then
  echo "ARTIFACT_DIR must be $TARGET_DIR/$ARTIFACT_REL" >&2
  exit 1
fi

cd "$TARGET_DIR"

command -v ddev >/dev/null
command -v git >/dev/null
command -v jq >/dev/null

test -f docs/evidence/configuration-language-baseline-609.yml
test -f scripts/runner/configuration-language-technical-no-override.php
test "$(git rev-parse HEAD)" = "$TRUSTED_MAIN"

ddev exec php -l \
  /var/www/html/scripts/runner/configuration-language-technical-no-override.php \
  >/dev/null
git diff --check

if ddev drush pm:list --status=enabled --type=module --field=name \
  | grep -Fxq 'config_language_lock'; then
  echo 'Config Language Lock must remain disabled for technical classification.' >&2
  exit 1
fi

config_status_before="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_before" > "$ARTIFACT_DIR/config-status-before.txt"
grep -Fq 'No differences' <<<"$config_status_before"

ddev drush php:script \
  /var/www/html/scripts/runner/configuration-language-technical-no-override.php \
  > "$ARTIFACT_DIR/result.json"

result="$ARTIFACT_DIR/result.json"
jq -e '.schema_version == 1' "$result" >/dev/null
jq -e '.status == "PASS"' "$result" >/dev/null
jq -e '.counts.candidate_technical_fr_base_without_en_override == 41' "$result" >/dev/null
jq -e '.counts.classified == 41' "$result" >/dev/null
jq -e '.counts.baseline_problem == 0' "$result" >/dev/null
jq -e '.baseline.expected_by_type.entity_form_display == 11' "$result" >/dev/null
jq -e '.baseline.expected_by_type.entity_view_display == 10' "$result" >/dev/null
jq -e '.baseline.expected_by_type.field_storage_config == 6' "$result" >/dev/null
jq -e '.baseline.expected_by_type.language_content_settings == 14' "$result" >/dev/null
jq -e '.by_type.entity_form_display.candidate == 11' "$result" >/dev/null
jq -e '.by_type.entity_view_display.candidate == 10' "$result" >/dev/null
jq -e '.by_type.field_storage_config.candidate == 6' "$result" >/dev/null
jq -e '.by_type.language_content_settings.candidate == 14' "$result" >/dev/null
jq -e '.constraints.read_only == true' "$result" >/dev/null
jq -e '.constraints.configuration_migration_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.config_language_lock_activation_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.editorial_semantic_cohort_in_scope == false' "$result" >/dev/null

config_status_after="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_after" > "$ARTIFACT_DIR/config-status-after.txt"
grep -Fq 'No differences' <<<"$config_status_after"
diff -u \
  "$ARTIFACT_DIR/config-status-before.txt" \
  "$ARTIFACT_DIR/config-status-after.txt"

if ddev drush pm:list --status=enabled --type=module --field=name \
  | grep -Fxq 'config_language_lock'; then
  echo 'Technical classification unexpectedly enabled Config Language Lock.' >&2
  exit 1
fi

git diff --exit-code -- config/sync
git diff --check
