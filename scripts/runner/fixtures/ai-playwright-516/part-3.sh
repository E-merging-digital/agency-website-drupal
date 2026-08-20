cp "$TRUSTED_FIXTURE_DIR/agency516-run.php" .agency516-run.php

if ! ddev drush scr .agency516-run.php > "$ARTIFACT_DIR/agent-run.stdout.txt" 2> "$ARTIFACT_DIR/agent-run.stderr.txt"; then
  # Preserve independent HTTP-origin diagnostics before cleanup. This runs only
  # on a failing agent loop and does not invalidate or rebuild any cache.
  capture_http_probe() {
    local label="$1"
    local url="$2"
    local headers_tmp="/tmp/agency516-${label}.headers"
    local body_tmp="/tmp/agency516-${label}.html"

    if ddev exec bash -lc "curl -fsS -D '$headers_tmp' -o '$body_tmp' '$url'" >/dev/null 2>&1; then
      ddev exec cat "$headers_tmp" > "$ARTIFACT_DIR/restored-http-${label}.headers.txt" 2>/dev/null || true
      ddev exec cat "$body_tmp" > "$ARTIFACT_DIR/restored-http-${label}.html" 2>/dev/null || true
      printf '%s' 'ok'
    else
      : > "$ARTIFACT_DIR/restored-http-${label}.headers.txt"
      : > "$ARTIFACT_DIR/restored-http-${label}.html"
      printf '%s' 'error'
    fi
  }

  classify_http_body() {
    local path="$1"
    if grep -Fq "$TEMP_HEADING" "$path"; then
      printf '%s' 'temporary'
    elif grep -Fq "$ORIGINAL_HEADING" "$path"; then
      printf '%s' 'original'
    else
      printf '%s' 'neither'
    fi
  }

  normal_probe_status="$(capture_http_probe 'canonical' "http://localhost${BASELINE_PATH}")"
  probe_token="$(date +%s%N)"
  bust_probe_status="$(capture_http_probe 'cache-bust' "http://localhost${BASELINE_PATH}?agency516_probe=${probe_token}")"
  normal_body_state="$(classify_http_body "$ARTIFACT_DIR/restored-http-canonical.html")"
  bust_body_state="$(classify_http_body "$ARTIFACT_DIR/restored-http-cache-bust.html")"

  jq -n \
    --arg canonical_status "$normal_probe_status" \
    --arg canonical_body_state "$normal_body_state" \
    --arg cache_bust_status "$bust_probe_status" \
    --arg cache_bust_body_state "$bust_body_state" \
    --arg probe_token "$probe_token" \
    '{
      canonical:{status:$canonical_status,body_state:$canonical_body_state},
      cache_bust:{status:$cache_bust_status,body_state:$cache_bust_body_state,probe_token:$probe_token},
      expected_original:"Composition Canvas bornée",
      temporary:"Composition Canvas bornée — vérification agentique",
      cache_mutation_performed:false
    }' > "$ARTIFACT_DIR/restored-http-diagnostics.json"

  msg="$(tail -n 12 "$ARTIFACT_DIR/agent-run.stderr.txt" | tr '\r\n' '  ' | cut -c1-500)"
  write_failure 'agent-loop' "${msg:-Deterministic AI Agents loop failed.}"
  exit 1
fi
jq -e '.status == "PASS" and .provider == "echoai" and .provider_network_required == false and .provider_hops == 6' "$ARTIFACT_DIR/agent-loop.json" >/dev/null

# Verify exact restoration at entity level. Canvas content component inputs are
# JSON-backed in ComponentTreeItem storage, so decode the upstream format.
ddev drush php:eval '
  $ids = \Drupal::entityQuery("canvas_page")->accessCheck(FALSE)->condition("uuid", "52600000-0000-4000-8000-000000000001")->execute();
  if (count($ids) !== 1) { throw new \RuntimeException("Baseline page not found uniquely after restoration."); }
  $page = \Drupal::entityTypeManager()->getStorage("canvas_page")->load(reset($ids));
  $values = $page->get("components")->getValue();
  $heading = NULL;
  $ids_seen = [];
  foreach ($values as $component) {
    $ids_seen[] = $component["component_id"] ?? NULL;
    if (($component["uuid"] ?? NULL) === "52600000-0000-4000-8000-000000000103") {
      if (($component["component_id"] ?? NULL) !== "sdc.emerging_digital.cta") {
        throw new \RuntimeException("Fixed CTA UUID no longer identifies the approved CTA after restoration.");
      }
      $rawInputs = $component["inputs"] ?? NULL;
      $inputs = is_string($rawInputs) ? json_decode($rawInputs, TRUE, 512, JSON_THROW_ON_ERROR) : $rawInputs;
      if (!is_array($inputs)) { throw new \RuntimeException("Restored Canvas CTA inputs are not a valid mapping."); }
      $heading = $inputs["heading"] ?? NULL;
    }
  }
  if ($ids_seen !== ["sdc.emerging_digital.hero", "sdc.emerging_digital.trust-list", "sdc.emerging_digital.cta"]) {
    throw new \RuntimeException("Baseline component allowlist/order changed after restoration.");
  }
  if (!is_string($heading) || trim($heading) === "") { throw new \RuntimeException("Restored CTA heading is missing or invalid."); }
  if (\Drupal::state()->get("agency_ai_playwright_516_test.original_components")) { throw new \RuntimeException("Restore snapshot still exists."); }
  echo json_encode(["component_ids" => $ids_seen, "heading" => $heading, "restored" => TRUE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
' > "$ARTIFACT_DIR/baseline-restored.json"
jq -e --slurp '
  .[0].component_ids == .[1].component_ids
  and .[0].heading == .[1].heading
  and .[1].restored == true
' "$ARTIFACT_DIR/baseline-before.json" "$ARTIFACT_DIR/baseline-restored.json" >/dev/null

# Exactly three browser_preview executions should have produced three managed screenshots.
ddev drush php:eval '
  $ids = \Drupal::entityQuery("file")->accessCheck(FALSE)->condition("uri", "public://ai_playwright/%", "LIKE")->sort("fid", "ASC")->execute();
  $files = \Drupal::entityTypeManager()->getStorage("file")->loadMultiple($ids);
  $out = [];
  foreach ($files as $file) {
    $out[] = ["fid" => (int) $file->id(), "uri" => $file->getFileUri()];
  }
  echo json_encode($out, JSON_UNESCAPED_SLASHES);
' > "$ARTIFACT_DIR/screenshots.json"
jq -e 'length == 3' "$ARTIFACT_DIR/screenshots.json" >/dev/null
mapfile -t screenshot_uris < <(jq -r '.[].uri' "$ARTIFACT_DIR/screenshots.json")
labels=(before after restored)
for i in 0 1 2; do
  uri="${screenshot_uris[$i]}"
  [[ "$uri" == public://ai_playwright/* ]]
  host_path="web/sites/default/files/${uri#public://}"
  test -s "$host_path"
  cp "$host_path" "$ARTIFACT_DIR/${labels[$i]}.png"
done

# Re-prove same-site boundary and least privilege at the end of the run.
ddev drush php:eval '
  $runner = \Drupal::service("ai_playwright.runner");
  if ($runner->resolveUrl("https://example.com") !== NULL) { throw new \RuntimeException("Off-site URL accepted."); }
  if ($runner->resolveUrl("file:///etc/passwd") !== NULL) { throw new \RuntimeException("file URL accepted."); }
  $role = \Drupal\user\Entity\Role::load("agency_516_agent");
  if (!$role || !$role->hasPermission("use ai playwright") || !$role->hasPermission("use agency 516 bounded canvas mutation")) {
    throw new \RuntimeException("Least-privilege role is incomplete.");
  }
  $permissions = array_values($role->getPermissions());
  sort($permissions);
  echo json_encode(["offsite_refused" => TRUE, "file_scheme_refused" => TRUE, "permissions" => $permissions]);
' > "$ARTIFACT_DIR/security-boundary.json"
jq -e '.offsite_refused == true and .file_scheme_refused == true' "$ARTIFACT_DIR/security-boundary.json" >/dev/null

# The runtime-only test adapter and fixture files are evidence mechanics, never product code.
rm -f .agency516-run.php
rm -rf "web/modules/custom/${TEST_MODULE}"

after_composer="$(sha256sum composer.json | awk '{print $1}')"
after_lock="$(sha256sum composer.lock | awk '{print $1}')"

jq -n \
  --arg status 'PASS' \
  --arg package "$EXPECTED_PACKAGE" \
  --arg version "$installed_version" \
  --arg baseline_uuid "$BASELINE_UUID" \
  --arg baseline_path "$BASELINE_PATH" \
  --arg original_heading "$ORIGINAL_HEADING" \
  --arg temporary_heading "$TEMP_HEADING" \
  --arg before_composer "$before_composer" \
  --arg after_composer "$after_composer" \
  --arg before_lock "$before_lock" \
  --arg after_lock "$after_lock" \
  '{
    status:$status,
    package:$package,
    version:$version,
    mode:"DEV_ONLY_EPHEMERAL",
    contrib_modules_first:true,
    provider:{id:"echoai",model:"gpt-test",network_required:false,secret_required:false},
    baseline:{uuid:$baseline_uuid,path:$baseline_path},
    mutation:{component:"sdc.emerging_digital.cta",prop:"heading",original:$original_heading,temporary:$temporary_heading},
    inspections:["before","after","restored"],
    browser_preview_tool_calls:3,
    bounded_mutation_tool_calls:2,
    restored:true,
    same_site_only:true,
    production_dependency_persisted:false,
    composer_hashes:{before:$before_composer,ephemeral_after:$after_composer},
    lock_hashes:{before:$before_lock,ephemeral_after:$after_lock}
  }' > "$ARTIFACT_DIR/result.json"
jq -e '.status == "PASS" and .restored == true and .provider.network_required == false and .browser_preview_tool_calls == 3' "$ARTIFACT_DIR/result.json" >/dev/null
