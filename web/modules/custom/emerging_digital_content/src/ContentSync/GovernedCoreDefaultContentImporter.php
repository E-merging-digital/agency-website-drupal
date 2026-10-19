<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DefaultContent\Existing;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Imports repository-owned core Default Content used by governed composition.
 */
final class GovernedCoreDefaultContentImporter {

  private const CANVAS_PAGE_UUID = '52600000-0000-4000-8000-000000000001';

  private const HERO_OLD = 'f6ebbdd7632bea80';

  private const CANVAS_INSTANCE_UUIDS = [
    '52600000-0000-4000-8000-000000000101',
    '52600000-0000-4000-8000-000000000102',
    '52600000-0000-4000-8000-000000000103',
  ];

  private const CANVAS_INPUT_KEYS = [
    ['heading', 'heading_level', 'variant'],
    ['heading', 'items'],
    ['heading', 'heading_level'],
  ];

  private const CANVAS_COMPONENT_VERSIONS = [
    'sdc.emerging_digital.hero' => '8172164706c8dc5f',
    'sdc.emerging_digital.trust-list' => '61b25a47f367765e',
    'sdc.emerging_digital.cta' => 'b4f165563eb02357',
  ];

  public function __construct(
    private readonly Importer $importer,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly string $appRoot,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Imports governed core Default Content and verifies the Canvas baseline.
   *
   * @return string[]
   *   Audit messages for the Governed Content report.
   */
  public function import(): array {
    $directory = $this->appRoot
      . '/modules/custom/emerging_digital_content/core_default_content';
    $finder = new Finder($directory);

    if ($finder->data === []) {
      throw new \RuntimeException(sprintf(
        'Governed core Default Content is empty at "%s".',
        $directory,
      ));
    }

    try {
      $this->importer->importContent($finder, Existing::Skip);
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException(
        'Governed core Default Content import failed: '
        . $exception->getMessage(),
        0,
        $exception,
      );
    }

    $page = $this->entityRepository->loadEntityByUuid(
      'canvas_page',
      self::CANVAS_PAGE_UUID,
    );
    if (!$page instanceof ContentEntityInterface) {
      throw new \RuntimeException(
        'Governed Canvas proof page was not imported by Drupal core.',
      );
    }

    $page = $this->convergeKnownCanvasHero($page);

    $violations = $page->validate();
    if (count($violations) > 0) {
      throw new \RuntimeException(sprintf(
        'Governed Canvas proof page is invalid after import: %s',
        (string) $violations,
      ));
    }

    $resolved = [];
    foreach ($page->get('components')->getValue() as $component) {
      $component_id = (string) ($component['component_id'] ?? '');
      $component_version = (string) ($component['component_version'] ?? '');
      if ($component_id !== '') {
        $resolved[$component_id] = $component_version;
      }
    }

    if ($resolved !== self::CANVAS_COMPONENT_VERSIONS) {
      throw new \RuntimeException(sprintf(
        'Canvas did not resolve the governed component versions as expected: %s',
        json_encode($resolved, JSON_THROW_ON_ERROR),
      ));
    }

    return [
      'core default content: governed Canvas proof page imported or retained',
      'core default content: Canvas component versions resolved from approved catalog',
    ];
  }

  /**
   * Fixes only the proven old Hero marker on the fixed governed proof page.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The unchanged or explicitly migrated proof page.
   */
  private function convergeKnownCanvasHero(ContentEntityInterface $page): ContentEntityInterface {
    if ($page->uuid() !== self::CANVAS_PAGE_UUID) {
      throw new \RuntimeException('Unexpected governed Canvas page UUID.');
    }
    $before = $page->get('components')->getValue();
    $after = self::planHeroVersionUpdate($before);
    if ($after === NULL) {
      return $page;
    }

    $config = $this->configFactory->get('canvas.component.sdc.emerging_digital.hero');
    self::assertHeroSchemaCompatible($config->getRawData());
    $storage = $this->entityTypeManager->getStorage('canvas_page');
    $current = $storage->loadUnchanged($page->id());
    if (!$current instanceof ContentEntityInterface
      || $current->uuid() !== self::CANVAS_PAGE_UUID
      || $current->getRevisionId() !== $page->getRevisionId()
      || $current->toArray() !== $page->toArray()
      || $current->get('components')->getValue() !== $before) {
      throw new \RuntimeException('Concurrent Canvas page change; Hero update refused.');
    }

    // Native typed-field mutation, never entity replacement or content reset.
    $current->get('components')->setValue($after);
    $violations = $current->validate();
    if (count($violations) !== 0) {
      throw new \RuntimeException('Updated Canvas page failed native entity validation.');
    }
    $current->save();
    $reloaded = $storage->loadUnchanged($current->id());
    if (!$reloaded instanceof ContentEntityInterface
      || $reloaded->uuid() !== self::CANVAS_PAGE_UUID
      || $reloaded->get('components')->getValue() !== $after) {
      throw new \RuntimeException('Persisted Canvas Hero tree differs from the approved single-marker update.');
    }
    return $reloaded;
  }

  /**
   * Plans a single version-marker update, or NULL for an already-new page.
   *
   * @param array<mixed> $components
   *   Native Canvas tree values.
   *
   * @return array<mixed>|null
   *   Original tree with only Hero version changed, or NULL.
   */
  private static function planHeroVersionUpdate(array $components): ?array {
    if (array_keys($components) !== [0, 1, 2]) {
      throw new \RuntimeException('Unexpected Canvas proof tree length or ordering.');
    }
    foreach (array_keys(self::CANVAS_COMPONENT_VERSIONS) as $n => $id) {
      $item = $components[$n];
      if (!is_array($item)
        || ($item['uuid'] ?? NULL) !== self::CANVAS_INSTANCE_UUIDS[$n]
        || ($item['component_id'] ?? NULL) !== $id
        || array_diff(array_keys($item), ['uuid', 'component_id', 'component_version', 'inputs', 'parent_uuid', 'slot']) !== []
        || !empty($item['parent_uuid']) || !empty($item['slot'])) {
        throw new \RuntimeException('Unexpected Canvas component or nested layout.');
      }
      $inputs = $item['inputs'] ?? NULL;
      if (is_string($inputs)) {
        try {
          $inputs = json_decode($inputs, TRUE, 512, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException) {
          throw new \RuntimeException('Invalid Canvas component input JSON.');
        }
      }
      if (!is_array($inputs)) {
        throw new \RuntimeException('Invalid Canvas input shape.');
      }
      $keys = array_keys($inputs);
      $expected_keys = self::CANVAS_INPUT_KEYS[$n];
      sort($keys);
      sort($expected_keys);
      if ($keys !== $expected_keys) {
        throw new \RuntimeException('Unexpected Canvas component input keys.');
      }
      $version = $item['component_version'] ?? NULL;
      if ($version !== self::CANVAS_COMPONENT_VERSIONS[$id]
        && !($n === 0 && $version === self::HERO_OLD)) {
        throw new \RuntimeException('Unapproved Canvas component version.');
      }
    }
    if ($components[0]['component_version'] !== self::HERO_OLD) {
      return NULL;
    }
    $components[0]['component_version'] = self::CANVAS_COMPONENT_VERSIONS['sdc.emerging_digital.hero'];
    return $components;
  }

  /**
   * Requires effective archived/active property and slot schemas to agree.
   *
   * @param array<string, mixed> $config
   *   Active Drupal configuration, not a YAML file from the repository.
   */
  private static function assertHeroSchemaCompatible(array $config): void {
    $versions = $config['versioned_properties'] ?? NULL;
    if (($config['id'] ?? NULL) !== 'sdc.emerging_digital.hero'
      || ($config['source'] ?? NULL) !== 'sdc'
      || ($config['source_local_id'] ?? NULL) !== 'emerging_digital:hero'
      || ($config['active_version'] ?? NULL) !== self::CANVAS_COMPONENT_VERSIONS['sdc.emerging_digital.hero']
      || !is_array($versions)
      || array_keys($versions) !== ['active', self::HERO_OLD]
      || !is_array($versions['active'])
      || !is_array($versions[self::HERO_OLD])
      || !isset($versions['active']['settings']['prop_field_definitions'])
      || !isset($versions['active']['fallback_metadata']['slot_definitions'])
      || self::normalizeMap($versions['active']) !== self::normalizeMap($versions[self::HERO_OLD])) {
      throw new \RuntimeException('Effective Canvas Hero schemas differ; no safe marker-only upgrade.');
    }
  }

  /**
   * Normalizes maps, retaining strict scalar and ordered-list semantics.
   */
  private static function normalizeMap(array $value): array {
    if (!array_is_list($value)) {
      ksort($value);
    }
    foreach ($value as &$child) {
      if (is_array($child)) {
        $child = self::normalizeMap($child);
      }
    }
    unset($child);
    return $value;
  }

}
