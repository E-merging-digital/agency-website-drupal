#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"
: "${TRUSTED_MAIN:?TRUSTED_MAIN is required}"

ARTIFACT_REL='artifacts/configuration-language-translated-promotion'

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

test -f docs/evidence/configuration-language-translated-cohort-718.yml
test -f scripts/runner/configuration-language-translated-promotion.php
test "$(git rev-parse HEAD)" = "$TRUSTED_MAIN"

ddev exec php -l \
  /var/www/html/scripts/runner/configuration-language-translated-promotion.php \
  >/dev/null
git diff --check

if ddev drush pm:list --status=enabled --type=module --field=name \
  | grep -Fxq 'config_language_lock'; then
  echo 'Config Language Lock must remain disabled for #718.' >&2
  exit 1
fi

config_status_before="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_before" > "$ARTIFACT_DIR/config-status-before.txt"
grep -Fq 'No differences' <<<"$config_status_before"

ddev drush php:script \
  /var/www/html/scripts/runner/configuration-language-translated-promotion.php \
  > "$ARTIFACT_DIR/result.json"

result="$ARTIFACT_DIR/result.json"
jq -e '.schema_version == 1' "$result" >/dev/null
jq -e '.status == "PASS"' "$result" >/dev/null
jq -e '.verdict == "ONE_HUNDRED_SEVENTY_THREE_TRANSLATED_CANDIDATES_PROMOTION_ROLLBACK_PROVEN"' "$result" >/dev/null
jq -e '.cohort.expected_count == 173' "$result" >/dev/null
jq -e '.cohort.actual_count == 173' "$result" >/dev/null
jq -e '.cohort.expected_names_sha256 == "3908bb3b785091d996e969f67af5d0060b3548d2ec732fb055c748085c0d6547"' "$result" >/dev/null
jq -e '.cohort.actual_names_sha256 == .cohort.expected_names_sha256' "$result" >/dev/null
jq -e '.counts.cohort_classified == 173' "$result" >/dev/null
jq -e '.counts.material_translatable_leaf_count == .counts.explicit_en_coverage_count' "$result" >/dev/null
jq -e '.counts.promoted == 173' "$result" >/dev/null
jq -e '.counts.en_overrides_removed == 173' "$result" >/dev/null
jq -e '.counts.unexpected_default_mutation_count == 0' "$result" >/dev/null
jq -e '.counts.rollback_restored == 173' "$result" >/dev/null
jq -e '.counts.problem_count == 0' "$result" >/dev/null
jq -e '.problems == []' "$result" >/dev/null
jq -e '.baseline_fingerprint == .final_fingerprint' "$result" >/dev/null
jq -e '[.items[] | select(.classification != "complete_for_promotion")] | length == 0' "$result" >/dev/null
jq -e '[.items[] | select(.promoted != true)] | length == 0' "$result" >/dev/null
jq -e '[.items[] | select(.origin == "issue_711_exception")] | length == 2' "$result" >/dev/null
jq -e '.constraints.active_config_only == true' "$result" >/dev/null
jq -e '.constraints.repository_config_mutation_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.canonical_translated_migration_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.bulk_langcode_replacement_allowed == false' "$result" >/dev/null
jq -e '.constraints.config_language_lock_activation_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.config_export_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.production_mutation_allowed_by_this_proof == false' "$result" >/dev/null
jq -e '.constraints.editorial_semantic_without_en_override_in_scope == false' "$result" >/dev/null

config_status_after="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_after" > "$ARTIFACT_DIR/config-status-after.txt"
grep -Fq 'No differences' <<<"$config_status_after"
diff -u \
  "$ARTIFACT_DIR/config-status-before.txt" \
  "$ARTIFACT_DIR/config-status-after.txt"

if ddev drush pm:list --status=enabled --type=module --field=name \
  | grep -Fxq 'config_language_lock'; then
  echo '#718 unexpectedly enabled Config Language Lock.' >&2
  exit 1
fi

git diff --exit-code -- config/sync
git diff --check
