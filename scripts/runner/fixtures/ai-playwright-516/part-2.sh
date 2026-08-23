# Least-privilege actor used by both ai_playwright and the bounded mutation tool.
ddev drush php:eval '
  use Drupal\user\Entity\Role;
  use Drupal\user\Entity\User;
  $role_id = "agency_516_agent";
  $role = Role::load($role_id) ?: Role::create(["id" => $role_id, "label" => "Agency #516 agent"]);
  $role->grantPermission("use ai playwright");
  $role->grantPermission("use agency 516 bounded canvas mutation");
  $role->save();
  $existing = user_load_by_name("agency_516_agent");
  if (!$existing) {
    $user = User::create([
      "name" => "agency_516_agent",
      "mail" => "agency-516-agent@example.test",
      "status" => 1,
      "roles" => [$role_id],
    ]);
    $user->save();
  }
'

# Persist a config-agent only inside the disposable site. It exposes exactly two tools.
ddev drush php:eval '
  $storage = \Drupal::entityTypeManager()->getStorage("ai_agent");
  if ($old = $storage->load("agency_516_governed_loop")) {
    $old->delete();
  }
  $agent = $storage->create([
    "id" => "agency_516_governed_loop",
    "label" => "Agency #516 governed loop",
    "description" => "Ephemeral deterministic DEV-ONLY proof agent.",
    "system_prompt" => "Execute only the scripted #516 proof tools. Never navigate externally or perform any other mutation.",
    "secured_system_prompt" => "",
    "default_information_tools" => "",
    "tools" => [
      "ai_playwright:browser_preview" => TRUE,
      "agency_ai_playwright_516_test:bounded_canvas_heading" => TRUE,
    ],
    "tool_settings" => [
      "ai_playwright:browser_preview" => ["return_directly" => 0, "require_usage" => 0, "description_override" => "", "progress_message" => "", "use_artifacts" => 0],
      "agency_ai_playwright_516_test:bounded_canvas_heading" => ["return_directly" => 0, "require_usage" => 0, "description_override" => "", "progress_message" => "", "use_artifacts" => 0],
    ],
    "tool_usage_limits" => [
      "ai_playwright:browser_preview" => [],
      "agency_ai_playwright_516_test:bounded_canvas_heading" => [],
    ],
    "orchestration_agent" => FALSE,
    "triage_agent" => FALSE,
    "max_loops" => 8,
    "max_loops_message" => "#516 deterministic loop exceeded its bounded maximum.",
    "masquerade_roles" => [],
    "exclude_users_role" => FALSE,
    "structured_output_enabled" => FALSE,
    "structured_output_schema" => "",
    "hostname_filter_disabled" => FALSE,
    "guardrail_set" => "",
  ]);
  $agent->save();
'

# Baseline sanity before the agent starts. Canvas content component inputs are
# stored as JSON in ComponentTreeItem storage; decode that upstream format
# fail-closed instead of inventing a parallel Agency representation.
ddev drush php:eval '
  $ids = \Drupal::entityQuery("canvas_page")->accessCheck(FALSE)->condition("uuid", "52600000-0000-4000-8000-000000000001")->execute();
  if (count($ids) !== 1) { throw new \RuntimeException("Baseline page not found uniquely."); }
  $page = \Drupal::entityTypeManager()->getStorage("canvas_page")->load(reset($ids));
  $values = $page->get("components")->getValue();
  $ids_seen = [];
  $heading = NULL;
  foreach ($values as $component) {
    $ids_seen[] = $component["component_id"] ?? NULL;
    if (($component["uuid"] ?? NULL) === "52600000-0000-4000-8000-000000000103") {
      if (($component["component_id"] ?? NULL) !== "sdc.emerging_digital.cta") {
        throw new \RuntimeException("Fixed CTA UUID no longer identifies the approved CTA.");
      }
      $rawInputs = $component["inputs"] ?? NULL;
      $inputs = is_string($rawInputs) ? json_decode($rawInputs, TRUE, 512, JSON_THROW_ON_ERROR) : $rawInputs;
      if (!is_array($inputs)) { throw new \RuntimeException("Canvas CTA inputs are not a valid mapping."); }
      $heading = $inputs["heading"] ?? NULL;
    }
  }
  if ($ids_seen !== ["sdc.emerging_digital.hero", "sdc.emerging_digital.trust-list", "sdc.emerging_digital.cta"]) {
    throw new \RuntimeException("Baseline component allowlist/order changed.");
  }
  if (!is_string($heading) || trim($heading) === "") {
    throw new \RuntimeException("Baseline CTA heading is missing or invalid.");
  }
  if ($heading === "Composition Canvas bornée — vérification agentique") {
    throw new \RuntimeException("Baseline CTA is already in the temporary proof state.");
  }
  echo json_encode(["component_ids" => $ids_seen, "heading" => $heading], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
' > "$ARTIFACT_DIR/baseline-before.json"
ORIGINAL_HEADING="$(jq -er '.heading | select(type == "string" and length > 0)' "$ARTIFACT_DIR/baseline-before.json")"
