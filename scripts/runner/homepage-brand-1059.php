<?php

declare(strict_types=1);

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\emerging_digital_content\ContentSync\Entity\ContentSyncMappingRecord;
use Drupal\emerging_digital_content\ContentSync\Repository\ContentSyncMappingRepository;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Closed PREPROD profile for #1059 homepage EN Brand parity.
 *
 * The profile is fixed to node 5 / page / EN and mutates only existing EN
 * translations of the first hero and text_block Paragraphs. It never creates a
 * homepage, Paragraph tree, translation, Content Sync mapping, alias or menu.
 */
final class AgencyHomepageBrand1059 {

  private const PROFILE = 'homepage-brand-1059';
  private const PROFILE_SHA256 = 'e7f6e184a31048b4fcea7f126b6e24e9aa23db46522cd2537f441fd2f3218390';
  private const ISSUE_NUMBER = 1059;
  private const TARGET_NODE_ID = 5;
  private const BUNDLE = 'page';
  private const LANGCODE = 'en';
  private const AUTHOR_UID = 1;
  private const CONTENT_ID = 'homepage';
  private const PRIMARY_CTA_URI = 'internal:/contact';
  private const SECONDARY_CTA_URI = 'internal:/services';

  private const EN_H1 = 'Create, improve or modernise your web platform';
  private const EN_SUBTITLE = 'E-merging Digital supports SMEs, non-profits and other organisations launching a new project, evolving an existing platform or modernising an environment that has become difficult to maintain. We start with the business need before choosing the technologies that best serve it.';
  private const EN_PRIMARY_CTA_LABEL = 'Discuss my project';
  private const EN_SECONDARY_CTA_LABEL = 'Explore our expertise';
  private const EN_AXES_HEADING = 'CREATE / IMPROVE / MODERNISE';

  private const FR_H1 = 'Créer, améliorer ou moderniser votre plateforme web';
  private const FR_SUBTITLE = 'E-merging Digital accompagne les PME, ASBL et organisations qui lancent un nouveau projet, doivent faire évoluer une plateforme existante ou veulent moderniser un environnement devenu difficile à maintenir. Nous partons du besoin métier avant de choisir les technologies qui le servent.';
  private const FR_PRIMARY_CTA_LABEL = 'Parler de mon projet';
  private const FR_SECONDARY_CTA_LABEL = 'Voir les expertises';
  private const FR_AXES_HEADING = 'CRÉER / AMÉLIORER / MODERNISER';

  /** @var array<int, array{heading: string, body: string}> */
  private const EN_AXES = [
    [
      'heading' => 'CREATE',
      'body' => 'You are starting from a new need. We help define the use cases, user journeys, content and technical foundations needed to build a useful, maintainable platform that can evolve over time.',
    ],
    [
      'heading' => 'IMPROVE',
      'body' => 'Your platform already works, but you need new features, better integrations, clearer user journeys or automation for some processes. We evolve what already exists without starting from scratch by default.',
    ],
    [
      'heading' => 'MODERNISE',
      'body' => 'Your website or application relies on an ageing foundation, has accumulated technical debt or is becoming too costly to evolve. We help prioritise, stabilise and modernise it progressively, through to migration when migration is genuinely justified.',
    ],
  ];

  /** @var array<int, array{heading: string, body: string}> */
  private const FR_AXES = [
    [
      'heading' => 'CRÉER',
      'body' => 'Vous partez d’un nouveau besoin. Nous aidons à cadrer les usages, les parcours, les contenus et la base technique pour construire une plateforme utile, maintenable et capable d’évoluer.',
    ],
    [
      'heading' => 'AMÉLIORER',
      'body' => 'Votre plateforme fonctionne déjà, mais vous avez besoin de nouvelles fonctionnalités, de meilleures intégrations, de parcours plus clairs ou d’automatiser certains usages. Nous faisons évoluer l’existant sans repartir de zéro par réflexe.',
    ],
    [
      'heading' => 'MODERNISER',
      'body' => 'Votre site ou application repose sur un socle vieillissant, accumule de la dette technique ou devient trop coûteux à faire évoluer. Nous aidons à prioriser, fiabiliser et moderniser progressivement, jusqu’à la migration lorsque celle-ci est réellement justifiée.',
    ],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly ContentSyncMappingRepository $mappingRepository,
    private readonly Connection $database,
  ) {
  }

  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('account_switcher'),
      $container->get('emerging_digital_content.content_sync_mapping_repository'),
      $container->get('database'),
    );
  }

  /** @return array<string, mixed> */
  public function inspect(): array {
    return $this->plan('inspect');
  }

  /** @return array<string, mixed> */
  public function dryRun(): array {
    return $this->plan('dry-run');
  }

  /** @return array<string, mixed> */
  public function apply(): array {
    $plan = $this->plan('apply');
    if (!in_array($plan['verdict'], ['IDEMPOTENT', 'UPDATE_READY'], TRUE)) {
      throw new RuntimeException('Homepage EN #1059 candidate is not update-ready.');
    }
    if ($plan['verdict'] === 'IDEMPOTENT') {
      return $plan;
    }

    $transaction = $this->database->startTransaction();
    $author = $this->loadAuthor();
    $this->accountSwitcher->switchTo($author);

    try {
      $node = $this->loadNode();
      $before = $this->snapshot($node);
      $this->assertRuntimeIdentity($node);
      $this->assertExpectedStructure($node, TRUE);
      $this->assertFrBaseline($node);

      $en = $this->translation($node, self::LANGCODE);
      $enComponents = $this->components($en);
      $hero = $this->translation($enComponents[0], self::LANGCODE);
      $axes = $this->translation($enComponents[1], self::LANGCODE);

      $hero->set('field_heading', self::EN_H1);
      $hero->set('field_text', $this->formatted(self::EN_SUBTITLE));
      $hero->set('field_link', [
        'uri' => self::PRIMARY_CTA_URI,
        'title' => self::EN_PRIMARY_CTA_LABEL,
      ]);
      $hero->set('field_secondary_link', [
        'uri' => self::SECONDARY_CTA_URI,
        'title' => self::EN_SECONDARY_CTA_LABEL,
      ]);
      $hero->setNewRevision(TRUE);
      $this->assertEntityValid($hero, 'homepage EN hero');
      $hero->save();

      $axes->set('field_heading', self::EN_AXES_HEADING);
      $axes->set('field_text', $this->formatted($this->axesHtml(self::EN_AXES)));
      $axes->setNewRevision(TRUE);
      $this->assertEntityValid($axes, 'homepage EN axes');
      $axes->save();

      $references = $en->get('field_home_components')->getValue();
      $references[0]['target_id'] = (int) $hero->id();
      $references[0]['target_revision_id'] = (int) $hero->getRevisionId();
      $references[1]['target_id'] = (int) $axes->id();
      $references[1]['target_revision_id'] = (int) $axes->getRevisionId();
      $en->set('field_home_components', $references);

      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(self::AUTHOR_UID);
      $node->setRevisionLogMessage($this->revisionMessage());
      $this->assertEntityValid($node, 'homepage node');
      $node->save();

      $this->entityTypeManager->getStorage('node')->resetCache([self::TARGET_NODE_ID]);
      $verified = $this->loadNode();
      $after = $this->snapshot($verified);
      $this->assertConverged($verified, $before, $after);
      unset($transaction);

      return $this->result('APPLIED', 'apply', $verified, $after);
    }
    catch (Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /** @return array<string, mixed> */
  private function plan(string $mode): array {
    $this->assertProfileHash();
    $node = $this->loadNode();
    $this->assertRuntimeIdentity($node);
    $translations = $this->translationState($node);
    $this->assertExpectedStructure($node, FALSE);
    $this->assertFrBaseline($node);
    $snapshot = $this->snapshot($node);

    if ($mode !== 'inspect' && in_array('MISSING', $translations, TRUE)) {
      throw new RuntimeException(sprintf(
        'Required existing EN translation is missing: node=%s hero=%s axes=%s.',
        $translations['node'],
        $translations['hero'],
        $translations['axes'],
      ));
    }

    $verdict = $mode === 'inspect'
      ? 'INSPECTED'
      : ($this->matchesRequestedContent($node) ? 'IDEMPOTENT' : 'UPDATE_READY');

    return $this->result($verdict, $mode, $node, $snapshot);
  }

  private function loadNode(): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load(self::TARGET_NODE_ID);
    if (!$node instanceof NodeInterface) {
      throw new RuntimeException('Required existing homepage node 5 is unavailable.');
    }
    return $node;
  }

  private function translation(ContentEntityInterface $entity, string $langcode): ContentEntityInterface {
    if ($entity->language()->getId() === $langcode) {
      return $entity;
    }
    if (!$entity->hasTranslation($langcode)) {
      throw new RuntimeException(sprintf('%s %s has no %s translation.', $entity->getEntityTypeId(), (string) $entity->id(), strtoupper($langcode)));
    }
    return $entity->getTranslation($langcode);
  }

  /** @return array<int, ParagraphInterface> */
  private function components(NodeInterface $translation): array {
    $components = $translation->get('field_home_components')->referencedEntities();
    foreach ($components as $component) {
      if (!$component instanceof ParagraphInterface) {
        throw new RuntimeException('Homepage contains a non-Paragraph component reference.');
      }
    }
    return array_values($components);
  }

  /** @return array{node:string,hero:string,axes:string} */
  private function translationState(NodeInterface $node): array {
    $nodeExists = $node->language()->getId() === self::LANGCODE || $node->hasTranslation(self::LANGCODE);
    if (!$nodeExists) {
      return ['node' => 'MISSING', 'hero' => 'MISSING', 'axes' => 'MISSING'];
    }

    $en = $this->translation($node, self::LANGCODE);
    $components = $this->components($en);
    if (count($components) < 2) {
      return ['node' => 'EXISTS', 'hero' => 'MISSING', 'axes' => 'MISSING'];
    }

    $heroExists = $components[0]->language()->getId() === self::LANGCODE || $components[0]->hasTranslation(self::LANGCODE);
    $axesExists = $components[1]->language()->getId() === self::LANGCODE || $components[1]->hasTranslation(self::LANGCODE);
    return [
      'node' => 'EXISTS',
      'hero' => $heroExists ? 'EXISTS' : 'MISSING',
      'axes' => $axesExists ? 'EXISTS' : 'MISSING',
    ];
  }

  private function assertExpectedStructure(NodeInterface $node, bool $requireEnTranslations): void {
    if ($node->bundle() !== self::BUNDLE) {
      throw new RuntimeException('Homepage node 5 is not a Page bundle.');
    }

    $definitions = $this->entityFieldManager->getFieldDefinitions('node', self::BUNDLE);
    if (!isset($definitions['field_home_components']) || !$definitions['field_home_components']->isTranslatable()) {
      throw new RuntimeException('Page field_home_components must exist and remain translatable.');
    }

    $fr = $this->translation($node, 'fr');
    $frComponents = $this->components($fr);
    if (count($frComponents) < 3 || $frComponents[0]->bundle() !== 'hero' || $frComponents[1]->bundle() !== 'text_block') {
      throw new RuntimeException('Homepage FR structure must begin with existing hero then text_block components.');
    }

    $heroFields = $this->entityFieldManager->getFieldDefinitions('paragraph', 'hero');
    foreach (['field_heading', 'field_text', 'field_link', 'field_secondary_link'] as $field) {
      if (!isset($heroFields[$field]) || !$heroFields[$field]->isTranslatable()) {
        throw new RuntimeException(sprintf('Homepage hero field %s must exist and remain translatable.', $field));
      }
    }
    $textFields = $this->entityFieldManager->getFieldDefinitions('paragraph', 'text_block');
    foreach (['field_heading', 'field_text'] as $field) {
      if (!isset($textFields[$field]) || !$textFields[$field]->isTranslatable()) {
        throw new RuntimeException(sprintf('Homepage text_block field %s must exist and remain translatable.', $field));
      }
    }

    $state = $this->translationState($node);
    if ($requireEnTranslations && in_array('MISSING', $state, TRUE)) {
      throw new RuntimeException('Homepage existing EN translation structure is incomplete.');
    }
    if ($state['node'] === 'EXISTS') {
      $enComponents = $this->components($this->translation($node, self::LANGCODE));
      if (count($enComponents) < 3 || $enComponents[0]->bundle() !== 'hero' || $enComponents[1]->bundle() !== 'text_block') {
        throw new RuntimeException('Homepage EN structure must begin with existing hero then text_block components.');
      }
      if ((int) $frComponents[0]->id() !== (int) $enComponents[0]->id()
        || (int) $frComponents[1]->id() !== (int) $enComponents[1]->id()) {
        throw new RuntimeException('Homepage FR/EN first component identities differ; duplicate Paragraph trees are not allowed.');
      }
    }
  }

  private function assertRuntimeIdentity(NodeInterface $node): void {
    if ((string) \Drupal::config('system.site')->get('page.front') !== '/node/5') {
      throw new RuntimeException('system.site:page.front is not /node/5.');
    }
    $mapping = $this->mappingRepository->findByContentId(self::CONTENT_ID);
    if (!$mapping instanceof ContentSyncMappingRecord
      || $mapping->entityType() !== 'node'
      || $mapping->entityId() !== self::TARGET_NODE_ID
      || $mapping->entityUuid() !== $node->uuid()) {
      throw new RuntimeException('Homepage released Content Sync identity is invalid.');
    }
    if ($mapping->status() !== ContentSyncMappingRecord::STATUS_RELEASED) {
      throw new RuntimeException('Homepage must remain editor-owned with released Content Sync lifecycle.');
    }
  }

  private function assertFrBaseline(NodeInterface $node): void {
    $frComponents = $this->components($this->translation($node, 'fr'));
    $hero = $this->translation($frComponents[0], 'fr');
    $axes = $this->translation($frComponents[1], 'fr');
    $primary = $hero->get('field_link')->first()?->getValue() ?? [];
    $secondary = $hero->get('field_secondary_link')->first()?->getValue() ?? [];

    if ((string) $hero->get('field_heading')->value !== self::FR_H1
      || (string) $hero->get('field_text')->value !== self::FR_SUBTITLE
      || ($primary['uri'] ?? NULL) !== self::PRIMARY_CTA_URI
      || ($primary['title'] ?? NULL) !== self::FR_PRIMARY_CTA_LABEL
      || ($secondary['uri'] ?? NULL) !== self::SECONDARY_CTA_URI
      || ($secondary['title'] ?? NULL) !== self::FR_SECONDARY_CTA_LABEL
      || (string) $axes->get('field_heading')->value !== self::FR_AXES_HEADING
      || (string) $axes->get('field_text')->value !== $this->axesHtml(self::FR_AXES)) {
      throw new RuntimeException('Homepage FR approved #1015 baseline changed; #1059 refuses to rewrite FR.');
    }
  }

  private function matchesRequestedContent(NodeInterface $node): bool {
    $state = $this->translationState($node);
    if (in_array('MISSING', $state, TRUE)) {
      return FALSE;
    }
    $enComponents = $this->components($this->translation($node, self::LANGCODE));
    $hero = $this->translation($enComponents[0], self::LANGCODE);
    $axes = $this->translation($enComponents[1], self::LANGCODE);
    $primary = $hero->get('field_link')->first()?->getValue() ?? [];
    $secondary = $hero->get('field_secondary_link')->first()?->getValue() ?? [];

    return (string) $hero->get('field_heading')->value === self::EN_H1
      && (string) $hero->get('field_text')->value === self::EN_SUBTITLE
      && ($primary['uri'] ?? NULL) === self::PRIMARY_CTA_URI
      && ($primary['title'] ?? NULL) === self::EN_PRIMARY_CTA_LABEL
      && ($secondary['uri'] ?? NULL) === self::SECONDARY_CTA_URI
      && ($secondary['title'] ?? NULL) === self::EN_SECONDARY_CTA_LABEL
      && (string) $axes->get('field_heading')->value === self::EN_AXES_HEADING
      && (string) $axes->get('field_text')->value === $this->axesHtml(self::EN_AXES);
  }

  /** @return array<string, mixed> */
  private function snapshot(NodeInterface $node): array {
    $fr = $this->translation($node, 'fr');
    $frComponents = $this->components($fr);
    $state = $this->translationState($node);
    $snapshot = [
      'node_id' => (int) $node->id(),
      'node_uuid' => $node->uuid(),
      'front' => (string) \Drupal::config('system.site')->get('page.front'),
      'translations' => $state,
      'fr' => $this->languageSnapshot($fr, $frComponents, 'fr'),
      'aliases' => $this->aliasSnapshot(),
      'menu' => $this->menuSnapshot(),
    ];
    if ($state['node'] === 'EXISTS') {
      $en = $this->translation($node, self::LANGCODE);
      $snapshot['en'] = $this->languageSnapshot($en, $this->components($en), self::LANGCODE);
    }
    return $snapshot;
  }

  /** @param array<int, ParagraphInterface> $components */
  private function languageSnapshot(NodeInterface $translation, array $components, string $langcode): array {
    $result = [
      'references' => $translation->get('field_home_components')->getValue(),
      'components' => array_map(static fn (ParagraphInterface $paragraph): array => [
        'id' => (int) $paragraph->id(),
        'revision_id' => (int) $paragraph->getRevisionId(),
        'uuid' => $paragraph->uuid(),
        'bundle' => $paragraph->bundle(),
      ], $components),
    ];
    if (count($components) >= 2
      && ($components[0]->language()->getId() === $langcode || $components[0]->hasTranslation($langcode))
      && ($components[1]->language()->getId() === $langcode || $components[1]->hasTranslation($langcode))) {
      $hero = $this->translation($components[0], $langcode);
      $axes = $this->translation($components[1], $langcode);
      $result['hero'] = [
        'heading' => (string) $hero->get('field_heading')->value,
        'text' => (string) $hero->get('field_text')->value,
        'link' => $hero->get('field_link')->first()?->getValue() ?? [],
        'secondary_link' => $hero->get('field_secondary_link')->first()?->getValue() ?? [],
      ];
      $result['axes'] = [
        'heading' => (string) $axes->get('field_heading')->value,
        'text' => (string) $axes->get('field_text')->value,
      ];
    }
    return $result;
  }

  private function assertConverged(NodeInterface $node, array $before, array $after): void {
    if (!$this->matchesRequestedContent($node)) {
      throw new RuntimeException('Homepage EN #1059 values did not converge after apply.');
    }
    $this->assertFrBaseline($node);
    foreach (['node_id', 'node_uuid', 'front', 'aliases', 'menu'] as $key) {
      if (($before[$key] ?? NULL) !== ($after[$key] ?? NULL)) {
        throw new RuntimeException(sprintf('Homepage immutable %s changed during EN apply.', $key));
      }
    }
    if (($before['fr'] ?? NULL) !== ($after['fr'] ?? NULL)) {
      throw new RuntimeException('Homepage FR translation or Paragraph references changed during EN apply.');
    }
    $beforeEn = $before['en'] ?? [];
    $afterEn = $after['en'] ?? [];
    if (count($beforeEn['components'] ?? []) !== count($afterEn['components'] ?? [])) {
      throw new RuntimeException('Homepage EN component count changed during apply.');
    }
    foreach (($beforeEn['components'] ?? []) as $index => $component) {
      if (($component['id'] ?? NULL) !== ($afterEn['components'][$index]['id'] ?? NULL)
        || ($component['uuid'] ?? NULL) !== ($afterEn['components'][$index]['uuid'] ?? NULL)
        || ($component['bundle'] ?? NULL) !== ($afterEn['components'][$index]['bundle'] ?? NULL)) {
        throw new RuntimeException('Homepage EN component identity/order changed during apply.');
      }
      if ($index >= 2 && $component !== $afterEn['components'][$index]) {
        throw new RuntimeException('Downstream homepage EN technical/proof component changed during apply.');
      }
    }
  }

  /** @return array<int, array<string, int|string>> */
  private function aliasSnapshot(): array {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('path', '/node/' . self::TARGET_NODE_ID)->sort('id')->execute();
    $aliases = [];
    foreach ($storage->loadMultiple($ids) as $alias) {
      $aliases[] = [
        'id' => (int) $alias->id(),
        'uuid' => $alias->uuid(),
        'langcode' => $alias->language()->getId(),
        'path' => (string) $alias->get('path')->value,
        'alias' => (string) $alias->get('alias')->value,
      ];
    }
    return $aliases;
  }

  /** @return array<int, array<string, mixed>> */
  private function menuSnapshot(): array {
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('menu_name', 'main')->sort('id')->execute();
    $links = [];
    foreach ($storage->loadMultiple($ids) as $link) {
      $links[] = [
        'id' => (int) $link->id(),
        'uuid' => $link->uuid(),
        'langcode' => $link->language()->getId(),
        'title' => (string) $link->label(),
        'uri' => (string) (($link->get('link')->first()?->getValue()['uri'] ?? '')),
        'enabled' => (bool) $link->get('enabled')->value,
        'weight' => (int) $link->get('weight')->value,
        'parent' => (string) ($link->get('parent')->value ?? ''),
      ];
    }
    return $links;
  }

  /** @param array<int, array{heading:string,body:string}> $axes */
  private function axesHtml(array $axes): string {
    return implode('', array_map(static fn (array $axis): string => sprintf(
      '<h3>%s</h3><p>%s</p>',
      htmlspecialchars($axis['heading'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
      htmlspecialchars($axis['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ), $axes));
  }

  /** @return array{value:string,format:string} */
  private function formatted(string $value): array {
    return ['value' => $value, 'format' => 'basic_html'];
  }

  private function loadAuthor(): UserInterface {
    $author = $this->entityTypeManager->getStorage('user')->load(self::AUTHOR_UID);
    if (!$author instanceof UserInterface || !$author->isActive()) {
      throw new RuntimeException('Required PREPROD author uid 1 is unavailable.');
    }
    return $author;
  }

  private function assertEntityValid(ContentEntityInterface $entity, string $label): void {
    $violations = $entity->validate();
    if ($violations->count() > 0) {
      throw new RuntimeException(sprintf('%s validation failed: %s', $label, (string) $violations));
    }
  }

  private function revisionMessage(): string {
    return sprintf('#1059 homepage EN Brand parity / profile=%s / sha256=%s', self::PROFILE, self::PROFILE_SHA256);
  }

  private function assertProfileHash(): void {
    $canonical = json_encode($this->canonicalize($this->profilePayload()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (!hash_equals(self::PROFILE_SHA256, hash('sha256', $canonical))) {
      throw new RuntimeException('Homepage Brand #1059 closed profile hash mismatch.');
    }
  }

  /** @return array<string,mixed> */
  private function profilePayload(): array {
    return [
      'bundle' => self::BUNDLE,
      'en_target' => [
        'axes' => self::EN_AXES,
        'axes_heading' => self::EN_AXES_HEADING,
        'hero' => [
          'h1' => self::EN_H1,
          'subtitle' => self::EN_SUBTITLE,
          'primary_cta_label' => self::EN_PRIMARY_CTA_LABEL,
          'primary_cta_uri' => self::PRIMARY_CTA_URI,
          'secondary_cta_label' => self::EN_SECONDARY_CTA_LABEL,
          'secondary_cta_uri' => self::SECONDARY_CTA_URI,
        ],
      ],
      'fr_baseline' => [
        'axes' => self::FR_AXES,
        'axes_heading' => self::FR_AXES_HEADING,
        'hero' => [
          'h1' => self::FR_H1,
          'subtitle' => self::FR_SUBTITLE,
          'primary_cta_label' => self::FR_PRIMARY_CTA_LABEL,
          'primary_cta_uri' => self::PRIMARY_CTA_URI,
          'secondary_cta_label' => self::FR_SECONDARY_CTA_LABEL,
          'secondary_cta_uri' => self::SECONDARY_CTA_URI,
        ],
      ],
      'issue_number' => self::ISSUE_NUMBER,
      'language' => self::LANGCODE,
      'node_id' => self::TARGET_NODE_ID,
      'profile' => self::PROFILE,
      'target' => 'PREPROD',
    ];
  }

  private function canonicalize(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (array_is_list($value)) {
      return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
    ksort($value);
    foreach ($value as $key => $item) {
      $value[$key] = $this->canonicalize($item);
    }
    return $value;
  }

  /** @return array<string,mixed> */
  private function result(string $verdict, string $mode, NodeInterface $node, array $snapshot): array {
    $translations = $snapshot['translations'];
    return [
      'status' => 'PASS',
      'verdict' => $verdict,
      'mode' => $mode,
      'profile' => self::PROFILE,
      'profile_sha256' => self::PROFILE_SHA256,
      'issue_number' => self::ISSUE_NUMBER,
      'target' => 'PREPROD',
      'bundle' => self::BUNDLE,
      'language' => self::LANGCODE,
      'front' => $snapshot['front'],
      'node' => [
        'id' => (int) $node->id(),
        'uuid' => $node->uuid(),
        'revision_id' => (int) $node->getRevisionId(),
        'published' => $node->isPublished(),
      ],
      'en_node_translation' => $translations['node'],
      'en_hero_translation' => $translations['hero'],
      'en_axes_translation' => $translations['axes'],
      'fr_mutated' => 'NO',
      'content_sync' => 'RELEASED_UNCHANGED',
      'prod_access' => 'NONE',
      'prod_write' => 'NONE',
    ];
  }
}

$mode = getenv('AGENCY_HOMEPAGE_BRAND_1059_MODE') ?: '';
$resultPath = getenv('AGENCY_HOMEPAGE_BRAND_1059_RESULT_PATH') ?: '';

$writeResult = static function (array $result) use ($resultPath): void {
  if ($resultPath === '') {
    throw new RuntimeException('AGENCY_HOMEPAGE_BRAND_1059_RESULT_PATH is required.');
  }
  file_put_contents($resultPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
};

try {
  if (!in_array($mode, ['inspect', 'dry-run', 'apply'], TRUE)) {
    throw new InvalidArgumentException('Unsupported AGENCY_HOMEPAGE_BRAND_1059_MODE.');
  }
  $candidate = AgencyHomepageBrand1059::fromContainer(\Drupal::getContainer());
  $result = match ($mode) {
    'inspect' => $candidate->inspect(),
    'dry-run' => $candidate->dryRun(),
    'apply' => $candidate->apply(),
  };
  $writeResult($result);
}
catch (Throwable $exception) {
  $writeResult([
    'status' => 'FAIL',
    'verdict' => 'FAIL_CLOSED',
    'mode' => $mode,
    'profile' => 'homepage-brand-1059',
    'profile_sha256' => 'e7f6e184a31048b4fcea7f126b6e24e9aa23db46522cd2537f441fd2f3218390',
    'issue_number' => 1059,
    'target' => 'PREPROD',
    'language' => 'en',
    'fr_mutated' => 'NO',
    'prod_access' => 'NONE',
    'prod_write' => 'NONE',
    'message' => $exception->getMessage(),
  ]);
  exit(1);
}
