#!/usr/bin/env bash
set -euo pipefail

ARTIFACT_DIR="artifacts/governed-content-cli-facade"
mkdir -p "$ARTIFACT_DIR"

snapshot_state() {
  local destination="$1"

  ddev drush php:eval '
    $database = \Drupal::database();
    $mappings = [];
    if ($database->schema()->tableExists("emerging_digital_content_sync_mapping")) {
      $rows = $database
        ->select("emerging_digital_content_sync_mapping", "m")
        ->fields("m")
        ->orderBy("content_id")
        ->execute()
        ->fetchAll();
      foreach ($rows as $row) {
        $mappings[] = (array) $row;
      }
    }

    $catalogPath = DRUPAL_ROOT
      . "/modules/custom/emerging_digital_content/content_sync/catalog.yml";
    $catalog = \Drupal\Component\Serialization\Yaml::decode(
      (string) file_get_contents($catalogPath),
    );
    $repository = \Drupal::service("entity.repository");
    $aliasManager = \Drupal::service("path_alias.manager");
    $nodes = [];

    foreach (($catalog["contents"] ?? []) as $entry) {
      $uuid = (string) ($entry["legacy_uuid"] ?? "");
      $node = $uuid !== ""
        ? $repository->loadEntityByUuid("node", $uuid)
        : NULL;
      $translations = [];

      if ($node instanceof \Drupal\node\NodeInterface) {
        foreach ($node->getTranslationLanguages() as $langcode => $language) {
          unset($language);
          $translation = $node->getTranslation($langcode);
          $translations[$langcode] = [
            "title" => $translation->label(),
            "published" => $translation->isPublished(),
            "changed" => $translation->getChangedTime(),
            "alias" => $aliasManager->getAliasByPath(
              "/node/" . $node->id(),
              $langcode,
            ),
          ];
        }
        ksort($translations);
      }

      $nodes[] = [
        "content_id" => (string) ($entry["id"] ?? ""),
        "legacy_uuid" => $uuid,
        "entity_id" => $node?->id(),
        "entity_uuid" => $node?->uuid(),
        "revision_id" => $node?->getRevisionId(),
        "translations" => $translations,
      ];
    }

    usort(
      $nodes,
      static fn (array $left, array $right): int =>
        $left["content_id"] <=> $right["content_id"],
    );

    echo json_encode(
      ["mappings" => $mappings, "nodes" => $nodes],
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
  ' | jq -S '.' > "$destination"
}

capture_command() {
  local name="$1"
  shift

  set +e
  "$@" > "$ARTIFACT_DIR/${name}.raw.txt" 2>&1
  local status=$?
  set -e

  tr -d '\r' < "$ARTIFACT_DIR/${name}.raw.txt" \
    > "$ARTIFACT_DIR/${name}.txt"
  printf '%s' "$status"
}

compare_outputs() {
  local left="$1"
  local right="$2"
  local diff_file="$3"

  if diff -u "$left" "$right" > "$diff_file"; then
    return 0
  fi

  return 1
}

baseline="$ARTIFACT_DIR/state-baseline.json"
snapshot_state "$baseline"

old_help_status="$(capture_command help-old ddev drush help emerging:content-sync)"
new_help_status="$(capture_command help-new ddev drush help emerging:governed-content)"
old_validate_help_status="$(capture_command help-validate-old ddev drush help emerging:content-sync:validate)"
new_validate_help_status="$(capture_command help-validate-new ddev drush help emerging:governed-content:validate)"

old_validate_status="$(capture_command validate-old ddev drush emerging:content-sync:validate)"
snapshot_state "$ARTIFACT_DIR/state-after-validate-old.json"

new_validate_status="$(capture_command validate-new ddev drush emerging:governed-content:validate)"
snapshot_state "$ARTIFACT_DIR/state-after-validate-new.json"

old_dry_run_status="$(capture_command dry-run-old ddev drush emerging:content-sync --all --dry-run)"
snapshot_state "$ARTIFACT_DIR/state-after-dry-run-old.json"

new_dry_run_status="$(capture_command dry-run-new ddev drush emerging:governed-content --all --dry-run)"
snapshot_state "$ARTIFACT_DIR/state-after-dry-run-new.json"

validate_outputs_equal=false
if compare_outputs \
  "$ARTIFACT_DIR/validate-old.txt" \
  "$ARTIFACT_DIR/validate-new.txt" \
  "$ARTIFACT_DIR/validate.diff"; then
  validate_outputs_equal=true
fi

dry_run_outputs_equal=false
if compare_outputs \
  "$ARTIFACT_DIR/dry-run-old.txt" \
  "$ARTIFACT_DIR/dry-run-new.txt" \
  "$ARTIFACT_DIR/dry-run.diff"; then
  dry_run_outputs_equal=true
fi

state_unchanged=true
for state_file in \
  "$ARTIFACT_DIR/state-after-validate-old.json" \
  "$ARTIFACT_DIR/state-after-validate-new.json" \
  "$ARTIFACT_DIR/state-after-dry-run-old.json" \
  "$ARTIFACT_DIR/state-after-dry-run-new.json"; do
  diff_name="$(basename "$state_file" .json).diff"
  if ! compare_outputs \
    "$baseline" \
    "$state_file" \
    "$ARTIFACT_DIR/$diff_name"; then
    state_unchanged=false
  fi
done

success=false
if [[ "$old_help_status" -eq 0 \
  && "$new_help_status" -eq 0 \
  && "$old_validate_help_status" -eq 0 \
  && "$new_validate_help_status" -eq 0 \
  && "$old_validate_status" -eq 0 \
  && "$new_validate_status" -eq 0 \
  && "$old_dry_run_status" -eq 0 \
  && "$new_dry_run_status" -eq 0 \
  && "$validate_outputs_equal" == true \
  && "$dry_run_outputs_equal" == true \
  && "$state_unchanged" == true ]]; then
  success=true
fi

jq -n \
  --arg head_sha "$(git rev-parse HEAD)" \
  --arg old_sync "emerging:content-sync" \
  --arg new_sync "emerging:governed-content" \
  --arg old_validate "emerging:content-sync:validate" \
  --arg new_validate "emerging:governed-content:validate" \
  --argjson old_help_status "$old_help_status" \
  --argjson new_help_status "$new_help_status" \
  --argjson old_validate_help_status "$old_validate_help_status" \
  --argjson new_validate_help_status "$new_validate_help_status" \
  --argjson old_validate_status "$old_validate_status" \
  --argjson new_validate_status "$new_validate_status" \
  --argjson old_dry_run_status "$old_dry_run_status" \
  --argjson new_dry_run_status "$new_dry_run_status" \
  --argjson validate_outputs_equal "$validate_outputs_equal" \
  --argjson dry_run_outputs_equal "$dry_run_outputs_equal" \
  --argjson state_unchanged "$state_unchanged" \
  --argjson success "$success" \
  '{
    head_sha: $head_sha,
    commands: {
      sync: {old: $old_sync, new: $new_sync},
      validate: {old: $old_validate, new: $new_validate}
    },
    discovery: {
      old_sync_help_status: $old_help_status,
      new_sync_help_status: $new_help_status,
      old_validate_help_status: $old_validate_help_status,
      new_validate_help_status: $new_validate_help_status
    },
    validate: {
      old_status: $old_validate_status,
      new_status: $new_validate_status,
      outputs_equal: $validate_outputs_equal
    },
    dry_run: {
      old_status: $old_dry_run_status,
      new_status: $new_dry_run_status,
      outputs_equal: $dry_run_outputs_equal,
      governed_state_unchanged: $state_unchanged
    },
    success: $success
  }' > "$ARTIFACT_DIR/result.json"

cat "$ARTIFACT_DIR/result.json"

if [[ "$success" != true ]]; then
  echo "Governed Content CLI runtime parity proof failed." >&2
  exit 1
fi
