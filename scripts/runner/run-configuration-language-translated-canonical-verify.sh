#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"
: "${TRUSTED_MAIN:?TRUSTED_MAIN is required}"

ARTIFACT_REL='artifacts/configuration-language-translated-canonical'
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
test -f docs/evidence/configuration-language-translated-canonical-cohort-720.yml
test -f docs/evidence/configuration-language-mechanical-cohort-713.yml
test -f scripts/runner/configuration-language-translated-canonical-verify.php

ddev exec php -l /var/www/html/scripts/runner/configuration-language-translated-canonical-verify.php >/dev/null
git diff --check

if ddev drush pm:list --status=enabled --type=module --field=name | grep -Fxq 'config_language_lock'; then
  echo 'Config Language Lock must remain disabled for #720.' >&2
  exit 1
fi

config_status_before="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_before" > "$ARTIFACT_DIR/config-status-before.txt"
grep -Fq 'No differences' <<<"$config_status_before"

ddev drush php:script /var/www/html/scripts/runner/configuration-language-translated-canonical-verify.php \
  > "$ARTIFACT_DIR/result.json"

result="$ARTIFACT_DIR/result.json"
jq -e '.schema_version == 1' "$result" >/dev/null
jq -e '.status == "PASS"' "$result" >/dev/null
jq -e '.verdict == "ONE_HUNDRED_SEVENTY_THREE_TRANSLATED_CANONICAL_PROMOTION_VERIFIED"' "$result" >/dev/null
jq -e '.counts.expected == 173' "$result" >/dev/null
jq -e '.counts.verified == 173' "$result" >/dev/null
jq -e '.counts.fr_overrides_required == 7' "$result" >/dev/null
jq -e '.counts.mechanical_715_verified_en == 39' "$result" >/dev/null
jq -e '.counts.remaining_fr_review_required == 140' "$result" >/dev/null
jq -e '.counts.preserved_fr_overrides_outside_cohort == 2' "$result" >/dev/null
jq -e '.counts.preserved_en_overrides_outside_cohort == 1' "$result" >/dev/null
jq -e '.counts.problem_count == 0' "$result" >/dev/null
jq -e '.cohort.expected_names_sha256 == "3908bb3b785091d996e969f67af5d0060b3548d2ec732fb055c748085c0d6547"' "$result" >/dev/null
jq -e '.cohort.actual_names_sha256 == .cohort.expected_names_sha256' "$result" >/dev/null
jq -e '.distribution_by_langcode == {"__none__":59,"en":395,"fr":140,"und":1}' "$result" >/dev/null
jq -e '.problems == []' "$result" >/dev/null
jq -e '.constraints.read_only == true' "$result" >/dev/null

config_status_after="$(ddev drush config:status 2>&1)"
printf '%s\n' "$config_status_after" > "$ARTIFACT_DIR/config-status-after.txt"
grep -Fq 'No differences' <<<"$config_status_after"
diff -u "$ARTIFACT_DIR/config-status-before.txt" "$ARTIFACT_DIR/config-status-after.txt"

if ddev drush pm:list --status=enabled --type=module --field=name | grep -Fxq 'config_language_lock'; then
  echo '#720 unexpectedly enabled Config Language Lock.' >&2
  exit 1
fi

git diff --exit-code -- config/sync
git diff --check
