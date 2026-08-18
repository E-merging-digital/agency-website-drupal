#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"

EXPECTED_PACKAGE='drupal/ai_playwright'
EXPECTED_VERSION='1.0.0-alpha1'
FR_PATH='/fr/mentions-legales'
EN_PATH='/en/legal-notices'

TARGET_DIR="$(cd "$TARGET_DIR" && pwd)"
mkdir -p "$ARTIFACT_DIR"
ARTIFACT_DIR="$(cd "$ARTIFACT_DIR" && pwd)"
cd "$TARGET_DIR"

write_failure() {
  local phase="$1"
  local message="$2"
  jq -n \
    --arg status 'FAIL' \
    --arg phase "$phase" \
    --arg message "$message" \
    '{status:$status, phase:$phase, message:$message}' \
    > "$ARTIFACT_DIR/result.json"
}

trap 'rc=$?; if [[ $rc -ne 0 && ! -s "$ARTIFACT_DIR/result.json" ]]; then write_failure "unexpected" "Trusted AI Playwright evaluation failed with exit code $rc"; fi' EXIT

command -v ddev >/dev/null
command -v jq >/dev/null
command -v git >/dev/null

test -f composer.json
test -f composer.lock
test -f .ddev/config.yaml

git diff --check
before_composer="$(sha256sum composer.json | awk '{print $1}')"
before_lock="$(sha256sum composer.lock | awk '{print $1}')"

# The alpha dependency is deliberately installed only inside this isolated
# evaluation workspace. It is never committed to main by this route.
ddev composer require "${EXPECTED_PACKAGE}:${EXPECTED_VERSION}" --no-interaction --no-progress

installed_version="$(ddev composer show "$EXPECTED_PACKAGE" --format=json | jq -r '.versions[0]')"
if [[ "$installed_version" != "$EXPECTED_VERSION" ]]; then
  write_failure 'composer' "Expected $EXPECTED_VERSION, got $installed_version"
  exit 1
fi

ddev composer show drupal/ai_agents --format=json > "$ARTIFACT_DIR/ai-agents-package.json"
ddev composer show drupal/modeler_api --format=json > "$ARTIFACT_DIR/modeler-api-package.json"
ddev composer show "$EXPECTED_PACKAGE" --format=json > "$ARTIFACT_DIR/ai-playwright-package.json"

# Node must exist in the same DDEV web container from which Drupal/PHP launches
# the bundled browser process. Host-level Node is explicitly not sufficient.
if ! ddev exec node --version > "$ARTIFACT_DIR/ddev-node-version.txt" 2>&1; then
  write_failure 'server-node' 'Node.js is unavailable in the DDEV web container.'
  exit 1
fi
if ! ddev exec npm --version > "$ARTIFACT_DIR/ddev-npm-version.txt" 2>&1; then
  write_failure 'server-node' 'npm is unavailable in the DDEV web container.'
  exit 1
fi

module_dir='/var/www/html/web/modules/contrib/ai_playwright'
ddev exec bash -lc "cd '$module_dir' && npm install --no-audit --no-fund"
ddev exec bash -lc "cd '$module_dir' && npx playwright install chromium"

admin_pass="$(openssl rand -hex 24)"
ddev drush site:install --existing-config -y --account-pass="$admin_pass"
unset admin_pass

ddev drush cim -y
ddev drush cr
# Rebuild the repository-owned legal Governed Content so FR/EN proof pages are
# deterministic on a fresh site.
ddev drush emerging:governed-content --all
ddev drush en ai_agents ai_playwright -y
ddev drush cr

ddev drush cset ai_playwright.settings internal_base 'http://localhost' -y
ddev drush cset ai_playwright.settings node_binary 'node' -y
ddev drush cset ai_playwright.settings timeout 60 -y
ddev drush cset ai_playwright.settings screenshot_scheme 'public' -y
ddev drush cset ai_playwright.settings allow_external_urls 0 -y
ddev drush cr

# Prove the exact server-side route the module will use is reachable from the
# DDEV web container before invoking Playwright.
ddev exec curl -fsS "http://localhost${FR_PATH}" > /dev/null
ddev exec curl -fsS "http://localhost${EN_PATH}" > /dev/null

capture_page() {
  local label="$1"
  local path="$2"
  local json_file="$ARTIFACT_DIR/${label}.json"
  local fid uri host_path

  ddev drush php:eval '
    $path = getenv("AI_PLAYWRIGHT_TEST_PATH");
    $result = \Drupal::service("ai_playwright.runner")->capture($path, FALSE);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ' --env="AI_PLAYWRIGHT_TEST_PATH=$path" > "$json_file"

  jq -e '.ok == true' "$json_file" >/dev/null
  jq -e '.title | type == "string" and length > 0' "$json_file" >/dev/null
  jq -e '.text | type == "string" and length > 0' "$json_file" >/dev/null
  jq -e '.console_errors | type == "array"' "$json_file" >/dev/null
  fid="$(jq -r '.fid // empty' "$json_file")"
  test -n "$fid"

  uri="$(ddev drush php:eval '
    $fid = (int) getenv("AI_PLAYWRIGHT_TEST_FID");
    $file = \Drupal\file\Entity\File::load($fid);
    if (!$file) { throw new \RuntimeException("Screenshot file not found."); }
    echo $file->getFileUri();
  ' --env="AI_PLAYWRIGHT_TEST_FID=$fid")"
  [[ "$uri" == public://ai_playwright/* ]]
  host_path="web/sites/default/files/${uri#public://}"
  test -s "$host_path"
  cp "$host_path" "$ARTIFACT_DIR/${label}.png"
}

capture_page 'browser-preview-fr' "$FR_PATH"
capture_page 'browser-preview-en' "$EN_PATH"

# The URL resolver is the SSRF boundary. With the default/dev-only policy,
# absolute off-site URLs and non-http schemes must both be rejected.
ddev drush php:eval '
  $runner = \Drupal::service("ai_playwright.runner");
  if ($runner->resolveUrl("https://example.com") !== NULL) {
    throw new \RuntimeException("Off-site https URL was unexpectedly accepted.");
  }
  if ($runner->resolveUrl("file:///etc/passwd") !== NULL) {
    throw new \RuntimeException("file:// URL was unexpectedly accepted.");
  }
  echo "offsite-and-file-schemes-refused";
' > "$ARTIFACT_DIR/url-boundary.txt"

# Exercise the actual FunctionCall permission gate with two ephemeral users:
# one authenticated user without the dedicated permission (must fail), and one
# role containing only `use ai playwright` (must succeed).
ddev drush php:eval '
  use Drupal\user\Entity\Role;
  use Drupal\user\Entity\User;

  $container = \Drupal::getContainer();
  $plugin_id = "ai_playwright:browser_preview";
  $manager = NULL;
  $manager_id = NULL;
  foreach ($container->getServiceIds() as $service_id) {
    if (!str_contains($service_id, "plugin.manager") || !str_contains($service_id, "ai")) {
      continue;
    }
    try {
      $candidate = $container->get($service_id);
      if (method_exists($candidate, "hasDefinition") && $candidate->hasDefinition($plugin_id)) {
        if ($manager !== NULL) {
          throw new \RuntimeException("Multiple function-call plugin managers matched.");
        }
        $manager = $candidate;
        $manager_id = $service_id;
      }
    }
    catch (\Throwable $e) {
      if (str_contains($e->getMessage(), "Multiple function-call")) {
        throw $e;
      }
    }
  }
  if ($manager === NULL) {
    throw new \RuntimeException("Could not locate the AI function-call plugin manager.");
  }

  $role_id = "ai_playwright_pilot";
  $role = Role::load($role_id) ?: Role::create(["id" => $role_id, "label" => "AI Playwright pilot"]);
  $role->grantPermission("use ai playwright");
  $role->save();

  $denied = User::create([
    "name" => "ai_playwright_denied",
    "mail" => "ai-playwright-denied@example.test",
    "status" => 1,
  ]);
  $denied->save();

  $allowed = User::create([
    "name" => "ai_playwright_allowed",
    "mail" => "ai-playwright-allowed@example.test",
    "status" => 1,
    "roles" => [$role_id],
  ]);
  $allowed->save();

  $switcher = $container->get("account_switcher");

  $switcher->switchTo($denied);
  try {
    $plugin = $manager->createInstance($plugin_id);
    $plugin->setContextValue("url", "/fr/mentions-legales");
    $plugin->setContextValue("task", "permission-denied-proof");
    $plugin->execute();
    throw new \RuntimeException("Plugin unexpectedly executed without permission.");
  }
  catch (\Throwable $e) {
    if (!str_contains($e->getMessage(), "do not have permission")) {
      throw $e;
    }
  }
  finally {
    $switcher->switchBack();
  }

  $switcher->switchTo($allowed);
  try {
    $plugin = $manager->createInstance($plugin_id);
    $plugin->setContextValue("url", "/en/legal-notices");
    $plugin->setContextValue("task", "least-privilege-proof");
    $plugin->execute();
    $output = $plugin->getReadableOutput();
    if (!str_contains($output, "Opened:") || !str_contains($output, "Title:")) {
      throw new \RuntimeException("Allowed plugin output is incomplete.");
    }
    echo json_encode([
      "manager_service" => $manager_id,
      "denied_without_permission" => TRUE,
      "allowed_with_dedicated_permission" => TRUE,
      "allowed_output" => $output,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }
  finally {
    $switcher->switchBack();
  }
' > "$ARTIFACT_DIR/permission-proof.json"

jq -e '.denied_without_permission == true and .allowed_with_dedicated_permission == true' "$ARTIFACT_DIR/permission-proof.json" >/dev/null

# Record only non-sensitive, reproducible evidence. The one-time login URL is
# never emitted by the trusted script or copied into artifacts.
after_composer="$(sha256sum composer.json | awk '{print $1}')"
after_lock="$(sha256sum composer.lock | awk '{print $1}')"

jq -n \
  --arg status 'PASS' \
  --arg package "$EXPECTED_PACKAGE" \
  --arg version "$installed_version" \
  --arg fr_path "$FR_PATH" \
  --arg en_path "$EN_PATH" \
  --arg before_composer "$before_composer" \
  --arg after_composer "$after_composer" \
  --arg before_lock "$before_lock" \
  --arg after_lock "$after_lock" \
  '{
    status:$status,
    package:$package,
    version:$version,
    pages:[$fr_path,$en_path],
    same_site_only:true,
    external_urls_allowed:false,
    permission_gate:true,
    provider_required:false,
    workspace_dependency_was_ephemeral:true,
    composer_hashes:{before:$before_composer,after:$after_composer},
    lock_hashes:{before:$before_lock,after:$after_lock}
  }' > "$ARTIFACT_DIR/result.json"

jq -e '.status == "PASS"' "$ARTIFACT_DIR/result.json" >/dev/null
