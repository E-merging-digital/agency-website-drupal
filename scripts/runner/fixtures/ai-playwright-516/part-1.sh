mkdir -p "$ARTIFACT_DIR"
ARTIFACT_DIR="$(cd "$ARTIFACT_DIR" && pwd)"
if [[ "$ARTIFACT_DIR" != "$TARGET_DIR/$ARTIFACT_REL" ]]; then
  echo "ARTIFACT_DIR must be $TARGET_DIR/$ARTIFACT_REL" >&2
  exit 1
fi
cd "$TARGET_DIR"

write_failure() {
  local phase="$1"
  local message="$2"
  jq -n \
    --arg status 'FAIL' \
    --arg phase "$phase" \
    --arg message "$message" \
    '{status:$status,phase:$phase,message:$message}' \
    > "$ARTIFACT_DIR/result.json"
}

restore_if_needed() {
  if ! command -v ddev >/dev/null 2>&1; then
    return 0
  fi
  ddev drush php:eval '
    $state = \Drupal::state();
    $stored = $state->get("agency_ai_playwright_516_test.original_components");
    if (is_string($stored) && $stored !== "") {
      $values = json_decode($stored, TRUE, 512, JSON_THROW_ON_ERROR);
      $ids = \Drupal::entityQuery("canvas_page")
        ->accessCheck(FALSE)
        ->condition("uuid", "52600000-0000-4000-8000-000000000001")
        ->execute();
      if ($ids) {
        $page = \Drupal::entityTypeManager()->getStorage("canvas_page")->load(reset($ids));
        if ($page) {
          $page->set("components", $values);
          $page->save();
        }
      }
      $state->delete("agency_ai_playwright_516_test.original_components");
    }
  ' >/dev/null 2>&1 || true
}

on_exit() {
  local rc=$?
  trap - EXIT
  restore_if_needed
  rm -f .agency516-run.php
  rm -rf "web/modules/custom/${TEST_MODULE}"
  if [[ $rc -ne 0 && ! -s "$ARTIFACT_DIR/result.json" ]]; then
    write_failure 'unexpected' "Trusted governed AI Playwright loop failed with exit code $rc"
  fi
  exit "$rc"
}
trap on_exit EXIT

command -v ddev >/dev/null
command -v jq >/dev/null
command -v git >/dev/null
command -v openssl >/dev/null

test -f composer.json
test -f composer.lock
test -f .ddev/config.yaml
test -f docs/evaluations/ai-playwright-516-governed-loop.md
test "$(cat docs/evaluations/ai-playwright-516-governed-loop.md)" = "$MARKER"
if grep -q 'drupal/ai_playwright' composer.json; then
  write_failure 'preflight' 'ai_playwright must not be a production Composer dependency.'
  exit 1
fi

git diff --check
before_composer="$(sha256sum composer.json | awk '{print $1}')"
before_lock="$(sha256sum composer.lock | awk '{print $1}')"

# Alpha dependency is deliberately workspace-only and disappears with DDEV/workspace cleanup.
ddev composer require "${EXPECTED_PACKAGE}:${EXPECTED_VERSION}" --no-interaction --no-progress
installed_version="$(ddev composer show "$EXPECTED_PACKAGE" --format=json | jq -r '.versions[]' | sed 's/^\* //' | head -n 1)"
if [[ "$installed_version" != "$EXPECTED_VERSION" ]]; then
  write_failure 'composer' "Expected $EXPECTED_VERSION, got $installed_version"
  exit 1
fi

ddev composer show drupal/ai --format=json > "$ARTIFACT_DIR/ai-package.json"
ddev composer show drupal/ai_agents --format=json > "$ARTIFACT_DIR/ai-agents-package.json"
ddev composer show drupal/canvas --format=json > "$ARTIFACT_DIR/canvas-package.json"
ddev composer show "$EXPECTED_PACKAGE" --format=json > "$ARTIFACT_DIR/ai-playwright-package.json"

module_dir='/var/www/html/web/modules/contrib/ai_playwright'
ddev exec node --version > "$ARTIFACT_DIR/ddev-node-version.txt"
ddev exec npm --version > "$ARTIFACT_DIR/ddev-npm-version.txt"
ddev exec bash -lc "cd '$module_dir' && npm install --no-audit --no-fund"
if ! ddev exec bash -lc "cd '$module_dir' && npx playwright install --with-deps chromium" \
  > "$ARTIFACT_DIR/chromium-install.txt" 2>&1; then
  msg="$(tail -n 8 "$ARTIFACT_DIR/chromium-install.txt" | tr '\r\n' '  ' | cut -c1-300)"
  write_failure 'chromium-install' "${msg:-Playwright Chromium installation failed.}"
  exit 1
fi

if ! ddev exec bash -lc "cd '$module_dir' && node - <<'NODE'
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true });
  const version = browser.version();
  await browser.close();
  process.stdout.write(JSON.stringify({ ok: true, version }));
})().catch((error) => {
  console.error(error && error.stack ? error.stack : String(error));
  process.exit(1);
});
NODE" > "$ARTIFACT_DIR/chromium-smoke.json" 2> "$ARTIFACT_DIR/chromium-smoke-error.txt"; then
  msg="$(tr '\r\n' '  ' < "$ARTIFACT_DIR/chromium-smoke-error.txt" | cut -c1-300)"
  write_failure 'chromium-smoke' "${msg:-Chromium direct launch failed.}"
  exit 1
fi
jq -e '.ok == true' "$ARTIFACT_DIR/chromium-smoke.json" >/dev/null

admin_pass="$(openssl rand -hex 24)"
ddev drush site:install --existing-config -y --account-pass="$admin_pass"
unset admin_pass

# ai_test lives under the AI package tests tree; this is enabled only in this disposable DDEV.
cat >> web/sites/default/settings.php <<'PHP'

// #516 trusted DEV-ONLY proof: discover ai_test from the contrib package test tree.
$settings['extension_discovery_scan_tests'] = TRUE;
PHP

ddev drush cim -y
ddev drush cr
ddev drush emerging:governed-content --all
ddev drush en ai_test ai_agents ai_playwright -y
ddev drush cr

ddev drush cset ai_playwright.settings internal_base 'http://localhost' -y
ddev drush cset ai_playwright.settings node_binary 'node' -y
ddev drush cset ai_playwright.settings timeout 60 -y
ddev drush cset ai_playwright.settings screenshot_scheme 'public' -y
ddev drush cset ai_playwright.settings allow_external_urls 0 -y
ddev drush cset ai.settings default_providers.chat_with_tools.provider_id echoai -y
ddev drush cset ai.settings default_providers.chat_with_tools.model_id gpt-test -y
ddev drush cr

ddev exec curl -fsS "http://localhost${BASELINE_PATH}" > /dev/null

# Runtime-only test adapter: one mutation, one UUID, one component and one prop.
mkdir -p "web/modules/custom/${TEST_MODULE}/src/Plugin/AiFunctionCall"
cat > "web/modules/custom/${TEST_MODULE}/${TEST_MODULE}.info.yml" <<'YAML'
name: 'Agency AI Playwright #516 Test'
type: module
description: 'Ephemeral trusted test adapter for the governed #516 proof.'
package: Testing
core_version_requirement: ^11
dependencies:
  - ai:ai
  - ai_agents:ai_agents
  - ai_playwright:ai_playwright
  - canvas:canvas
YAML
cat > "web/modules/custom/${TEST_MODULE}/${TEST_MODULE}.permissions.yml" <<'YAML'
use agency 516 bounded canvas mutation:
  title: 'Use Agency #516 bounded Canvas mutation'
  restrict access: true
YAML
cp "$TRUSTED_FIXTURE_DIR/BoundedCanvasHeading.php" "web/modules/custom/${TEST_MODULE}/src/Plugin/AiFunctionCall/BoundedCanvasHeading.php"

ddev drush en "$TEST_MODULE" -y
ddev drush cr

