#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"
: "${TRUSTED_MAIN:?TRUSTED_MAIN is required}"

ARTIFACT_REL='artifacts/configuration-language-mechanical-migration'

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

test -f docs/evidence/configuration-language-mechanical-cohort-713.yml
test -f scripts/runner/configuration-language-mechanical-migration.php
test "$(git rev-parse HEAD)" = "$TRUSTED_MAIN"

ddev exec php -l \
  /var/www/html/scripts/runner/configuration-language-mechanical-migration.php \
  >/dev/null
git diff --check

if ddev drush pm:list --status=enabled --type=module --field=name \
  | grep -Fxq 'config_language_lock'; then
  echo 'Config Language Lock must remain disabled for #713.' >&2
  exit 1
fi

config_status_before="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_before" > "$ARTIFACT_DIR/config-status-before.txt"
grep -Fq 'No differences' <<<"$config_status_before"

ddev drush php:script \
  /var/www/html/scripts/runner/configuration-language-mechanical-migration.php \
  > "$ARTIFACT_DIR/result.json"

result="$ARTIFACT_DIR/result.json"
jq -e '.schema_version == 1' "$result" >/dev/null
jq -e '.status == "PASS"' "$result" >/dev/null
jq -e '.verdict == "THIRTY_NINE_MECHANICAL_CANDIDATES_MIGRATION_ROLLBACK_PROVEN"' "$result" >/dev/null
jq -e '.counts.cohort_expected == 39' "$result" >/dev/null
jq -e '.counts.cohort_classified == 39' "$result" >/dev/null
jq -e '.counts.material_translatable_leaf_count == 0' "$result" >/dev/null
jq -e '.counts.migrated == 39' "$result" >/dev/null
jq -e '.counts.unexpected_mutation_count == 0' "$result" >/dev/null
jq -e '.counts.language_override_delta_count == 0' "$result" >/dev/null
jq -e '.counts.rollback_restored == 39' "$result" >/dev/null
jq -e '.counts.problem_count == 0' "$result" >/dev/null
jq -e '.problems == []' "$result" >/dev/null
jq -e '.constraints.active_config_only == true' "$result" >/dev/null
jq -e '.constraints.repository_config_mutation_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.canonical_base_langcode_migration_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.bulk_langcode_replacement_allowed == false' "$result" >/dev/null
jq -e '.constraints.config_language_lock_activation_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.config_export_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.production_mutation_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.editorial_semantic_cohort_in_scope == false' "$result" >/dev/null

config_status_after="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_after" > "$ARTIFACT_DIR/config-status-after.txt"
grep -Fq 'No differences' <<<"$config_status_after"
diff -u \
  "$ARTIFACT_DIR/config-status-before.txt" \
  "$ARTIFACT_DIR/config-status-after.txt"

if ddev drush pm:list --status=enabled --type=module --field=name \
  | grep -Fxq 'config_language_lock'; then
  echo '#713 unexpectedly enabled Config Language Lock.' >&2
  exit 1
fi

git diff --exit-code -- config/sync
git diff --check
