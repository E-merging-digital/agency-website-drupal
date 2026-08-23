#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"
: "${TRUSTED_MAIN:?TRUSTED_MAIN is required}"

ARTIFACT_REL='artifacts/configuration-language-exception-coverage'

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

test -f config/sync/language/en/core.entity_form_display.node.page.default.yml
test -f config/sync/language/en/field.storage.node.ai_automator_status.yml
test -f scripts/runner/configuration-language-exception-coverage.php
test "$(git rev-parse HEAD)" = "$TRUSTED_MAIN"

ddev exec php -l \
  /var/www/html/scripts/runner/configuration-language-exception-coverage.php \
  >/dev/null
git diff --check

if ddev drush pm:list --status=enabled --type=module --field=name \
  | grep -Fxq 'config_language_lock'; then
  echo 'Config Language Lock must remain disabled for exception coverage.' >&2
  exit 1
fi

config_status_before="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_before" > "$ARTIFACT_DIR/config-status-before.txt"
grep -Fq 'No differences' <<<"$config_status_before"

ddev drush php:script \
  /var/www/html/scripts/runner/configuration-language-exception-coverage.php \
  > "$ARTIFACT_DIR/result.json"

result="$ARTIFACT_DIR/result.json"
jq -e '.schema_version == 1' "$result" >/dev/null
jq -e '.status == "PASS"' "$result" >/dev/null
jq -e '.verdict == "TWO_EXCEPTIONS_EXPLICITLY_COVERED"' "$result" >/dev/null
jq -e '.counts.expected_configurations == 2' "$result" >/dev/null
jq -e '.counts.classified == 2' "$result" >/dev/null
jq -e '.counts.material_translatable_leaf_count == 6' "$result" >/dev/null
jq -e '.counts.explicit_en_coverage_count == 6' "$result" >/dev/null
jq -e '.counts.source_equal_count == 4' "$result" >/dev/null
jq -e '.counts.problem_count == 0' "$result" >/dev/null
jq -e '.problems == []' "$result" >/dev/null
jq -e '[.items[].name] == ["core.entity_form_display.node.page.default", "field.storage.node.ai_automator_status"]' \
  "$result" >/dev/null
jq -e '[.items[].classification] | all(. == "en_override_complete_and_minimal")' \
  "$result" >/dev/null
jq -e '.constraints.read_only == true' "$result" >/dev/null
jq -e '.constraints.base_langcode_migration_allowed_by_this_proof == false' \
  "$result" >/dev/null
jq -e '.constraints.bulk_langcode_replacement_allowed == false' "$result" >/dev/null
jq -e '.constraints.config_language_lock_activation_allowed_by_this_proof == false' \
  "$result" >/dev/null
jq -e '.constraints.config_export_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.production_mutation_allowed_by_this_proof == false' \
  "$result" >/dev/null

config_status_after="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_after" > "$ARTIFACT_DIR/config-status-after.txt"
grep -Fq 'No differences' <<<"$config_status_after"
diff -u \
  "$ARTIFACT_DIR/config-status-before.txt" \
  "$ARTIFACT_DIR/config-status-after.txt"

if ddev drush pm:list --status=enabled --type=module --field=name \
  | grep -Fxq 'config_language_lock'; then
  echo 'Exception coverage unexpectedly enabled Config Language Lock.' >&2
  exit 1
fi

git diff --exit-code -- config/sync
git diff --check
