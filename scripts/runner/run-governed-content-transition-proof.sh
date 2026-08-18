#!/usr/bin/env bash
set -euo pipefail

: "${BASE_SHA:?BASE_SHA is required}"
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
BROWSER_ROOT="$ARTIFACT_ROOT/browser"
LOG_ROOT="$ARTIFACT_ROOT/logs"
RESULT_PATH="$ARTIFACT_ROOT/result.json"
EDITORIAL_MARKER="$PROOF_EDITORIAL_MARKER"
PILOT_IDS=("${PROOF_CONTENT_IDS[@]}")
PILOT_PAYLOADS=("${PROOF_PAYLOADS[@]}")
EXPECTED_ALIASES=("${PROOF_PUBLIC_PATHS[@]}")
EXPECTED_BROWSER_ONLY_PATHS=("${PROOF_BROWSER_ONLY_PATHS[@]}")
CONTACT_FORM_PATHS=("${PROOF_CONTACT_FORM_PATHS[@]}")
EXPECTED_MAPPING_COUNT="$PROOF_MAPPING_COUNT"
EDITORIAL_CONTENT_ID="$PROOF_EDITORIAL_CONTENT_ID"
EDITORIAL_PATH="$PROOF_EDITORIAL_PATH"
CATALOG_PATH="web/modules/custom/emerging_digital_content/content_sync/catalog.yml"
SQL_IDS="$PROOF_SQL_IDS"

mkdir -p "$SNAPSHOT_ROOT" "$BROWSER_ROOT" "$LOG_ROOT"

proof_status="FAIL"
proof_phase="bootstrap"

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
    --arg target_sha "$TARGET_SHA" \
    --arg proof_profile "$PROOF_PROFILE" \
    --arg marker "$EDITORIAL_MARKER" \
    --argjson pilot_content_ids "$pilot_ids_json" \
    --argjson exit_code "$exit_code" \
    '{
      result: $result,
      phase: $phase,
      request_id: $request_id,
      pr_number: $pr_number,
      base_sha: $base_sha,
      target_sha: $target_sha,
      proof_profile: $proof_profile,
      pilot_content_ids: $pilot_content_ids,
      editorial_marker: $marker,
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

verify_config_clean() {
  local label="$1"
  local config_status

  config_status="$(ddev drush config:status 2>&1)"
  printf '%s\n' "$config_status" | tee "$LOG_ROOT/config-status-${label}.txt"
  grep -Fq 'No differences' <<<"$config_status" || {
    echo "Configuration drift detected during ${label}." >&2
    return 1
  }
}

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

snapshot_revisions() {
  local destination="$1"
  ddev mysql -N -B -e "
    SELECT m.content_id, COUNT(DISTINCT nr.vid), MAX(nr.vid)
      FROM emerging_digital_content_sync_mapping m
      JOIN node_revision nr ON nr.nid = m.entity_id
     WHERE m.content_id IN (${SQL_IDS})
     GROUP BY m.content_id
     ORDER BY m.content_id;
  " > "$destination"
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

mapping_identity() {
  cut -f1,2,3,5 "$1"
}

assert_public_aliases() {
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

sync_full_catalog() {
  local label="$1"
  ddev drush emerging:content-sync:validate | tee "$LOG_ROOT/content-sync-${label}-validate.txt"
  ddev drush emerging:content-sync --all --dry-run | tee "$LOG_ROOT/content-sync-${label}-dry-run.txt"
  ddev drush emerging:content-sync --all | tee "$LOG_ROOT/content-sync-${label}-apply.txt"
  ddev drush cr
}

release_pilot() {
  local content_id
  for content_id in "${PILOT_IDS[@]}"; do
    ddev drush emerging:content-sync:release "$content_id" --dry-run \
      | tee "$LOG_ROOT/release-${content_id}-dry-run.txt"
    ddev drush emerging:content-sync:release "$content_id" --apply \
      | tee "$LOG_ROOT/release-${content_id}-apply.txt"
  done
}

readmit_pilot() {
  local content_id
  for content_id in "${PILOT_IDS[@]}"; do
    ddev drush emerging:content-sync:readmit "$content_id" --dry-run \
      | tee "$LOG_ROOT/readmit-${content_id}-dry-run.txt"
    ddev drush emerging:content-sync:readmit "$content_id" --apply \
      | tee "$LOG_ROOT/readmit-${content_id}-apply.txt"
    ddev drush emerging:content-sync "$content_id" \
      | tee "$LOG_ROOT/readmit-${content_id}-resync.txt"
  done
  ddev drush cr
}

apply_editorial_edit() {
  case "$PROOF_PROFILE" in
    case-studies-440)
      ddev drush php:eval '
        $database = \Drupal::database();
        $nid = $database->select("emerging_digital_content_sync_mapping", "m")
          ->fields("m", ["entity_id"])
          ->condition("content_id", "cas-client-migration-drupal-11")
          ->execute()
          ->fetchField();
        if (!$nid) {
          throw new \RuntimeException("Pilot mapping not found for editorial proof.");
        }
        $node = \Drupal\node\Entity\Node::load((int) $nid);
        if (!$node) {
          throw new \RuntimeException("Pilot node not found for editorial proof.");
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage("Governed Content transition proof #440");
        $translation = $node->hasTranslation("fr") ? $node->getTranslation("fr") : $node;
        if (!str_contains((string) $translation->label(), "[proof-440-editorial-survives]")) {
          $translation->setTitle($translation->label() . " [proof-440-editorial-survives]");
        }
        $node->save();
      '
      ;;

    ai-features-441)
      ddev drush php:eval '
        $database = \Drupal::database();
        $nid = $database->select("emerging_digital_content_sync_mapping", "m")
          ->fields("m", ["entity_id"])
          ->condition("content_id", "ai-redaction-assistee")
          ->execute()
          ->fetchField();
        if (!$nid) {
          throw new \RuntimeException("AI feature mapping not found for editorial proof.");
        }
        $node = \Drupal\node\Entity\Node::load((int) $nid);
        if (!$node) {
          throw new \RuntimeException("AI feature node not found for editorial proof.");
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage("Governed Content transition proof #441 ai_feature");
        $translation = $node->hasTranslation("fr") ? $node->getTranslation("fr") : $node;
        if (!str_contains((string) $translation->label(), "[proof-441-ai-features-editorial-survives]")) {
          $translation->setTitle($translation->label() . " [proof-441-ai-features-editorial-survives]");
        }
        $node->save();
      '
      ;;

    services-drupal-441)
      ddev drush php:eval '
        $database = \Drupal::database();
        $nid = $database->select("emerging_digital_content_sync_mapping", "m")
          ->fields("m", ["entity_id"])
          ->condition("content_id", "audit-drupal")
          ->execute()
          ->fetchField();
        if (!$nid) {
          throw new \RuntimeException("Drupal service mapping not found for editorial proof.");
        }
        $node = \Drupal\node\Entity\Node::load((int) $nid);
        if (!$node) {
          throw new \RuntimeException("Drupal service node not found for editorial proof.");
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage("Governed Content transition proof #441 services Drupal");
        $translation = $node->hasTranslation("fr") ? $node->getTranslation("fr") : $node;
        if (!str_contains((string) $translation->label(), "[proof-441-services-drupal-editorial-survives]")) {
          $translation->setTitle($translation->label() . " [proof-441-services-drupal-editorial-survives]");
        }
        $node->save();
      '
      ;;

    services-general-441)
      ddev drush php:eval '
        $database = \Drupal::database();
        $nid = $database->select("emerging_digital_content_sync_mapping", "m")
          ->fields("m", ["entity_id"])
          ->condition("content_id", "creation-site-web-professionnel")
          ->execute()
          ->fetchField();
        if (!$nid) {
          throw new \RuntimeException("General service mapping not found for editorial proof.");
        }
        $node = \Drupal\node\Entity\Node::load((int) $nid);
        if (!$node) {
          throw new \RuntimeException("General service node not found for editorial proof.");
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage("Governed Content transition proof #441 services general");
        $translation = $node->hasTranslation("fr") ? $node->getTranslation("fr") : $node;
        if (!str_contains((string) $translation->label(), "[proof-441-services-general-editorial-survives]")) {
          $translation->setTitle($translation->label() . " [proof-441-services-general-editorial-survives]");
        }
        $node->save();
      '
      ;;

    pages-medium-441)
      ddev drush php:eval '
        $database = \Drupal::database();
        $nid = $database->select("emerging_digital_content_sync_mapping", "m")
          ->fields("m", ["entity_id"])
          ->condition("content_id", "equipe")
          ->execute()
          ->fetchField();
        if (!$nid) {
          throw new \RuntimeException("Medium page mapping not found for editorial proof.");
        }
        $node = \Drupal\node\Entity\Node::load((int) $nid);
        if (!$node) {
          throw new \RuntimeException("Medium page node not found for editorial proof.");
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage("Governed Content transition proof #441 medium pages");
        $translation = $node->hasTranslation("fr") ? $node->getTranslation("fr") : $node;
        if (!str_contains((string) $translation->label(), "[proof-441-medium-pages-editorial-survives]")) {
          $translation->setTitle($translation->label() . " [proof-441-medium-pages-editorial-survives]");
        }
        $node->save();
      '
      ;;

    pages-final-441)
      ddev drush php:eval '
        $database = \Drupal::database();
        $nid = $database->select("emerging_digital_content_sync_mapping", "m")
          ->fields("m", ["entity_id"])
          ->condition("content_id", "services")
          ->execute()
          ->fetchField();
        if (!$nid) {
          throw new \RuntimeException("Final services page mapping not found for editorial proof.");
        }
        $node = \Drupal\node\Entity\Node::load((int) $nid);
        if (!$node) {
          throw new \RuntimeException("Final services page node not found for editorial proof.");
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage("Governed Content transition proof #441 final pages");
        $translation = $node->hasTranslation("fr") ? $node->getTranslation("fr") : $node;
        if (!str_contains((string) $translation->label(), "[proof-441-final-pages-editorial-survives]")) {
          $translation->setTitle($translation->label() . " [proof-441-final-pages-editorial-survives]");
        }
        $node->save();
      '
      ;;
  esac
}

assert_editorial_edit_survives() {
  ddev mysql -N -B -e "
    SELECT nfd.title
      FROM emerging_digital_content_sync_mapping m
      JOIN node_field_data nfd ON nfd.nid = m.entity_id AND nfd.langcode = 'fr'
     WHERE m.content_id = '${EDITORIAL_CONTENT_ID}';
  " | grep -Fq "$EDITORIAL_MARKER"
}

detect_ddev_url() {
  ddev describe -j | python3 -c '
import json
import sys

def find_url(value):
    if isinstance(value, str):
        return value if value.startswith(("https://", "http://")) else None
    if isinstance(value, list):
        urls = [find_url(item) for item in value]
        urls = [url for url in urls if url]
        return next((url for url in urls if url.startswith("https://")), urls[0] if urls else None)
    if not isinstance(value, dict):
        return None
    for key, entry in value.items():
        normalized = key.lower().replace("-", "_")
        if normalized in {"primary_url", "primaryurl"} and isinstance(entry, str):
            if entry.startswith(("https://", "http://")):
                return entry
    for entry in value.values():
        found = find_url(entry)
        if found:
            return found
    return None

data = json.load(sys.stdin)
url = find_url(data)
if not url:
    raise SystemExit("No DDEV primary URL found")
print(url.rstrip("/"))
'
}

path_is_contact_form() {
  local candidate="$1"
  local path
  for path in "${CONTACT_FORM_PATHS[@]}"; do
    [[ "$candidate" == "$path" ]] && return 0
  done
  return 1
}

browser_pages_json() {
  local path
  local contact
  {
    for path in "${EXPECTED_ALIASES[@]}"; do
      contact=false
      if path_is_contact_form "$path"; then
        contact=true
      fi
      jq -nc \
        --arg path "$path" \
        --arg editorial "$EDITORIAL_PATH" \
        --argjson contact "$contact" \
        '[$path, $path == $editorial, $contact]'
    done
    for path in "${EXPECTED_BROWSER_ONLY_PATHS[@]}"; do
      jq -nc --arg path "$path" '[$path, false, false]'
    done
  } | jq -s '.'
}

run_browser_proof() {
  local base_url
  local pages_json
  base_url="$(detect_ddev_url)"
  pages_json="$(browser_pages_json)"

  cat > tests/browser/governed-content-transition-proof.spec.mjs <<'EOF'
import { expect, test } from '@playwright/test';
import { mkdir } from 'node:fs/promises';

const pages = JSON.parse(process.env.PROOF_PAGES_JSON);

for (const [path, expectsMarker, expectsContactForm] of pages) {
  test(`released governed-content item remains public: ${path}`, async ({ page }, testInfo) => {
    const runtimeErrors = [];
    page.on('console', (message) => {
      if (message.type() === 'error') {
        runtimeErrors.push(`console: ${message.text()}`);
      }
    });
    page.on('pageerror', (error) => runtimeErrors.push(`pageerror: ${error.message}`));

    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    expect(response, `No navigation response for ${path}`).not.toBeNull();
    expect(response.status(), `Unexpected HTTP status for ${path}`).toBeLessThan(400);
    await expect(page.locator('body')).not.toContainText('The website encountered an unexpected error.');

    if (expectsMarker) {
      await expect(page.locator('body')).toContainText(process.env.PROOF_EDITORIAL_MARKER);
    }

    if (expectsContactForm) {
      const form = page.locator('form').filter({ has: page.locator('[name="email"]') }).first();
      await expect(form).toBeVisible();
      for (const fieldName of ['name', 'email', 'subject', 'message', 'rgpd_consent']) {
        await expect(form.locator(`[name="${fieldName}"]`), `Missing contact field ${fieldName} on ${path}`).toBeVisible();
      }
      await expect(form.locator('button[type="submit"], input[type="submit"]').first()).toBeVisible();
    }

    expect(runtimeErrors, `Browser runtime errors for ${path}`).toEqual([]);

    await mkdir('artifacts/governed-content-transition/browser', { recursive: true });
    const slug = path.replace(/^\//, '').replaceAll('/', '--');
    await page.screenshot({
      path: `artifacts/governed-content-transition/browser/${slug}-${testInfo.project.name}.png`,
      fullPage: true,
    });
  });
}
EOF

  PLAYWRIGHT_BASE_URL="$base_url" \
  PROOF_EDITORIAL_MARKER="$EDITORIAL_MARKER" \
  PROOF_PAGES_JSON="$pages_json" \
    npx playwright test tests/browser/governed-content-transition-proof.spec.mjs \
      | tee "$LOG_ROOT/playwright.txt"
}

proof_phase="prepare-base"
git fetch --no-tags origin "$BASE_SHA" "$TARGET_SHA"
git checkout --detach "$BASE_SHA"
git diff --check

cat > .ddev/config.gate-governed-content-proof.yaml <<EOF
name: agency-governed-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}
EOF

ddev start -y
ddev composer install --no-interaction --no-progress --prefer-dist
admin_pass="$(openssl rand -hex 24)"
ddev drush site:install --existing-config -y --account-pass="$admin_pass"
unset admin_pass
ddev drush cim -y
ddev drush cr
verify_config_clean "base-before-sync"
sync_full_catalog "base"
verify_config_clean "base-after-sync"

snapshot_mappings "$SNAPSHOT_ROOT/base-mappings.tsv"
snapshot_nodes "$SNAPSHOT_ROOT/base-nodes.tsv"
snapshot_revisions "$SNAPSHOT_ROOT/base-revisions.tsv"
assert_mapping_status "$SNAPSHOT_ROOT/base-mappings.tsv" "active"
assert_public_aliases "$SNAPSHOT_ROOT/base-nodes.tsv"

proof_phase="target-release"
git checkout --detach "$TARGET_SHA"
git diff --check
ddev composer install --no-interaction --no-progress --prefer-dist
ddev drush updb -y
ddev drush cim -y
ddev drush cr
verify_config_clean "target-before-release"

release_pilot
snapshot_mappings "$SNAPSHOT_ROOT/released-mappings.tsv"
snapshot_nodes "$SNAPSHOT_ROOT/released-nodes.tsv"
snapshot_revisions "$SNAPSHOT_ROOT/released-revisions.tsv"
assert_mapping_status "$SNAPSHOT_ROOT/released-mappings.tsv" "released"
assert_public_aliases "$SNAPSHOT_ROOT/released-nodes.tsv"
diff -u \
  <(mapping_identity "$SNAPSHOT_ROOT/base-mappings.tsv") \
  <(mapping_identity "$SNAPSHOT_ROOT/released-mappings.tsv") \
  | tee "$LOG_ROOT/release-mapping-identity.diff"
diff -u "$SNAPSHOT_ROOT/base-nodes.tsv" "$SNAPSHOT_ROOT/released-nodes.tsv" \
  | tee "$LOG_ROOT/release-node-state.diff"

sync_full_catalog "target-after-release"
verify_config_clean "target-after-sync"
snapshot_nodes "$SNAPSHOT_ROOT/post-deploy-nodes.tsv"
diff -u "$SNAPSHOT_ROOT/base-nodes.tsv" "$SNAPSHOT_ROOT/post-deploy-nodes.tsv" \
  | tee "$LOG_ROOT/post-deploy-node-state.diff"

proof_phase="editorial-persistence"
apply_editorial_edit
assert_editorial_edit_survives
snapshot_revisions "$SNAPSHOT_ROOT/after-editorial-edit-revisions.tsv"
sync_full_catalog "target-after-editorial-edit"
assert_editorial_edit_survives
snapshot_nodes "$SNAPSHOT_ROOT/after-editorial-resync-nodes.tsv"
snapshot_revisions "$SNAPSHOT_ROOT/after-editorial-resync-revisions.tsv"

proof_phase="browser-proof"
run_browser_proof

proof_phase="rollback"
git checkout "$BASE_SHA" -- "$CATALOG_PATH" "${PILOT_PAYLOADS[@]}"
readmit_pilot
snapshot_mappings "$SNAPSHOT_ROOT/rollback-mappings.tsv"
snapshot_nodes "$SNAPSHOT_ROOT/rollback-nodes.tsv"
snapshot_revisions "$SNAPSHOT_ROOT/rollback-revisions.tsv"
assert_mapping_status "$SNAPSHOT_ROOT/rollback-mappings.tsv" "active"
assert_public_aliases "$SNAPSHOT_ROOT/rollback-nodes.tsv"
diff -u \
  <(mapping_identity "$SNAPSHOT_ROOT/base-mappings.tsv") \
  <(mapping_identity "$SNAPSHOT_ROOT/rollback-mappings.tsv") \
  | tee "$LOG_ROOT/rollback-mapping-identity.diff"
diff -u "$SNAPSHOT_ROOT/base-nodes.tsv" "$SNAPSHOT_ROOT/rollback-nodes.tsv" \
  | tee "$LOG_ROOT/rollback-node-state.diff"

proof_status="PASS"
proof_phase="complete"