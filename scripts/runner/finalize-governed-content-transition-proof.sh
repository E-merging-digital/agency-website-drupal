#!/usr/bin/env bash
set -euo pipefail

: "${BASE_SHA:?BASE_SHA is required}"
: "${RELEASE_SHA:?RELEASE_SHA is required}"
: "${TARGET_SHA:?TARGET_SHA is required}"
: "${PR_NUMBER:?PR_NUMBER is required}"
: "${REQUEST_ID:?REQUEST_ID is required}"

ARTIFACT_ROOT="artifacts/governed-content-transition"
SNAPSHOT_ROOT="$ARTIFACT_ROOT/snapshots"
LOG_ROOT="$ARTIFACT_ROOT/logs"
RESULT_PATH="$ARTIFACT_ROOT/final-result.json"
EDITORIAL_MARKER="[proof-440-editorial-survives]"
CATALOG_PATH="web/modules/custom/emerging_digital_content/content_sync/catalog.yml"
SQL_IDS="'cas-client-refonte-drupal-institutionnelle','cas-client-migration-drupal-11','cas-client-integration-ia-editoriale'"
PILOT_IDS=(
  "cas-client-refonte-drupal-institutionnelle"
  "cas-client-migration-drupal-11"
  "cas-client-integration-ia-editoriale"
)
PILOT_PAYLOADS=(
  "web/modules/custom/emerging_digital_content/content_sync/node/cas-client-refonte-drupal-institutionnelle.yml"
  "web/modules/custom/emerging_digital_content/content_sync/node/cas-client-migration-drupal-11.yml"
  "web/modules/custom/emerging_digital_content/content_sync/node/cas-client-integration-ia-editoriale.yml"
)

proof_status="FAIL"
proof_phase="finalize-bootstrap"

write_result() {
  local exit_code="$1"
  jq -n \
    --arg result "$proof_status" \
    --arg phase "$proof_phase" \
    --arg request_id "$REQUEST_ID" \
    --arg pr_number "$PR_NUMBER" \
    --arg base_sha "$BASE_SHA" \
    --arg release_sha "$RELEASE_SHA" \
    --arg target_sha "$TARGET_SHA" \
    --argjson exit_code "$exit_code" \
    '{
      result: $result,
      phase: $phase,
      request_id: $request_id,
      pr_number: $pr_number,
      base_sha: $base_sha,
      release_sha: $release_sha,
      target_sha: $target_sha,
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
  [[ "$(wc -l < "$snapshot" | tr -d ' ')" == "3" ]]
  awk -F '\t' -v expected="$expected" '
    $4 != expected { bad = 1 }
    END { exit bad }
  ' "$snapshot"
}

assert_aliases_and_publication() {
  local snapshot="$1"
  local alias
  local expected_aliases=(
    "/cas-clients/refonte-drupal-institutionnelle"
    "/case-studies/institutional-drupal-redesign"
    "/cas-clients/migration-drupal-11"
    "/case-studies/drupal-11-migration"
    "/cas-clients/integration-ia-editoriale"
    "/case-studies/editorial-ai-integration"
  )
  for alias in "${expected_aliases[@]}"; do
    grep -Fq $'\t'"${alias}" "$snapshot"
  done
  awk -F '\t' '$5 != "1" { bad = 1 } END { exit bad }' "$snapshot"
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
  grep -Fq 'No differences' <<<"$status"
}

apply_editorial_edit() {
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
}

assert_editorial_marker() {
  ddev mysql -N -B -e "
    SELECT nfd.title
      FROM emerging_digital_content_sync_mapping m
      JOIN node_field_data nfd
        ON nfd.nid = m.entity_id
       AND nfd.langcode = 'fr'
     WHERE m.content_id = 'cas-client-migration-drupal-11';
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
PLAYWRIGHT_BASE_URL="$base_url" \
PROOF_EDITORIAL_MARKER="$EDITORIAL_MARKER" \
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
