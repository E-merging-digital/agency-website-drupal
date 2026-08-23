#!/usr/bin/env bash
set -euo pipefail

: "${TARGET_DIR:?TARGET_DIR is required}"
: "${ARTIFACT_DIR:?ARTIFACT_DIR is required}"

EXPECTED_PACKAGE='drupal/ai_context'
EXPECTED_VERSION='1.0.0-beta3'
ARTIFACT_REL='artifacts/ai-context-387-pilot'

mkdir -p "$ARTIFACT_DIR"
ARTIFACT_DIR="$(cd "$ARTIFACT_DIR" && pwd)"
TARGET_DIR="$(cd "$TARGET_DIR" && pwd)"

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
    --arg verdict 'BLOCKED_BY_RELEASED_API' \
    --arg phase "$phase" \
    --arg message "$message" \
    '{status:$status,verdict:$verdict,phase:$phase,message:$message}' \
    > "$ARTIFACT_DIR/result.json"
}

on_exit() {
  local rc=$?
  trap - EXIT
  rm -f .agency573-audit.php
  if [[ $rc -ne 0 && ! -s "$ARTIFACT_DIR/result.json" ]]; then
    write_failure 'unexpected' "Trusted CCC beta3 pilot failed with exit code $rc"
  fi
  exit "$rc"
}
trap on_exit EXIT

command -v ddev >/dev/null
command -v jq >/dev/null
command -v git >/dev/null
command -v sha256sum >/dev/null
command -v openssl >/dev/null

test -f composer.json
test -f composer.lock
test -f .ddev/config.yaml
test -f docs/drupal-ai-context-architecture.md

if grep -q '"drupal/ai_context"' composer.json; then
  write_failure 'preflight' 'ai_context must not be a production Composer dependency.'
  exit 1
fi

git diff --check
before_composer="$(sha256sum composer.json | awk '{print $1}')"
before_lock="$(sha256sum composer.lock | awk '{print $1}')"

# Beta dependency is deliberately workspace-only. The workflow always deletes
# the isolated DDEV project and checkout after uploading evidence.
if ! ddev composer require "${EXPECTED_PACKAGE}:${EXPECTED_VERSION}" \
  --with-all-dependencies --no-interaction --no-progress; then
  write_failure 'composer' "Unable to resolve ${EXPECTED_PACKAGE}:${EXPECTED_VERSION} in isolated DDEV."
  exit 1
fi

installed_version="$(
  ddev composer show "$EXPECTED_PACKAGE" --format=json |
    jq -r '.versions[]' |
    sed 's/^\* //' |
    head -n 1
)"
if [[ "$installed_version" != "$EXPECTED_VERSION" ]]; then
  write_failure 'composer' "Expected $EXPECTED_VERSION, got $installed_version"
  exit 1
fi

ddev composer show "$EXPECTED_PACKAGE" --format=json > "$ARTIFACT_DIR/package.json"
ddev composer show drupal/ai --format=json > "$ARTIFACT_DIR/ai-package.json"

after_composer="$(sha256sum composer.json | awk '{print $1}')"
after_lock="$(sha256sum composer.lock | awk '{print $1}')"
mapfile -t workspace_changes < <(git diff --name-only)

jq -n \
  --arg before_composer "$before_composer" \
  --arg after_composer "$after_composer" \
  --arg before_lock "$before_lock" \
  --arg after_lock "$after_lock" \
  --argjson workspace_changes "$(printf '%s\n' "${workspace_changes[@]}" | jq -R . | jq -s .)" \
  '{
    production_persisted:false,
    workspace_only:true,
    composer_json:{before:$before_composer,after:$after_composer,changed:($before_composer != $after_composer)},
    composer_lock:{before:$before_lock,after:$after_lock,changed:($before_lock != $after_lock)},
    git_changed_files:$workspace_changes
  }' > "$ARTIFACT_DIR/composer-integrity.json"

admin_pass="$(openssl rand -hex 24)"
ddev drush site:install --existing-config -y --account-pass="$admin_pass"
unset admin_pass

ddev drush cim -y
ddev drush cr
ddev drush emerging:governed-content --all

if ! ddev drush en ai_context -y; then
  write_failure 'module-enable' 'Unable to enable ai_context beta3 in isolated DDEV.'
  exit 1
fi
ddev drush cr

cat > .agency573-audit.php <<'PHP'
<?php

declare(strict_types=1);

use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\Yaml\Yaml;

$artifactDir = '/var/www/html/artifacts/ai-context-387-pilot';

$write = static function (string $name, array $data) use ($artifactDir): void {
  file_put_contents(
    $artifactDir . '/' . $name,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
  );
};

$entityTypeManager = \Drupal::entityTypeManager();
$entityFieldManager = \Drupal::service('entity_field.manager');

if (!$entityTypeManager->hasDefinition('ai_context_item')) {
  $write('result.json', [
    'status' => 'FAIL',
    'verdict' => 'BLOCKED_BY_RELEASED_API',
    'phase' => 'entity-contract',
    'message' => 'ai_context_item entity type is unavailable after enabling beta3.',
  ]);
  exit(1);
}

$entityType = $entityTypeManager->getDefinition('ai_context_item');
$baseFields = $entityFieldManager->getBaseFieldDefinitions('ai_context_item');

$fieldReport = [];
foreach ($baseFields as $name => $definition) {
  $fieldReport[$name] = [
    'type' => $definition->getType(),
    'required' => $definition->isRequired(),
    'translatable' => $definition->isTranslatable(),
    'revisionable' => method_exists($definition, 'isRevisionable') ? $definition->isRevisionable() : null,
    'read_only' => $definition->isReadOnly(),
  ];
}

$entityContract = [
  'entity_type' => 'ai_context_item',
  'class' => $entityType->getClass(),
  'keys' => $entityType->getKeys(),
  'revisionable' => $entityType->isRevisionable(),
  'translatable' => $entityType->isTranslatable(),
  'revision_table' => $entityType->getRevisionTable(),
  'data_table' => $entityType->getDataTable(),
  'fields' => $fieldReport,
];
$write('entity-contract.json', $entityContract);

$permissions = \Drupal::service('user.permissions')->getPermissions();
$contextPermissions = [];
foreach ($permissions as $permission => $info) {
  $provider = (string) ($info['provider'] ?? '');
  if ($provider === 'ai_context' || str_contains($permission, 'context')) {
    $contextPermissions[$permission] = [
      'provider' => $provider,
      'title' => isset($info['title']) ? (string) $info['title'] : '',
      'restrict_access' => (bool) ($info['restrict access'] ?? false),
    ];
  }
}
ksort($contextPermissions);
$write('permissions.json', $contextPermissions);

$modulePath = DRUPAL_ROOT . '/modules/contrib/ai_context';
$servicesFile = $modulePath . '/ai_context.services.yml';
$serviceReport = [];
if (is_file($servicesFile)) {
  $parsed = Yaml::parseFile($servicesFile);
  foreach (($parsed['services'] ?? []) as $serviceId => $definition) {
    if (!is_string($serviceId) || !str_starts_with($serviceId, 'ai_context.')) {
      continue;
    }
    $class = is_array($definition) ? ($definition['class'] ?? null) : null;
    $internal = null;
    if (is_string($class) && class_exists($class)) {
      $reflection = new ReflectionClass($class);
      $doc = $reflection->getDocComment() ?: '';
      $internal = str_contains($doc, '@internal');
    }
    $serviceReport[$serviceId] = [
      'class' => $class,
      'internal' => $internal,
    ];
  }
}
ksort($serviceReport);

$eventFiles = [];
if (is_dir($modulePath . '/src/Event')) {
  foreach (glob($modulePath . '/src/Event/*Event.php') ?: [] as $file) {
    $eventFiles[] = basename($file);
  }
}
sort($eventFiles);

$retrieverCandidates = [
  'ai_context.retriever',
  'ai_context.context_retriever',
];
$publicRetriever = null;
foreach ($retrieverCandidates as $candidate) {
  if (!isset($serviceReport[$candidate])) {
    continue;
  }
  if ($serviceReport[$candidate]['internal'] === false) {
    $publicRetriever = $candidate;
    break;
  }
}

$write('services-api.json', [
  'services' => $serviceReport,
  'event_files' => $eventFiles,
  'public_non_agent_retriever' => $publicRetriever,
  'consumer_policy' => $publicRetriever
    ? 'PUBLIC_RETRIEVER_AVAILABLE'
    : 'NO_PROVEN_PUBLIC_NON_AGENT_RETRIEVER',
]);

$proof = [
  'created' => false,
  'revision_proved' => false,
  'translation_proved' => false,
  'deleted' => false,
  'id' => null,
  'label_key' => $entityType->getKey('label'),
  'fields_written' => [],
  'error' => null,
];

try {
  $storage = $entityTypeManager->getStorage('ai_context_item');
  $values = [];

  $labelKey = $entityType->getKey('label');
  if (is_string($labelKey) && $labelKey !== '') {
    $values[$labelKey] = 'Agency #573 brand.voice proof';
  }

  $langcodeKey = $entityType->getKey('langcode');
  if (is_string($langcodeKey) && $langcodeKey !== '') {
    $values[$langcodeKey] = 'fr';
  }

  $publishedKey = $entityType->getKey('published');
  if (is_string($publishedKey) && $publishedKey !== '') {
    $values[$publishedKey] = 1;
  }

  $sampleFr = 'Ton professionnel, calme, concret et vérifiable. Éviter les superlatifs non démontrés.';
  $sampleEn = 'Professional, calm, concrete and verifiable tone. Avoid unsupported superlatives.';

  foreach (['content', 'description', 'context', 'text', 'purpose'] as $candidate) {
    if (!isset($baseFields[$candidate])) {
      continue;
    }
    $type = $baseFields[$candidate]->getType();
    if (in_array($type, ['string', 'string_long', 'text', 'text_long', 'text_with_summary'], true)) {
      $values[$candidate] = $candidate === 'purpose'
        ? 'Ephemeral Agency #573 brand.voice compatibility proof.'
        : $sampleFr;
    }
  }

  foreach ($baseFields as $name => $definition) {
    if (!$definition->isRequired() || array_key_exists($name, $values) || $definition->isReadOnly()) {
      continue;
    }
    if (in_array($name, ['id', 'uuid', 'revision_id', 'created', 'changed'], true)) {
      continue;
    }
    $type = $definition->getType();
    if (in_array($type, ['string', 'string_long', 'text', 'text_long', 'text_with_summary'], true)) {
      $values[$name] = 'Agency #573 beta3 compatibility proof';
    }
    elseif ($type === 'boolean') {
      $values[$name] = true;
    }
    elseif ($type === 'entity_reference' && in_array($name, ['uid', 'user_id', 'owner'], true)) {
      $values[$name] = 1;
    }
  }

  $entity = $storage->create($values);
  if (!$entity instanceof FieldableEntityInterface) {
    throw new RuntimeException('ai_context_item is not fieldable.');
  }

  $violations = $entity->validate();
  if ($violations->count() > 0) {
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    throw new RuntimeException('Entity validation failed: ' . implode(' | ', $messages));
  }

  $entity->save();
  $proof['created'] = true;
  $proof['id'] = $entity->id();
  $proof['fields_written'] = array_keys($values);
  $initialRevision = method_exists($entity, 'getRevisionId') ? $entity->getRevisionId() : null;

  if ($entityType->isRevisionable()) {
    $entity->setNewRevision(true);
    if (method_exists($entity, 'setRevisionLogMessage')) {
      $entity->setRevisionLogMessage('Agency #573 revision proof');
    }
    $entity->save();
    $newRevision = method_exists($entity, 'getRevisionId') ? $entity->getRevisionId() : null;
    $proof['revision_proved'] = $initialRevision !== null
      && $newRevision !== null
      && (string) $initialRevision !== (string) $newRevision;
    $proof['initial_revision_id'] = $initialRevision;
    $proof['new_revision_id'] = $newRevision;
  }

  $languageManager = \Drupal::languageManager();
  if ($entityType->isTranslatable() && $languageManager->getLanguage('en')) {
    $translationValues = [];
    if (is_string($labelKey) && $labelKey !== '' && isset($baseFields[$labelKey]) && $baseFields[$labelKey]->isTranslatable()) {
      $translationValues[$labelKey] = 'Agency #573 brand.voice proof EN';
    }
    foreach (['content', 'description', 'context', 'text', 'purpose'] as $candidate) {
      if (!isset($baseFields[$candidate]) || !$baseFields[$candidate]->isTranslatable()) {
        continue;
      }
      $type = $baseFields[$candidate]->getType();
      if (in_array($type, ['string', 'string_long', 'text', 'text_long', 'text_with_summary'], true)) {
        $translationValues[$candidate] = $candidate === 'purpose'
          ? 'Ephemeral Agency #573 brand.voice compatibility proof.'
          : $sampleEn;
      }
    }

    $translation = $entity->addTranslation('en', $translationValues);
    $translation->save();
    $reloaded = $storage->loadUnchanged($entity->id());
    $proof['translation_proved'] = $reloaded !== null && $reloaded->hasTranslation('en');
  }

  $id = $entity->id();
  $entity->delete();
  $storage->resetCache([$id]);
  $proof['deleted'] = $storage->load($id) === null;
}
catch (Throwable $e) {
  $proof['error'] = $e->getMessage();
}
$write('brand-voice-proof.json', $proof);

$entityGovernancePass = $proof['created']
  && $proof['deleted']
  && (!$entityType->isRevisionable() || $proof['revision_proved'])
  && (!$entityType->isTranslatable() || $proof['translation_proved']);

if (!$entityGovernancePass) {
  $write('result.json', [
    'status' => 'FAIL',
    'verdict' => 'BLOCKED_BY_RELEASED_API',
    'phase' => 'brand-voice-proof',
    'message' => $proof['error'] ?? 'Released beta3 entity governance proof is incomplete.',
    'entity_governance' => $proof,
    'public_non_agent_retriever' => $publicRetriever,
  ]);
  exit(1);
}

$verdict = $publicRetriever ? 'PILOT_CONFIRMED' : 'WAIT_FOR_RC';
$write('result.json', [
  'status' => 'PASS',
  'verdict' => $verdict,
  'phase' => 'complete',
  'message' => $publicRetriever
    ? 'Released beta3 entity governance and a public non-agent retrieval facade were proved.'
    : 'Released beta3 entity governance was proved; no public non-agent retrieval facade was demonstrated, so Agency must wait for RC rather than wrap internal services.',
  'ai_context_version' => '1.0.0-beta3',
  'dev_only_ephemeral' => true,
  'provider_network_required' => false,
  'production_dependency_persisted' => false,
  'entity_governance' => $proof,
  'public_non_agent_retriever' => $publicRetriever,
  'stable_extension_events_observed' => $eventFiles,
]);
PHP

if ! ddev drush php:script /var/www/html/.agency573-audit.php; then
  if [[ ! -s "$ARTIFACT_DIR/result.json" ]]; then
    write_failure 'runtime-audit' 'CCC beta3 Drupal runtime audit failed.'
  fi
  exit 1
fi

jq -e '.status == "PASS"' "$ARTIFACT_DIR/result.json" >/dev/null
