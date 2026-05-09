<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalog;
use Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalogEntry;
use Drupal\emerging_digital_content\ContentSync\Catalog\Exception\ContentSyncCatalogException;
use Drupal\emerging_digital_content\ContentSync\Entity\ContentSyncMappingRecord;
use Drupal\emerging_digital_content\ContentSync\Loader\ContentSyncCatalogLoader;
use Drupal\emerging_digital_content\ContentSync\Repository\ContentSyncMappingRepository;
use Drupal\emerging_digital_content\ContentSync\Validator\ContentSyncCatalogValidator;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Builds Content Sync catalog reports and applies catalog writes.
 */
final class ContentSyncManager {

  private const PRUNE_UNPUBLISH = 'unpublish';

  public function __construct(
    private readonly ContentSyncCatalogLoader $catalogLoader,
    private readonly ContentSyncCatalogValidator $catalogValidator,
    private readonly ContentSyncMappingRepository $mappingRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly AliasManagerInterface $aliasManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Synchronizes catalog content items, or previews them in dry-run mode.
   *
   * @return array<string, mixed>
   *   A structured Content Sync report.
   */
  public function sync(string $content_id = '', bool $dry_run = TRUE, bool $all = FALSE, string $prune = ''): array {
    if ($all && $content_id !== '') {
      throw new \InvalidArgumentException('Content Sync cannot combine --all with a targeted content id.');
    }

    if ($prune !== '' && !$all) {
      throw new \InvalidArgumentException('Content Sync prune mode requires --all.');
    }

    if ($prune !== '' && $prune !== self::PRUNE_UNPUBLISH) {
      throw new \InvalidArgumentException(sprintf(
        'Unsupported Content Sync prune mode "%s". Only "unpublish" is available.',
        $prune,
      ));
    }

    if ($dry_run) {
      return $this->dryRun($content_id !== '' ? $content_id : NULL, $prune);
    }

    if ($all) {
      return $this->applyAll($prune);
    }

    if ($content_id === '') {
      throw new \InvalidArgumentException('Content Sync apply mode requires one targeted content id.');
    }

    return $this->applyTargeted($content_id);
  }

  /**
   * Validates the full catalog.
   *
   * @return array<string, mixed>
   *   A structured validation report.
   */
  public function validateCatalog(): array {
    try {
      $catalog = $this->catalogLoader->load();
    }
    catch (ContentSyncCatalogException $exception) {
      return [
        'contents_found' => 0,
        'valid_contents' => [],
        'invalid_contents' => [],
        'warnings' => [],
        'errors' => [$exception->getMessage()],
      ];
    }

    return $this->catalogValidator->validate($catalog);
  }

  /**
   * Builds a read-only dry-run report for the catalog or one content item.
   *
   * @return array<string, mixed>
   *   A structured dry-run report.
   */
  public function dryRun(?string $content_id = NULL, string $prune = ''): array {
    try {
      $catalog = $this->catalogLoader->load();
      $report = $this->catalogValidator->validate($catalog);
    }
    catch (ContentSyncCatalogException $exception) {
      return [
        'dry_run' => TRUE,
        'contents_found' => 0,
        'valid_contents' => [],
        'invalid_contents' => [],
        'warnings' => [],
        'errors' => [$exception->getMessage()],
        'actions' => [],
        'menus_touched' => FALSE,
      ];
    }

    $entries = [];
    if ($content_id !== NULL) {
      $entry = $catalog->get($content_id);
      if ($entry === NULL) {
        if (!isset($report['errors']) || !is_array($report['errors'])) {
          $report['errors'] = [];
        }
        $report['errors'][] = sprintf('Unknown content id "%s".', $content_id);
      }
      else {
        $entries[] = $entry;
      }
    }
    else {
      $entries = $catalog->entries();
    }

    $report['dry_run'] = TRUE;
    $report['actions'] = [];
    $report['menus_touched'] = FALSE;

    foreach ($entries as $entry) {
      $definition = $entry->toArray();
      $mapping = $this->mappingRepository->findByContentId($entry->id());
      $translations = is_array($definition['translations'] ?? NULL)
        ? $definition['translations']
        : [];
      $catalog_hash = $this->catalogHash($definition);

      $report['actions'][] = sprintf(
        'read %s "%s" from catalog: %s',
        (string) ($definition['entity_type'] ?? 'unknown'),
        (string) ($definition['bundle'] ?? 'unknown'),
        $entry->id(),
      );
      $report['actions'][] = $mapping === NULL
        ? sprintf(
          'mapping lookup for %s: no managed Drupal entity is registered yet (catalog hash %s)',
          $entry->id(),
          $catalog_hash,
        )
        : sprintf(
          'mapping lookup for %s: registered %s:%s, langcode %s, status %s, last action %s',
          $entry->id(),
          $mapping->entityType(),
          (string) ($mapping->entityId() ?? 'unknown'),
          $mapping->langcode() !== '' ? $mapping->langcode() : 'unknown',
          $mapping->status(),
          $mapping->lastAction() !== '' ? $mapping->lastAction() : 'none',
        );
      $report['actions'][] = sprintf(
        'mapping write skipped for %s: dry-run remains read-only',
        $entry->id(),
      );
      $report['actions'][] = sprintf(
        'validate translations for %s: %s',
        $entry->id(),
        implode(', ', array_keys($translations)),
      );

      foreach ($translations as $langcode => $translation) {
        if (is_array($translation) && isset($translation['alias'])) {
          $report['actions'][] = sprintf(
            'dry-run alias %s: %s',
            (string) $langcode,
            (string) $translation['alias'],
          );
        }
      }

      if (isset($definition['promotions']) && is_array($definition['promotions'])) {
        $report['actions'][] = sprintf(
          'read promotion definitions for %s: %d',
          $entry->id(),
          count($definition['promotions']),
        );
      }

      $report['actions'][] = sprintf(
        'read-only check for %s: no Drupal entity or mapping writes are executed',
        $entry->id(),
      );
    }

    if ($prune === self::PRUNE_UNPUBLISH) {
      $result = $this->pruneUnpublish($this->catalogContentIds($catalog), TRUE);
      $report['actions'] = array_merge($report['actions'], $result['actions']);
      $report['warnings'] = array_merge(
        $this->reportList($report, 'warnings'),
        $result['warnings'],
      );
    }

    $report['actions'][] = 'skip menu_link_content: menus are intentionally out of scope';

    return $report;
  }

  /**
   * Applies one targeted catalog entry.
   *
   * @return array<string, mixed>
   *   A structured apply report.
   */
  private function applyTargeted(string $content_id): array {
    try {
      $catalog = $this->catalogLoader->load();
      $report = $this->catalogValidator->validate($catalog);
    }
    catch (ContentSyncCatalogException $exception) {
      return [
        'dry_run' => FALSE,
        'contents_found' => 0,
        'valid_contents' => [],
        'invalid_contents' => [],
        'warnings' => [],
        'errors' => [$exception->getMessage()],
        'actions' => [],
        'menus_touched' => FALSE,
      ];
    }

    $report['dry_run'] = FALSE;
    $report['actions'] = [];
    $report['menus_touched'] = FALSE;

    $entry = $catalog->get($content_id);
    if ($entry === NULL) {
      $report['errors'][] = sprintf('Unknown content id "%s".', $content_id);
      return $report;
    }

    if (isset($report['invalid_contents'][$content_id . '#' . $entry->index()])) {
      $report['errors'][] = sprintf('Content "%s" is invalid and was not applied.', $content_id);
      return $report;
    }

    try {
      $result = $this->applyValidatedEntry($entry);
      $report['actions'] = array_merge($report['actions'], $result['actions']);
      $report['warnings'] = array_merge(
        $this->reportList($report, 'warnings'),
        $result['warnings'],
      );
    }
    catch (\RuntimeException $exception) {
      $report['errors'][] = $exception->getMessage();
    }

    $report['actions'][] = 'skip menu_link_content: menus are intentionally out of scope';

    return $report;
  }

  /**
   * Applies every valid catalog entry after a full preflight validation.
   *
   * @return array<string, mixed>
   *   A structured apply report.
   */
  private function applyAll(string $prune = ''): array {
    try {
      $catalog = $this->catalogLoader->load();
      $report = $this->catalogValidator->validate($catalog);
    }
    catch (ContentSyncCatalogException $exception) {
      return [
        'dry_run' => FALSE,
        'contents_found' => 0,
        'valid_contents' => [],
        'invalid_contents' => [],
        'warnings' => [],
        'errors' => [$exception->getMessage()],
        'actions' => [],
        'content_reports' => [],
        'menus_touched' => FALSE,
      ];
    }

    $report['dry_run'] = FALSE;
    $report['actions'] = [];
    $report['content_reports'] = [];
    $report['menus_touched'] = FALSE;

    if ($this->reportList($report, 'errors') !== []) {
      $report['actions'][] = 'catalog validation failed: no content or mapping writes were executed';
      $report['actions'][] = 'skip menu_link_content: menus are intentionally out of scope';
      return $report;
    }

    foreach ($catalog->entries() as $entry) {
      try {
        $this->assertSupportedTarget($entry);
      }
      catch (\RuntimeException $exception) {
        $report['errors'][] = $exception->getMessage();
      }
    }

    if ($this->reportList($report, 'errors') !== []) {
      $report['actions'][] = 'catalog apply preflight failed: no content or mapping writes were executed';
      $report['actions'][] = 'skip menu_link_content: menus are intentionally out of scope';
      return $report;
    }

    foreach ($catalog->entries() as $entry) {
      $content_report = [
        'id' => $entry->id(),
        'actions' => [],
        'warnings' => [],
        'errors' => [],
      ];

      try {
        $result = $this->applyValidatedEntry($entry);
        $content_report['actions'] = $result['actions'];
        $content_report['warnings'] = $result['warnings'];
        $report['actions'][] = sprintf('content %s:', $entry->id());
        foreach ($result['actions'] as $action) {
          $report['actions'][] = sprintf('  - %s', $action);
        }
        $report['warnings'] = array_merge(
          $this->reportList($report, 'warnings'),
          $result['warnings'],
        );
      }
      catch (\RuntimeException $exception) {
        $content_report['errors'][] = $exception->getMessage();
        $report['errors'][] = $exception->getMessage();
      }

      $report['content_reports'][] = $content_report;
    }

    if ($prune === self::PRUNE_UNPUBLISH) {
      if ($this->reportList($report, 'errors') === []) {
        $result = $this->pruneUnpublish($this->catalogContentIds($catalog), FALSE);
        $report['actions'] = array_merge($report['actions'], $result['actions']);
        $report['warnings'] = array_merge(
          $this->reportList($report, 'warnings'),
          $result['warnings'],
        );
      }
      else {
        $report['actions'][] = 'prune unpublish skipped: catalog apply reported errors';
      }
    }

    $report['actions'][] = 'skip menu_link_content: menus are intentionally out of scope';

    return $report;
  }

  /**
   * Unpublishes active managed nodes absent from the current catalog.
   *
   * @param list<string> $catalog_content_ids
   *   Business identifiers currently declared in the catalog.
   * @param bool $dry_run
   *   TRUE to report only, FALSE to write node and mapping changes.
   *
   * @return array{actions: list<string>, warnings: list<string>}
   *   Prune messages.
   */
  private function pruneUnpublish(array $catalog_content_ids, bool $dry_run): array {
    $actions = [
      $dry_run
        ? 'prune unpublish dry-run: checking active mappings absent from catalog'
        : 'prune unpublish apply: checking active mappings absent from catalog',
    ];
    $warnings = [];
    $mappings = $this->mappingRepository->findActiveMissingFromCatalog($catalog_content_ids);

    if ($mappings === []) {
      $actions[] = 'prune unpublish: no active managed content is absent from catalog';
      return [
        'actions' => $actions,
        'warnings' => $warnings,
      ];
    }

    foreach ($mappings as $mapping) {
      if ($mapping->entityType() !== 'node') {
        $warnings[] = sprintf(
          'prune unpublish skipped for %s: mapped entity type "%s" is not supported',
          $mapping->contentId(),
          $mapping->entityType(),
        );
        continue;
      }

      $node = $this->loadMappedNode($mapping);
      if (!$node instanceof NodeInterface) {
        $warnings[] = sprintf(
          'prune unpublish skipped for %s: mapped node could not be loaded',
          $mapping->contentId(),
        );
        continue;
      }

      if ($dry_run) {
        $actions[] = sprintf(
          'would unpublish managed node:%d for absent catalog content %s',
          (int) $node->id(),
          $mapping->contentId(),
        );
        continue;
      }

      $this->unpublishNodeTranslations($node);
      $node->save();
      $this->mappingRepository->createOrUpdate(new ContentSyncMappingRecord(
        $mapping->contentId(),
        'node',
        (int) $node->id(),
        $node->uuid(),
        $mapping->langcode(),
        $mapping->catalogHash(),
        $this->time->getRequestTime(),
        'unpublished',
        'unpublished',
        $mapping->created(),
      ));

      $actions[] = sprintf(
        'unpublished managed node:%d for absent catalog content %s',
        (int) $node->id(),
        $mapping->contentId(),
      );
    }

    return [
      'actions' => $actions,
      'warnings' => $warnings,
    ];
  }

  /**
   * Unpublishes every available translation of a node.
   */
  private function unpublishNodeTranslations(NodeInterface $node): void {
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $translation = $node->getTranslation((string) $langcode);
      if ($translation->hasField('status')) {
        $translation->set('status', NodeInterface::NOT_PUBLISHED);
      }
    }
  }

  /**
   * Applies one catalog entry that has already passed catalog preflight.
   *
   * @return array{actions: list<string>, warnings: list<string>}
   *   Apply messages.
   */
  private function applyValidatedEntry(ContentSyncCatalogEntry $entry): array {
    $this->assertSupportedTarget($entry);

    return $this->applyNodeService($entry);
  }

  /**
   * Applies the supported node:service catalog item.
   *
   * @return array{actions: list<string>, warnings: list<string>}
   *   Apply messages.
   */
  private function applyNodeService(ContentSyncCatalogEntry $entry): array {
    $definition = $entry->toArray();
    $mapping = $this->mappingRepository->findByContentId($entry->id());
    $node = $this->loadMappedNode($mapping);
    $actions = [];
    $warnings = [];

    if ($node !== NULL) {
      $actions[] = sprintf('mapping resolved %s to node:%d', $entry->id(), (int) $node->id());
    }

    if ($node === NULL) {
      $node = $this->resolveNodeByCatalogAliases($definition);
      if ($node !== NULL) {
        $actions[] = sprintf('alias fallback resolved %s to node:%d', $entry->id(), (int) $node->id());
      }
    }

    $created = FALSE;
    if ($node === NULL) {
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'service',
        'langcode' => $this->defaultTranslationLangcode($definition),
        'status' => NodeInterface::PUBLISHED,
        'uid' => 1,
      ]);
      if (!$node instanceof NodeInterface) {
        throw new \RuntimeException(sprintf('Could not create node for "%s".', $entry->id()));
      }
      $created = TRUE;
      $actions[] = sprintf('created new node:service for %s', $entry->id());
    }

    if ($node->bundle() !== 'service') {
      throw new \RuntimeException(sprintf(
        'Content "%s" resolved to node:%s, but it is not a service node.',
        $entry->id(),
        (string) $node->id(),
      ));
    }

    $this->applyNodeTranslations($node, $definition);

    foreach ($this->collectAliases($definition) as $langcode => $aliases) {
      foreach ($aliases as $alias) {
        $this->assertAliasIsAvailableForNode($alias, $langcode, $node);
      }
    }

    $node->save();
    $actions[] = sprintf(
      '%s node:%d translations: %s',
      $created ? 'saved new' : 'updated existing',
      (int) $node->id(),
      implode(', ', array_keys($this->translations($definition))),
    );

    foreach ($this->applyPromotions($definition) as $message) {
      if (str_starts_with($message, 'warning: ')) {
        $warnings[] = substr($message, 9);
        continue;
      }
      $actions[] = $message;
    }

    $catalog_hash = $this->catalogHash($definition);
    $record = $this->mappingRepository->createOrUpdate(new ContentSyncMappingRecord(
      $entry->id(),
      'node',
      (int) $node->id(),
      $node->uuid(),
      $this->defaultTranslationLangcode($definition),
      $catalog_hash,
      $this->time->getRequestTime(),
      $created ? 'created' : 'updated',
      'active',
      $mapping?->created() ?? 0,
    ));

    $actions[] = sprintf(
      'mapping %s for %s: node:%d hash %s',
      $mapping === NULL ? 'created' : 'updated',
      $entry->id(),
      (int) $record->entityId(),
      $catalog_hash,
    );

    return [
      'actions' => $actions,
      'warnings' => $warnings,
    ];
  }

  /**
   * Ensures the catalog entry is the targeted supported write scope.
   */
  private function assertSupportedTarget(ContentSyncCatalogEntry $entry): void {
    $definition = $entry->toArray();
    if (($definition['entity_type'] ?? NULL) !== 'node' || ($definition['bundle'] ?? NULL) !== 'service') {
      throw new \RuntimeException(sprintf(
        'Content "%s" is outside the supported targeted write scope.',
        $entry->id(),
      ));
    }
  }

  /**
   * Loads a node from a mapping record when it still exists.
   */
  private function loadMappedNode(?ContentSyncMappingRecord $mapping): ?NodeInterface {
    if ($mapping === NULL || $mapping->entityType() !== 'node') {
      return NULL;
    }

    if ($mapping->entityUuid() !== NULL && $mapping->entityUuid() !== '') {
      $entity = $this->entityRepository->loadEntityByUuid('node', $mapping->entityUuid());
      if ($entity instanceof NodeInterface) {
        return $entity;
      }
    }

    if ($mapping->entityId() !== NULL) {
      $entity = $this->entityTypeManager->getStorage('node')->load($mapping->entityId());
      if ($entity instanceof NodeInterface) {
        return $entity;
      }
    }

    return NULL;
  }

  /**
   * Resolves an existing service node cautiously by catalog aliases.
   *
   * @param array<string, mixed> $definition
   *   Catalog definition.
   */
  private function resolveNodeByCatalogAliases(array $definition): ?NodeInterface {
    $candidates = [];
    foreach ($this->collectAliases($definition) as $langcode => $aliases) {
      foreach ($aliases as $alias) {
        $path = $this->aliasManager->getPathByAlias($alias, $langcode);
        if ($path === $alias || !preg_match('@^/node/(\d+)$@', $path, $matches)) {
          continue;
        }

        $node = $this->entityTypeManager->getStorage('node')->load((int) $matches[1]);
        if ($node instanceof NodeInterface && $node->bundle() === 'service') {
          $candidates[(int) $node->id()] = $node;
        }
      }
    }

    if (count($candidates) > 1) {
      throw new \RuntimeException('Catalog aliases resolve to multiple service nodes; refusing to write.');
    }

    return $candidates === [] ? NULL : reset($candidates);
  }

  /**
   * Applies all declared node translations and aliases.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node to update.
   * @param array<string, mixed> $definition
   *   Catalog definition.
   */
  private function applyNodeTranslations(NodeInterface $node, array $definition): void {
    foreach ($this->translations($definition) as $langcode => $translation_definition) {
      $translation = $this->nodeTranslation($node, $langcode);

      if (isset($translation_definition['title']) && is_string($translation_definition['title'])) {
        $translation->setTitle($translation_definition['title']);
      }

      if ($translation->hasField('status')) {
        $translation->set('status', NodeInterface::PUBLISHED);
      }

      $fields = is_array($translation_definition['fields'] ?? NULL)
        ? $translation_definition['fields']
        : [];
      foreach ($fields as $field_name => $value) {
        if (is_string($field_name) && $translation->hasField($field_name)) {
          $translation->set($field_name, $value);
        }
      }

      if (isset($translation_definition['alias']) && is_string($translation_definition['alias'])) {
        $translation->set('path', [
          'alias' => $translation_definition['alias'],
          'pathauto' => FALSE,
        ]);
      }
    }
  }

  /**
   * Returns or creates a node translation.
   */
  private function nodeTranslation(NodeInterface $node, string $langcode): NodeInterface {
    if ($node->language()->getId() === $langcode) {
      return $node;
    }

    if ($node->hasTranslation($langcode)) {
      return $node->getTranslation($langcode);
    }

    return $node->addTranslation($langcode, $node->toArray());
  }

  /**
   * Ensures an alias is not already owned by another node.
   */
  private function assertAliasIsAvailableForNode(string $alias, string $langcode, NodeInterface $node): void {
    $path = $this->aliasManager->getPathByAlias($alias, $langcode);
    if ($path === $alias) {
      return;
    }

    if (!preg_match('@^/node/(\d+)$@', $path, $matches)) {
      throw new \RuntimeException(sprintf(
        'Alias "%s" (%s) is already used by "%s"; refusing to overwrite it.',
        $alias,
        $langcode,
        $path,
      ));
    }

    if ((int) $matches[1] !== (int) $node->id()) {
      throw new \RuntimeException(sprintf(
        'Alias "%s" (%s) is already used by node:%d; refusing to overwrite it.',
        $alias,
        $langcode,
        (int) $matches[1],
      ));
    }
  }

  /**
   * Applies catalog promotion cards on existing target pages.
   *
   * @param array<string, mixed> $definition
   *   Catalog definition.
   *
   * @return list<string>
   *   Action and warning messages.
   */
  private function applyPromotions(array $definition): array {
    if (!isset($definition['promotions']) || !is_array($definition['promotions'])) {
      return [];
    }

    $messages = [];
    foreach ($definition['promotions'] as $promotion) {
      if (!is_array($promotion)) {
        continue;
      }

      $target = is_array($promotion['target'] ?? NULL) ? $promotion['target'] : [];
      $page = $this->resolvePromotionTargetPage($target);
      $target_label = (string) ($target['label'] ?? 'promotion target');
      if (!$page instanceof NodeInterface) {
        $messages[] = sprintf('warning: promotion target "%s" was not found', $target_label);
        continue;
      }

      $translations = is_array($promotion['translations'] ?? NULL)
        ? $promotion['translations']
        : [];

      foreach ($translations as $langcode => $translation) {
        if (!is_array($translation)) {
          continue;
        }

        $paragraph = $this->findServicesParagraph($page, (string) $langcode);
        if (!$paragraph instanceof ParagraphInterface) {
          $messages[] = sprintf(
            'warning: promotion target "%s" has no services paragraph for %s',
            $target_label,
            (string) $langcode,
          );
          continue;
        }

        $paragraph_translation = $this->paragraphTranslation($paragraph, (string) $langcode);
        if (!$paragraph_translation->hasField('field_items')) {
          $messages[] = sprintf(
            'warning: promotion target "%s" services paragraph has no field_items',
            $target_label,
          );
          continue;
        }

        $changed = $this->upsertPromotionCard($paragraph_translation, $translation);
        if ($changed) {
          $paragraph_translation->save();
          $messages[] = sprintf(
            'promotion card synchronized on "%s" for %s',
            $target_label,
            (string) $langcode,
          );
        }
        else {
          $messages[] = sprintf(
            'promotion card already up to date on "%s" for %s',
            $target_label,
            (string) $langcode,
          );
        }
      }
    }

    return $messages;
  }

  /**
   * Resolves a target page from the promotion target definition.
   *
   * @param array<string, mixed> $target
   *   Promotion target definition.
   */
  private function resolvePromotionTargetPage(array $target): ?NodeInterface {
    if (($target['front'] ?? FALSE) === TRUE) {
      $front = $this->configFactory->get('system.site')->get('page.front');
      if (is_string($front) && preg_match('@^/node/(\d+)$@', $front, $matches)) {
        $node = $this->entityTypeManager->getStorage('node')->load((int) $matches[1]);
        if ($node instanceof NodeInterface) {
          return $node;
        }
      }
    }

    $aliases = is_array($target['aliases'] ?? NULL) ? $target['aliases'] : [];
    foreach ($aliases as $langcode => $alias) {
      if (!is_string($alias)) {
        continue;
      }

      $path = $this->aliasManager->getPathByAlias($alias, (string) $langcode);
      if (!preg_match('@^/node/(\d+)$@', $path, $matches)) {
        continue;
      }

      $node = $this->entityTypeManager->getStorage('node')->load((int) $matches[1]);
      if ($node instanceof NodeInterface) {
        return $node;
      }
    }

    return NULL;
  }

  /**
   * Finds the first services paragraph referenced by a page.
   */
  private function findServicesParagraph(NodeInterface $page, string $langcode): ?ParagraphInterface {
    $page_translation = $page;
    if ($page->hasTranslation($langcode)) {
      $translation = $page->getTranslation($langcode);
      if ($translation instanceof NodeInterface) {
        $page_translation = $translation;
      }
    }

    if (!$page_translation->hasField('field_home_components')) {
      return NULL;
    }

    foreach ($page_translation->get('field_home_components')->referencedEntities() as $component) {
      if ($component instanceof ParagraphInterface && $component->bundle() === 'services') {
        return $component;
      }
    }

    return NULL;
  }

  /**
   * Returns or creates a paragraph translation.
   */
  private function paragraphTranslation(ParagraphInterface $paragraph, string $langcode): ParagraphInterface {
    if ($paragraph->language()->getId() === $langcode) {
      return $paragraph;
    }

    if ($paragraph->hasTranslation($langcode)) {
      return $paragraph->getTranslation($langcode);
    }

    return $paragraph->addTranslation($langcode, $paragraph->toArray());
  }

  /**
   * Inserts or updates one promotion card while preserving unrelated items.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   Services paragraph translation to update.
   * @param array<string, mixed> $translation
   *   Promotion translation definition.
   */
  private function upsertPromotionCard(ParagraphInterface $paragraph, array $translation): bool {
    $title = (string) ($translation['title'] ?? '');
    $description = (string) ($translation['description'] ?? '');
    $url = (string) ($translation['url'] ?? '');

    if ($title === '' || $description === '' || $url === '') {
      return FALSE;
    }

    $new_value = sprintf('%s|%s|%s', $title, $description, $url);
    $items = [];
    $updated = FALSE;
    foreach ($paragraph->get('field_items') as $item) {
      $item_value = $item->getValue();
      $value = (string) ($item_value['value'] ?? '');
      $parts = array_map('trim', explode('|', $value));
      $is_match = !$updated && (
        ($parts[0] ?? '') === $title
        || ($parts[2] ?? '') === $url
      );

      $items[] = [
        'value' => $is_match ? $new_value : $value,
        'format' => (string) (($item_value['format'] ?? '') ?: 'basic_html'),
      ];
      if ($is_match) {
        $updated = TRUE;
      }
    }

    if (!$updated) {
      $items[] = [
        'value' => $new_value,
        'format' => 'basic_html',
      ];
    }

    $current = array_map(
      static fn (array $item): string => $item['value'] . '|' . $item['format'],
      array_map(
        static function ($item): array {
          $item_value = $item->getValue();
          return [
            'value' => (string) ($item_value['value'] ?? ''),
            'format' => (string) (($item_value['format'] ?? '') ?: 'basic_html'),
          ];
        },
        iterator_to_array($paragraph->get('field_items')),
      ),
    );
    $next = array_map(
      static fn (array $item): string => $item['value'] . '|' . $item['format'],
      $items,
    );

    if ($current === $next) {
      return FALSE;
    }

    $paragraph->set('field_items', $items);
    return TRUE;
  }

  /**
   * Returns normalized translation definitions.
   *
   * @param array<string, mixed> $definition
   *   Catalog definition.
   *
   * @return array<string, array<string, mixed>>
   *   Translation definitions keyed by langcode.
   */
  private function translations(array $definition): array {
    $translations = [];
    if (!is_array($definition['translations'] ?? NULL)) {
      return $translations;
    }

    foreach ($definition['translations'] as $langcode => $translation) {
      if (is_string($langcode) && is_array($translation)) {
        $translations[$langcode] = $translation;
      }
    }

    return $translations;
  }

  /**
   * Returns the preferred default translation language.
   *
   * @param array<string, mixed> $definition
   *   Catalog definition.
   */
  private function defaultTranslationLangcode(array $definition): string {
    $translations = $this->translations($definition);
    if (isset($translations['fr'])) {
      return 'fr';
    }

    $langcodes = array_keys($translations);
    return $langcodes[0] ?? $this->languageManager->getDefaultLanguage()->getId();
  }

  /**
   * Collects catalog aliases by language.
   *
   * @param array<string, mixed> $definition
   *   Catalog definition.
   *
   * @return array<string, list<string>>
   *   Aliases keyed by langcode.
   */
  private function collectAliases(array $definition): array {
    $aliases = [];
    foreach (['business_aliases', 'translations'] as $section) {
      if (!is_array($definition[$section] ?? NULL)) {
        continue;
      }

      foreach ($definition[$section] as $langcode => $value) {
        if (!is_string($langcode)) {
          continue;
        }

        $alias = NULL;
        if (is_string($value)) {
          $alias = $value;
        }
        elseif (is_array($value) && isset($value['alias']) && is_string($value['alias'])) {
          $alias = $value['alias'];
        }

        if ($alias !== NULL && str_starts_with($alias, '/')) {
          $aliases[$langcode][] = $alias;
        }
      }
    }

    foreach ($aliases as $langcode => $lang_aliases) {
      $aliases[$langcode] = array_values(array_unique($lang_aliases));
    }

    return $aliases;
  }

  /**
   * Returns all business identifiers declared in a catalog.
   *
   * @return list<string>
   *   Catalog content identifiers.
   */
  private function catalogContentIds(ContentSyncCatalog $catalog): array {
    $content_ids = [];
    foreach ($catalog->entries() as $entry) {
      $content_ids[] = $entry->id();
    }

    return $content_ids;
  }

  /**
   * Builds a deterministic hash for one catalog definition.
   *
   * @param array<string, mixed> $definition
   *   Catalog definition.
   */
  private function catalogHash(array $definition): string {
    return hash('sha256', serialize($definition));
  }

  /**
   * Returns a report list safely.
   *
   * @param array<string, mixed> $report
   *   Structured report.
   * @param string $key
   *   Report list key.
   *
   * @return array<int|string, mixed>
   *   Report list.
   */
  private function reportList(array $report, string $key): array {
    return isset($report[$key]) && is_array($report[$key]) ? $report[$key] : [];
  }

}
