#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"
: "${TRUSTED_MAIN:?TRUSTED_MAIN is required}"

ARTIFACT_REL='artifacts/configuration-language-audit'

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
command -v sha256sum >/dev/null

test -f docs/configuration-language-policy.yml
test -f scripts/runner/configuration-language-audit.php
test "$(git rev-parse HEAD)" = "$TRUSTED_MAIN"
php -l scripts/runner/configuration-language-audit.php >/dev/null
git diff --check

config_status="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status" > "$ARTIFACT_DIR/config-status.txt"
if ! grep -Fq 'No differences' <<<"$config_status"; then
  jq -n \
    --arg status 'FAIL' \
    --arg verdict 'CONFIGURATION_NOT_CANONICAL' \
    --arg trusted_main "$TRUSTED_MAIN" \
    '{status:$status,verdict:$verdict,trusted_main:$trusted_main}' \
    > "$ARTIFACT_DIR/result.json"
  echo 'Configuration must be converged before language audit.' >&2
  exit 1
fi

ddev drush php:script /var/www/html/scripts/runner/configuration-language-audit.php \
  > "$ARTIFACT_DIR/snapshot.json"

jq -e '.schema_version == 1' "$ARTIFACT_DIR/snapshot.json" >/dev/null
jq -e '.policy.policy_id == "agency-configuration-language-v1"' "$ARTIFACT_DIR/snapshot.json" >/dev/null
jq -e '.policy.status == "migration_required"' "$ARTIFACT_DIR/snapshot.json" >/dev/null
jq -e '.policy.canonical_configuration_language == "en"' "$ARTIFACT_DIR/snapshot.json" >/dev/null
jq -e '.policy.enforce_consistency == false' "$ARTIFACT_DIR/snapshot.json" >/dev/null
jq -e '.repository_active_comparison.missing_from_active | length == 0' "$ARTIFACT_DIR/snapshot.json" >/dev/null
jq -e '.repository_active_comparison.missing_from_repository | length == 0' "$ARTIFACT_DIR/snapshot.json" >/dev/null
jq -e '.repository_active_comparison.langcode_mismatches | length == 0' "$ARTIFACT_DIR/snapshot.json" >/dev/null
jq -e '.migration_watchlist | all(.exists == true)' "$ARTIFACT_DIR/snapshot.json" >/dev/null

snapshot_sha256="$(sha256sum "$ARTIFACT_DIR/snapshot.json" | awk '{print $1}')"
repo_total="$(jq -r '.repository.summary.total' "$ARTIFACT_DIR/snapshot.json")"
active_total="$(jq -r '.active.summary.total' "$ARTIFACT_DIR/snapshot.json")"
repo_langcodes="$(jq -c '.repository.summary.by_langcode' "$ARTIFACT_DIR/snapshot.json")"
active_langcodes="$(jq -c '.active.summary.by_langcode' "$ARTIFACT_DIR/snapshot.json")"
translation_counts="$(jq -c '[.translations[] | {language,count}]' "$ARTIFACT_DIR/snapshot.json")"
mixed="$(jq -r '.observations.mixed_technical_base_languages' "$ARTIFACT_DIR/snapshot.json")"
uniform="$(jq -r '.observations.canonical_language_already_uniform' "$ARTIFACT_DIR/snapshot.json")"

jq -n \
  --arg status 'PASS' \
  --arg verdict 'SNAPSHOT_CAPTURED' \
  --arg trusted_main "$TRUSTED_MAIN" \
  --arg snapshot_sha256 "$snapshot_sha256" \
  --argjson repository_total "$repo_total" \
  --argjson active_total "$active_total" \
  --argjson repository_by_langcode "$repo_langcodes" \
  --argjson active_by_langcode "$active_langcodes" \
  --argjson translations "$translation_counts" \
  --argjson mixed_technical_base_languages "$mixed" \
  --argjson canonical_language_already_uniform "$uniform" \
  '{
    status:$status,
    verdict:$verdict,
    trusted_main:$trusted_main,
    snapshot_sha256:$snapshot_sha256,
    policy_status:"migration_required",
    canonical_configuration_language:"en",
    repository:{total:$repository_total,by_langcode:$repository_by_langcode},
    active:{total:$active_total,by_langcode:$active_by_langcode},
    translations:$translations,
    observations:{
      mixed_technical_base_languages:$mixed_technical_base_languages,
      canonical_language_already_uniform:$canonical_language_already_uniform
    }
  }' > "$ARTIFACT_DIR/result.json"

jq -e '.status == "PASS" and .verdict == "SNAPSHOT_CAPTURED"' "$ARTIFACT_DIR/result.json" >/dev/null
