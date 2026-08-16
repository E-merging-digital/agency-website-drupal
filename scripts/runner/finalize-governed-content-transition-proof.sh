#!/usr/bin/env bash
set -euo pipefail

: "${BASE_SHA:?BASE_SHA is required}"
: "${RELEASE_SHA:?RELEASE_SHA is required}"
: "${TARGET_SHA:?TARGET_SHA is required}"
: "${PR_NUMBER:?PR_NUMBER is required}"
: "${REQUEST_ID:?REQUEST_ID is required}"
: "${PROOF_PROFILE:?PROOF_PROFILE is required}"
: "${PROOF_PROFILE_HELPER:?PROOF_PROFILE_HELPER is required}"

# The helper is copied from trusted main by the workflow before any target SHA is
# checked out. Do not source profile data from the PR under proof.
# shellcheck source=/dev/null
source "$PROOF_PROFILE_HELPER"

ARTIFACT_ROOT="artifacts/governed-content-transition"
SNAPSHOT_ROOT="$ARTIFACT_ROOT/snapshots"
LOG_ROOT="$ARTIFACT_ROOT/logs"
RESULT_PATH="$ARTIFACT_ROOT/final-result.json"
EDITORIAL_MARKER="$PROOF_EDITORIAL_MARKER"
CATALOG_PATH="web/modules/custom/emerging_digital_content/content_sync/catalog.yml"
SQL_IDS="$PROOF_SQL_IDS"
PILOT_IDS=("${PROOF_CONTENT_IDS[@]}")
PILOT_PAYLOADS=("${PROOF_PAYLOADS[@]}")
EXPECTED_ALIASES=("${PROOF_PUBLIC_PATHS[@]}")
EXPECTED_MAPPING_COUNT="$PROOF_MAPPING_COUNT"
EDITORIAL_CONTENT_ID="$PROOF_EDITORIAL_CONTENT_ID"
EDITORIAL_PATH="$PROOF_EDITORIAL_PATH"

proof_status="FAIL"
proof_phase="finalize-bootstrap"

write_result() {
  local exit_code="$1"
  local pilot_ids_json
  pilot_ids_json="$(printf '%s\n' "${PILOT_IDS[@]}" | jq -R -s 'split("\n")[:-1]')"

  jq -n \
    --arg result "$proof_status" \
    --arg phase "$proof_phase" \
    --arg request_id "$REQUEST_ID" \
    --arg pr_number "$PR_NUMBER" \
    --arg base_sha "$BASE_SHA" \
    --arg release_sha "$RELEASE_SHA" \
    --arg target_sha "$TARGET_SHA" \
    --arg proof_profile "$PROOF_PROFILE" \
    --argjson pilot_content_ids "$pilot_ids_json" \
    --argjson exit_code "$exit_code" \
    '{
      result: $result,
      phase: $phase,
      request_id: $request_id,
      pr_number: $pr_number,
      base_sha: $base_sha,
      release_sha: $release_sha,
      target_sha: $target_sha,
      proof_profile: $proof_profile,
      pilot_content_ids: $pilot_content_ids,
      exit_code: $exit_code
    }' > "$RESULT_PATH"
}

on_exit() {
  local exit_code=$?
  if [[ "$exit_code" -eq 0 ]]; then
    proof_status="PASS"
    proof_phase="complete"
  fi
  write_result "$exit_code"
}
trap on_exit EXIT

snapshot_mappings() {
  local destination="$1"
  ddev mysql -N -B -e "
    SELECT content_id, entity_id, entity_uuid, status, catalog_hash,
           COALESCE(last_synced, 0), last_action
      FROM emerging_digital_content_sync_mapping
     WHERE content_id IN (${SQL_IDS})
     ORDER BY content_id;
  " > "$destination"
}

snapshot_nodes() {
  local destination="$1"
  ddev mysql -N -B -e "
    SELECT m.content_id, nfd.nid, n.uuid, nfd.langcode, nfd.status,
           nfd.title, COALESCE(pa.alias, '')
      FROM emerging_digital_content_sync_mapping m
      JOIN node n ON n.nid = m.entity_id
      JOIN node_field_data nfd ON nfd.nid = n.nid
      LEFT JOIN path_alias pa
        ON pa.path = CONCAT('/node/', n.nid)
       AND pa.langcode = nfd.langcode
       AND pa.status = 1
     WHERE m.content_id IN (${SQL_IDS})
     ORDER BY m.content_id, nfd.langcode, pa.alias;
  " > "$destination"
}

mapping_identity() {
  cut -f1,2,3,5 "$1"
}

assert_mapping_status() {
  local snapshot="$1"
  local expected="$2"
  local count

  count="$(wc -l < "$snapshot" | tr -d ' ')"
  [[ "$count" == "$EXPECTED_MAPPING_COUNT" ]] || {
    echo "Expected exactly ${EXPECTED_MAPPING_COUNT} proof mappings, found ${count}." >&2
    return 1
  }

  awk -F '\t' -v expected="$expected" '
    $4 != expected {
      printf "Unexpected mapping status for %s: %s (expected %s)\n", $1, $4, expected > "/dev/stderr"
      bad = 1
    }
    END { exit bad }
  ' "$snapshot"
}

assert_aliases_and_publication() {
  local snapshot="$1"
  local alias

  for alias in "${EXPECTED_ALIASES[@]}"; do
    alias="${alias#/fr}"
    alias="${alias#/en}"
    grep -Fq $'\t'"${alias}" "$snapshot" || {
      echo "Expected alias missing from runtime snapshot: ${alias}" >&2
      return 1
    }
  done

  awk -F '\t' '
    $5 != "1" {
      printf "Proof node %s translation %s is not published.\n", $1, $4 > "/dev/stderr"
      bad = 1
    }
    END { exit bad }
  ' "$snapshot"
}

release_pilot() {
  local content_id
  for content_id in "${PILOT_IDS[@]}"; do
    ddev drush emerging:content-sync:release "$content_id" --dry-run \
      | tee "$LOG_ROOT/final-release-${content_id}-dry-run.txt"
    ddev drush emerging:content-sync:release "$content_id" --apply \
      | tee "$LOG_ROOT/final-release-${content_id}-apply.txt"
  done
}

readmit_pilot() {
  local content_id
  for content_id in "${PILOT_IDS[@]}"; do
    ddev drush emerging:content-sync:readmit "$content_id" --dry-run \
      | tee "$LOG_ROOT/final-readmit-${content_id}-dry-run.txt"
    ddev drush emerging:content-sync:readmit "$content_id" --apply \
      | tee "$LOG_ROOT/final-readmit-${content_id}-apply.txt"
    ddev drush emerging:content-sync "$content_id" \
      | tee "$LOG_ROOT/final-readmit-${content_id}-resync.txt"
  done
  ddev drush cr
}

verify_config_clean() {
  local label="$1"
  local status
  status="$(ddev drush config:status 2>&1)"
  printf '%s\n' "$status" | tee "$LOG_ROOT/final-config-status-${label}.txt"
  grep -Fq 'No differences' <<<"$status" || {
    echo "Configuration drift detected during ${label}." >&2
    return 1
  }
}

apply_editorial_edit() {
  case "$PROOF_PROFILE" in
    case-studies-440)
      ddev drush php:eval '
        $nid = \Drupal::database()
          ->select("emerging_digital_content_sync_mapping", "m")
          ->fields("m", ["entity_id"])
          ->condition("content_id", "cas-client-migration-drupal-11")
          ->execute()
          ->fetchField();
        if (!$nid) {
          throw new \RuntimeException("Pilot mapping not found.");
        }
        $node = \Drupal\node\Entity\Node::load((int) $nid);
        if (!$node) {
          throw new \RuntimeException("Pilot node not found.");
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage("Governed Content exact-head proof #440");
        $translation = $node->hasTranslation("fr") ? $node->getTranslation("fr") : $node;
        if (!str_contains((string) $translation->label(), "[proof-440-editorial-survives]")) {
          $translation->setTitle($translation->label() . " [proof-440-editorial-survives]");
        }
        $node->save();
      '
      ;;

    ai-features-441)
      ddev drush php:eval '
        $nid = \Drupal::database()
          ->select("emerging_digital_content_sync_mapping", "m")
          ->fields("m", ["entity_id"])
          ->condition("content_id", "ai-redaction-assistee")
          ->execute()
          ->fetchField();
        if (!$nid) {
          throw new \RuntimeException("AI feature mapping not found.");
        }
        $node = \Drupal\node\Entity\Node::load((int) $nid);
        if (!$node) {
          throw new \RuntimeException("AI feature node not found.");
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage("Governed Content exact-head proof #441 ai_feature");
        $translation = $node->hasTranslation("fr") ? $node->getTranslation("fr") : $node;
        if (!str_contains((string) $translation->label(), "[proof-441-ai-features-editorial-survives]")) {
          $translation->setTitle($translation->label() . " [proof-441-ai-features-editorial-survives]");
        }
        $node->save();
      '
      ;;

    services-drupal-441)
      ddev drush php:eval '
        $nid = \Drupal::database()
          ->select("emerging_digital_content_sync_mapping", "m")
          ->fields("m", ["entity_id"])
          ->condition("content_id", "audit-drupal")
          ->execute()
          ->fetchField();
        if (!$nid) {
          throw new \RuntimeException("Drupal service mapping not found.");
        }
        $node = \Drupal\node\Entity\Node::load((int) $nid);
        if (!$node) {
          throw new \RuntimeException("Drupal service node not found.");
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage("Governed Content exact-head proof #441 services Drupal");
        $translation = $node->hasTranslation("fr") ? $node->getTranslation("fr") : $node;
        if (!str_contains((string) $translation->label(), "[proof-441-services-drupal-editorial-survives]")) {
          $translation->setTitle($translation->label() . " [proof-441-services-drupal-editorial-survives]");
        }
        $node->save();
      '
      ;;
  esac
}

assert_editorial_marker() {
  ddev mysql -N -B -e "
    SELECT nfd.title
      FROM emerging_digital_content_sync_mapping m
      JOIN node_field_data nfd
        ON nfd.nid = m.entity_id
       AND nfd.langcode = 'fr'
     WHERE m.content_id = '${EDITORIAL_CONTENT_ID}';
  " | grep -Fq "$EDITORIAL_MARKER"
}

detect_ddev_url() {
  ddev describe -j | python3 -c '
import json, sys

def find(value):
    if isinstance(value, str):
        return value if value.startswith(("https://", "http://")) else None
    if isinstance(value, list):
        found = [find(item) for item in value]
        found = [item for item in found if item]
        return next((item for item in found if item.startswith("https://")), found[0] if found else None)
    if isinstance(value, dict):
        for key, entry in value.items():
            if key.lower().replace("-", "_") in {"primary_url", "primaryurl"}:
                if isinstance(entry, str) and entry.startswith(("https://", "http://")):
                    return entry
        for entry in value.values():
            result = find(entry)
            if result:
                return result
    return None

url = find(json.load(sys.stdin))
if not url:
    raise SystemExit("No DDEV URL found")
print(url.rstrip("/"))
'
}

browser_pages_json() {
  printf '%s\n' "${EXPECTED_ALIASES[@]}" \
    | jq -R -s --arg editorial "$EDITORIAL_PATH" \
      'split("\n")[:-1] | map([., . == $editorial])'
}

proof_phase="replay-release-candidate"
git reset --hard "$RELEASE_SHA"
git clean -fd -- web/modules/custom/emerging_digital_content/content_sync
ddev drush cr
release_pilot
snapshot_mappings "$SNAPSHOT_ROOT/final-prerelease-mappings.tsv"
assert_mapping_status "$SNAPSHOT_ROOT/final-prerelease-mappings.tsv" "released"
diff -u \
  <(mapping_identity "$SNAPSHOT_ROOT/base-mappings.tsv") \
  <(mapping_identity "$SNAPSHOT_ROOT/final-prerelease-mappings.tsv") \
  | tee "$LOG_ROOT/final-prerelease-identity.diff"

proof_phase="exact-head-deploy"
git checkout --detach "$TARGET_SHA"
git diff --check
ddev composer install --no-interaction --no-progress --prefer-dist
ddev drush updb -y
ddev drush cim -y
ddev drush cr
verify_config_clean "before-sync"
ddev drush emerging:content-sync:validate \
  | tee "$LOG_ROOT/final-content-sync-validate.txt"
ddev drush emerging:content-sync --all --dry-run \
  | tee "$LOG_ROOT/final-content-sync-dry-run.txt"
ddev drush emerging:content-sync --all \
  | tee "$LOG_ROOT/final-content-sync-apply.txt"
ddev drush cr
verify_config_clean "after-sync"

snapshot_mappings "$SNAPSHOT_ROOT/final-head-mappings.tsv"
snapshot_nodes "$SNAPSHOT_ROOT/final-head-nodes.tsv"
assert_mapping_status "$SNAPSHOT_ROOT/final-head-mappings.tsv" "released"
assert_aliases_and_publication "$SNAPSHOT_ROOT/final-head-nodes.tsv"
diff -u \
  <(mapping_identity "$SNAPSHOT_ROOT/base-mappings.tsv") \
  <(mapping_identity "$SNAPSHOT_ROOT/final-head-mappings.tsv") \
  | tee "$LOG_ROOT/final-head-identity.diff"
diff -u "$SNAPSHOT_ROOT/base-nodes.tsv" "$SNAPSHOT_ROOT/final-head-nodes.tsv" \
  | tee "$LOG_ROOT/final-head-node-state.diff"

proof_phase="exact-head-editorial-persistence"
apply_editorial_edit
assert_editorial_marker
ddev drush emerging:content-sync --all \
  | tee "$LOG_ROOT/final-content-sync-after-editorial-edit.txt"
ddev drush cr
assert_editorial_marker

proof_phase="exact-head-browser"
base_url="$(detect_ddev_url)"
pages_json="$(browser_pages_json)"
PLAYWRIGHT_BASE_URL="$base_url" \
PROOF_EDITORIAL_MARKER="$EDITORIAL_MARKER" \
PROOF_PAGES_JSON="$pages_json" \
  npx playwright test tests/browser/governed-content-transition-proof.spec.mjs \
    --output="$ARTIFACT_ROOT/final-playwright-test-results" \
    | tee "$LOG_ROOT/final-playwright.txt"

proof_phase="final-rollback"
git checkout "$BASE_SHA" -- "$CATALOG_PATH" "${PILOT_PAYLOADS[@]}"
readmit_pilot
snapshot_mappings "$SNAPSHOT_ROOT/final-rollback-mappings.tsv"
snapshot_nodes "$SNAPSHOT_ROOT/final-rollback-nodes.tsv"
assert_mapping_status "$SNAPSHOT_ROOT/final-rollback-mappings.tsv" "active"
assert_aliases_and_publication "$SNAPSHOT_ROOT/final-rollback-nodes.tsv"
diff -u \
  <(mapping_identity "$SNAPSHOT_ROOT/base-mappings.tsv") \
  <(mapping_identity "$SNAPSHOT_ROOT/final-rollback-mappings.tsv") \
  | tee "$LOG_ROOT/final-rollback-identity.diff"
diff -u "$SNAPSHOT_ROOT/base-nodes.tsv" "$SNAPSHOT_ROOT/final-rollback-nodes.tsv" \
  | tee "$LOG_ROOT/final-rollback-node-state.diff"

proof_status="PASS"
proof_phase="complete"