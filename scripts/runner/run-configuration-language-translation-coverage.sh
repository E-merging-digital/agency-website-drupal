#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"
: "${TRUSTED_MAIN:?TRUSTED_MAIN is required}"

ARTIFACT_REL='artifacts/configuration-language-translation-coverage'

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
test -f scripts/runner/configuration-language-translation-coverage.php
test "$(git rev-parse HEAD)" = "$TRUSTED_MAIN"

ddev exec php -l \
  /var/www/html/scripts/runner/configuration-language-translation-coverage.php \
  >/dev/null
git diff --check

if ddev drush pm:list --status=enabled --type=module --field=name \
  | grep -Fxq 'config_language_lock'; then
  echo 'Config Language Lock must remain disabled for coverage classification.' >&2
  exit 1
fi

config_status_before="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_before" > "$ARTIFACT_DIR/config-status-before.txt"
grep -Fq 'No differences' <<<"$config_status_before"

ddev drush php:script \
  /var/www/html/scripts/runner/configuration-language-translation-coverage.php \
  > "$ARTIFACT_DIR/coverage.json"
cp "$ARTIFACT_DIR/coverage.json" "$ARTIFACT_DIR/result.json"

result="$ARTIFACT_DIR/result.json"
jq -e '.schema_version == 1' "$result" >/dev/null
jq -e '.status == "PASS"' "$result" >/dev/null
jq -e '.verdict == "COVERAGE_CLASSIFIED"' "$result" >/dev/null
jq -e '.baseline.expected_fr_base_with_en_override == 171' "$result" >/dev/null
jq -e '.counts.candidate_fr_base_with_en_override == 171' "$result" >/dev/null
jq -e '.counts.classified == 171' "$result" >/dev/null
jq -e '.counts.baseline_problem == 0' "$result" >/dev/null
jq -e '.counts.en_override_complete_for_material_translatable_source == 171' \
  "$result" >/dev/null
jq -e '.counts.en_override_partial_review_required == 0' "$result" >/dev/null
jq -e '.counts.schema_unresolved_review_required == 0' "$result" >/dev/null
jq -e '.focus["webform.webform.contact"].classification == "en_override_complete_for_material_translatable_source"' \
  "$result" >/dev/null
jq -e '.constraints.read_only == true' "$result" >/dev/null
jq -e '.constraints.bulk_langcode_replacement_allowed == false' "$result" >/dev/null
jq -e '.constraints.configuration_migration_allowed_by_this_proof == false' \
  "$result" >/dev/null
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
  echo 'Coverage classification unexpectedly enabled Config Language Lock.' >&2
  exit 1
fi

git diff --exit-code -- config/sync
git diff --check
