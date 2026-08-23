#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"
: "${TRUSTED_MAIN:?TRUSTED_MAIN is required}"

ARTIFACT_REL='artifacts/configuration-language-migration-dry-run'
AUDIT_REL='artifacts/configuration-language-audit'

TARGET_DIR="$(cd "$TARGET_DIR" && pwd)"
mkdir -p "$ARTIFACT_DIR"
ARTIFACT_DIR="$(cd "$ARTIFACT_DIR" && pwd)"
AUDIT_DIR="$TARGET_DIR/$AUDIT_REL"

if [[ "$ARTIFACT_DIR" != "$TARGET_DIR/$ARTIFACT_REL" ]]; then
  echo "ARTIFACT_DIR must be $TARGET_DIR/$ARTIFACT_REL" >&2
  exit 1
fi

cd "$TARGET_DIR"

command -v ddev >/dev/null
command -v git >/dev/null
command -v jq >/dev/null
command -v sha256sum >/dev/null

test -f docs/configuration-language-policy.yml
test -f docs/evidence/configuration-language-baseline-609.yml
test -f scripts/runner/run-configuration-language-audit.sh
test -f scripts/runner/configuration-language-audit.php
test -f scripts/runner/configuration-language-migration-dry-run.php
test "$(git rev-parse HEAD)" = "$TRUSTED_MAIN"

ddev exec php -l /var/www/html/scripts/runner/configuration-language-audit.php >/dev/null
ddev exec php -l /var/www/html/scripts/runner/configuration-language-migration-dry-run.php >/dev/null
git diff --check

mkdir -p "$AUDIT_DIR"
TARGET_DIR="$TARGET_DIR" \
ARTIFACT_DIR="$AUDIT_DIR" \
TRUSTED_MAIN="$TRUSTED_MAIN" \
  bash scripts/runner/run-configuration-language-audit.sh

jq -e '.status == "PASS" and .verdict == "SNAPSHOT_CAPTURED"' "$AUDIT_DIR/result.json" >/dev/null
cp "$AUDIT_DIR/snapshot.json" "$ARTIFACT_DIR/baseline-snapshot.json"
cp "$AUDIT_DIR/result.json" "$ARTIFACT_DIR/audit-result.json"

ddev exec php /var/www/html/scripts/runner/configuration-language-migration-dry-run.php \
  > "$ARTIFACT_DIR/classification.json"
cp "$ARTIFACT_DIR/classification.json" "$ARTIFACT_DIR/result.json"

jq -e '.schema_version == 1' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.status == "PASS"' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.baseline.match == true' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.counts.fr_base_with_en_override == 171' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.counts.fr_base_without_en_override == 181' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.counts.classified_fr_without_en_override == 181' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.counts.unknown == 0' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.categories.locked_or_special_preserve.count >= 3' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.special_invariants.pass == true' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.constraints.bulk_langcode_replacement_allowed == false' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.constraints.config_language_lock_activation_allowed_by_this_proof == false' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.constraints.config_export_allowed_by_this_proof == false' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.constraints.production_mutation_allowed_by_this_proof == false' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.focus.canvas.count == 43' "$ARTIFACT_DIR/result.json" >/dev/null
jq -e '.focus.language_content_settings.count == 14' "$ARTIFACT_DIR/result.json" >/dev/null

config_status="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status" > "$ARTIFACT_DIR/config-status-after.txt"
grep -Fq 'No differences' <<<"$config_status"

git diff --exit-code -- config/sync
git diff --check
