#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"
: "${TRUSTED_MAIN:?TRUSTED_MAIN is required}"

ARTIFACT_REL='artifacts/configuration-language-mechanical-canonical-verify'
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

test "$(git rev-parse HEAD)" = "$TRUSTED_MAIN"
test -f scripts/runner/configuration-language-mechanical-canonical-verify.php
test -f scripts/runner/configuration-language-exception-coverage.php
test -f docs/evidence/configuration-language-mechanical-cohort-713.yml

ddev exec php -l /var/www/html/scripts/runner/configuration-language-mechanical-canonical-verify.php >/dev/null

if ddev drush pm:list --status=enabled --type=module --field=name | grep -Fxq 'config_language_lock'; then
  echo 'Config Language Lock must remain disabled for canonical migration verification.' >&2
  exit 1
fi

config_status_before="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_before" > "$ARTIFACT_DIR/config-status-before.txt"
grep -Fq 'No differences' <<<"$config_status_before"

ddev drush php:script \
  /var/www/html/scripts/runner/configuration-language-mechanical-canonical-verify.php \
  > "$ARTIFACT_DIR/result.json"

ddev drush php:script \
  /var/www/html/scripts/runner/configuration-language-exception-coverage.php \
  > "$ARTIFACT_DIR/exceptions-result.json"

result="$ARTIFACT_DIR/result.json"
exceptions="$ARTIFACT_DIR/exceptions-result.json"

jq -e '.schema_version == 1' "$result" >/dev/null
jq -e '.status == "PASS"' "$result" >/dev/null
jq -e '.verdict == "THIRTY_NINE_MECHANICAL_CANONICAL_MIGRATION_VERIFIED"' "$result" >/dev/null
jq -e '.counts.cohort_expected == 39' "$result" >/dev/null
jq -e '.counts.cohort_verified_en == 39' "$result" >/dev/null
jq -e '.counts.material_translatable_leaf_count == 0' "$result" >/dev/null
jq -e '.counts.excluded_exceptions_expected == 2' "$result" >/dev/null
jq -e '.counts.excluded_exceptions_preserved_fr == 2' "$result" >/dev/null
jq -e '.counts.problem_count == 0' "$result" >/dev/null
jq -e '.problems == []' "$result" >/dev/null
jq -e '[.items[] | select(.repository_langcode != "en" or .active_langcode != "en" or .material_translatable_leaf_count != 0 or .classification != "canonical_en_no_material_translatable_source")] == []' "$result" >/dev/null
jq -e '[.excluded_exceptions[] | select(.base_langcode != "fr" or .en_override_present != true)] == []' "$result" >/dev/null
jq -e '.constraints.read_only == true' "$result" >/dev/null
jq -e '.constraints.config_language_lock_activation_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.config_export_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.production_mutation_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.editorial_semantic_cohort_in_scope == false' "$result" >/dev/null

jq -e '.status == "PASS"' "$exceptions" >/dev/null
jq -e '.verdict == "TWO_EXCEPTIONS_EXPLICITLY_COVERED"' "$exceptions" >/dev/null
jq -e '.counts.expected_configurations == 2' "$exceptions" >/dev/null
jq -e '.counts.classified == 2' "$exceptions" >/dev/null
jq -e '.counts.material_translatable_leaf_count == 6' "$exceptions" >/dev/null
jq -e '.counts.explicit_en_coverage_count == 6' "$exceptions" >/dev/null
jq -e '.counts.problem_count == 0' "$exceptions" >/dev/null
jq -e '.problems == []' "$exceptions" >/dev/null

config_status_after="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_after" > "$ARTIFACT_DIR/config-status-after.txt"
grep -Fq 'No differences' <<<"$config_status_after"
diff -u "$ARTIFACT_DIR/config-status-before.txt" "$ARTIFACT_DIR/config-status-after.txt"

if ddev drush pm:list --status=enabled --type=module --field=name | grep -Fxq 'config_language_lock'; then
  echo '#715 verification unexpectedly enabled Config Language Lock.' >&2
  exit 1
fi

git diff --exit-code -- config/sync
git diff --check
