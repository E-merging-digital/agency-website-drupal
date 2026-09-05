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
 * Closed PREPROD profile for #1015 homepage Brand materialization.
 *
 * This helper can update only the existing FR translation of node 5. It does
 * not admit the homepage back into Content Sync and is not a generic Page
 * writer.
 */
final class AgencyHomepageBrand1015 {

  private const PROFILE = 'homepage-brand-1015';
  private const ISSUE_NUMBER = 1015;
  private const TARGET_NODE_ID = 5;
  private const BUNDLE = 'page';
  private const LANGCODE = 'fr';
  private const AUTHOR_UID = 1;
  private const CONTENT_ID = 'homepage';
  private const PROFILE_SHA256 = 'bfbc8ac2d56af551509af254c797abe4437b6739b3a1d38ca369309717619da7';
  private const PRIMARY_CTA_URI = 'internal:/contact';
  private const SECONDARY_CTA_URI = 'internal:/services';

  private const HERO_H1 = 'Créer, améliorer ou moderniser votre plateforme web';
  private const HERO_SUBTITLE = 'E-merging Digital accompagne les PME, ASBL et organisations qui lancent un nouveau projet, doivent faire évoluer une plateforme existante ou veulent moderniser un environnement devenu difficile à maintenir. Nous partons du besoin métier avant de choisir les technologies qui le servent.';
  private const PRIMARY_CTA_LABEL = 'Parler de mon projet';
  private const SECONDARY_CTA_LABEL = 'Voir les expertises';

  /** @var array<int, array{heading: string, body: string}> */
  private const AXES = [
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

  /**
   * Inspects node 5 without writing.
   *
   * @return array<string, mixed>
   *   Closed-profile evidence.
   */
  public function inspect(): array {
    return $this->plan('inspect');
  }

  /**
   * Computes the exact mutation without writing.
   *
   * @return array<string, mixed>
   *   Closed-profile evidence.
   */
  public function dryRun(): array {
    return $this->plan('dry-run');
  }

  /**
   * Applies the exact Brand #9 mutation as one Drupal revision.
   *
   * @return array<string, mixed>
   *   Closed-profile evidence.
   */
  public function apply(): array {
    $plan = $this->plan('apply');
    if ($plan['verdict'] === 'IDEMPOTENT') {
      return $plan;
    }
    if ($plan['verdict'] !== 'UPDATE_READY') {
      throw new RuntimeException('Homepage Brand #1015 candidate is not update-ready.');
    }

    $node = $this->loadNode();
    $before = $this->snapshot($node);
    $fr = $this->frTranslation($node);
    $components = $this->components($fr);

    $hero = $this->paragraphInLanguage($components[0]);
    $axes = $this->paragraphInLanguage($components[1]);

    $transaction = $this->database->startTransaction();
    $author = $this->loadAuthor();
    $this->accountSwitcher->switchTo($author);

    try {
      $hero->set('field_heading', self::HERO_H1);
      $hero->set('field_text', $this->formatted(self::HERO_SUBTITLE));
      $hero->set('field_link', [
        'uri' => self::PRIMARY_CTA_URI,
        'title' => self::PRIMARY_CTA_LABEL,
      ]);
      $hero->set('field_secondary_link', [
        'uri' => self::SECONDARY_CTA_URI,
        'title' => self::SECONDARY_CTA_LABEL,
      ]);
      $hero->setNewRevision(TRUE);
      $this->assertEntityValid($hero, 'homepage hero');
      $hero->save();

      $axes->set('field_heading', 'CRÉER / AMÉLIORER / MODERNISER');
      $axes->set('field_text', $this->formatted($this->axesHtml()));
      $axes->setNewRevision(TRUE);
      $this->assertEntityValid($axes, 'homepage axes');
      $axes->save();

      $references = $fr->get('field_home_components')->getValue();
      $references[0] = [
        'target_id' => (int) $hero->id(),
        'target_revision_id' => (int) $hero->getRevisionId(),
      ];
      $references[1] = [
        'target_id' => (int) $axes->id(),
        'target_revision_id' => (int) $axes->getRevisionId(),
      ];
      $fr->set('field_home_components', $references);

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

  /**
   * Validates the closed runtime and returns UPDATE_READY or IDEMPOTENT.
   *
   * @return array<string, mixed>
   *   Closed-profile evidence.
   */
  private function plan(string $mode): array {
    $this->assertProfileHash();
    $node = $this->loadNode();
    $snapshot = $this->snapshot($node);
    $this->assertImmutableRuntimeIdentity($node, $snapshot);
    $this->assertExpectedStructure($node);

    $verdict = $this->matchesRequestedContent($node)
      ? 'IDEMPOTENT'
      : ($mode === 'inspect' ? 'INSPECTED' : 'UPDATE_READY');

    return $this->result($verdict, $mode, $node, $snapshot);
  }

  private function loadNode(): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load(self::TARGET_NODE_ID);
    if (!$node instanceof NodeInterface) {
      throw new RuntimeException('Required existing homepage node 5 is unavailable.');
    }
    return $node;
  }

  private function frTranslation(NodeInterface $node): NodeInterface {
    if ($node->language()->getId() === self::LANGCODE) {
      return $node;
    }
    if (!$node->hasTranslation(self::LANGCODE)) {
      throw new RuntimeException('Homepage node 5 has no FR translation.');
    }
    $translation = $node->getTranslation(self::LANGCODE);
    if (!$translation instanceof NodeInterface) {
      throw new RuntimeException('Homepage FR translation is invalid.');
    }
    return $translation;
  }

  /**
   * @return array<int, ParagraphInterface>
   *   Existing ordered homepage Paragraph entities.
   */
  private function components(NodeInterface $fr): array {
    $components = $fr->get('field_home_components')->referencedEntities();
    foreach ($components as $component) {
      if (!$component instanceof ParagraphInterface) {
        throw new RuntimeException('Homepage contains a non-Paragraph component reference.');
      }
    }
    return array_values($components);
  }

  private function paragraphInLanguage(ParagraphInterface $paragraph): ParagraphInterface {
    if ($paragraph->language()->getId() === self::LANGCODE) {
      return $paragraph;
    }
    if (!$paragraph->hasTranslation(self::LANGCODE)) {
      throw new RuntimeException(sprintf(
        'Homepage Paragraph %s has no FR translation.',
        (string) $paragraph->id(),
      ));
    }
    $translation = $paragraph->getTranslation(self::LANGCODE);
    if (!$translation instanceof ParagraphInterface) {
      throw new RuntimeException('Homepage Paragraph FR translation is invalid.');
    }
    return $translation;
  }

  private function assertExpectedStructure(NodeInterface $node): void {
    if ($node->bundle() !== self::BUNDLE) {
      throw new RuntimeException('Homepage node 5 is not a Page bundle.');
    }

    $definitions = $this->entityFieldManager->getFieldDefinitions('node', self::BUNDLE);
    if (!isset($definitions['field_home_components'])) {
      throw new RuntimeException('Page field_home_components is unavailable.');
    }

    $fr = $this->frTranslation($node);
    $components = $this->components($fr);
    if (count($components) < 3) {
      throw new RuntimeException('Homepage component sequence is shorter than the closed profile requires.');
    }

    if ($components[0]->bundle() !== 'hero' || $components[1]->bundle() !== 'text_block') {
      throw new RuntimeException('Homepage must begin with existing hero then text_block components.');
    }

    $heroFields = $this->entityFieldManager->getFieldDefinitions('paragraph', 'hero');
    foreach (['field_heading', 'field_text', 'field_link', 'field_secondary_link'] as $field) {
      if (!isset($heroFields[$field])) {
        throw new RuntimeException(sprintf('Homepage hero field %s is unavailable.', $field));
      }
    }

    $textFields = $this->entityFieldManager->getFieldDefinitions('paragraph', 'text_block');
    foreach (['field_heading', 'field_text'] as $field) {
      if (!isset($textFields[$field])) {
        throw new RuntimeException(sprintf('Homepage text_block field %s is unavailable.', $field));
      }
    }

    $hero = $this->paragraphInLanguage($components[0]);
    $primary = $hero->get('field_link')->first()?->getValue() ?? [];
    $secondary = $hero->get('field_secondary_link')->first()?->getValue() ?? [];
    if (($primary['uri'] ?? NULL) !== self::PRIMARY_CTA_URI) {
      throw new RuntimeException('Existing homepage primary CTA destination is not the approved contact route.');
    }
    if (($secondary['uri'] ?? NULL) !== self::SECONDARY_CTA_URI) {
      throw new RuntimeException('Existing homepage secondary CTA destination is not the approved services route.');
    }
  }

  /**
   * @param array<string, mixed> $snapshot
   *   Pre-write immutable identity snapshot.
   */
  private function assertImmutableRuntimeIdentity(NodeInterface $node, array $snapshot): void {
    if (($snapshot['front'] ?? NULL) !== '/node/5') {
      throw new RuntimeException('system.site:page.front is not /node/5.');
    }

    $mapping = $this->mapping();
    if ($mapping->status() !== ContentSyncMappingRecord::STATUS_RELEASED
      || $mapping->entityType() !== 'node'
      || $mapping->entityId() !== self::TARGET_NODE_ID
      || $mapping->entityUuid() !== $node->uuid()) {
      throw new RuntimeException('Homepage Content Sync mapping is not RELEASED and bound to node 5.');
    }

    if (($snapshot['content_sync']['status'] ?? NULL) !== ContentSyncMappingRecord::STATUS_RELEASED) {
      throw new RuntimeException('Homepage RELEASED ownership snapshot is invalid.');
    }
  }

  private function mapping(): ContentSyncMappingRecord {
    $mapping = $this->mappingRepository->findByContentId(self::CONTENT_ID);
    if (!$mapping instanceof ContentSyncMappingRecord) {
      throw new RuntimeException('Homepage Content Sync mapping is unavailable.');
    }
    return $mapping;
  }

  private function matchesRequestedContent(NodeInterface $node): bool {
    $components = $this->components($this->frTranslation($node));
    $hero = $this->paragraphInLanguage($components[0]);
    $axes = $this->paragraphInLanguage($components[1]);

    $primary = $hero->get('field_link')->first()?->getValue() ?? [];
    $secondary = $hero->get('field_secondary_link')->first()?->getValue() ?? [];

    return (string) $hero->get('field_heading')->value === self::HERO_H1
      && (string) $hero->get('field_text')->value === self::HERO_SUBTITLE
      && ($primary['uri'] ?? NULL) === self::PRIMARY_CTA_URI
      && ($primary['title'] ?? NULL) === self::PRIMARY_CTA_LABEL
      && ($secondary['uri'] ?? NULL) === self::SECONDARY_CTA_URI
      && ($secondary['title'] ?? NULL) === self::SECONDARY_CTA_LABEL
      && (string) $axes->get('field_heading')->value === 'CRÉER / AMÉLIORER / MODERNISER'
      && (string) $axes->get('field_text')->value === $this->axesHtml();
  }

  /**
   * @param array<string, mixed> $before
   *   Immutable pre-write snapshot.
   * @param array<string, mixed> $after
   *   Immutable post-write snapshot.
   */
  private function assertConverged(NodeInterface $node, array $before, array $after): void {
    if (!$this->matchesRequestedContent($node)) {
      throw new RuntimeException('Homepage Brand #9 values did not converge after apply.');
    }

    foreach (['node_id', 'node_uuid', 'front', 'aliases', 'menu', 'content_sync'] as $key) {
      if (($before[$key] ?? NULL) !== ($after[$key] ?? NULL)) {
        throw new RuntimeException(sprintf('Homepage immutable %s changed during apply.', $key));
      }
    }

    $beforeComponents = $before['components'] ?? [];
    $afterComponents = $after['components'] ?? [];
    if (count($beforeComponents) !== count($afterComponents)) {
      throw new RuntimeException('Homepage component count changed during apply.');
    }
    foreach ($beforeComponents as $index => $component) {
      if (($component['id'] ?? NULL) !== ($afterComponents[$index]['id'] ?? NULL)
        || ($component['bundle'] ?? NULL) !== ($afterComponents[$index]['bundle'] ?? NULL)) {
        throw new RuntimeException('Homepage component identity/order changed during apply.');
      }
      if ($index >= 2 && $component !== $afterComponents[$index]) {
        throw new RuntimeException('Downstream homepage technical/proof component changed during apply.');
      }
    }
  }

  /**
   * @return array<string, mixed>
   *   Immutable/runtime state required by #1015.
   */
  private function snapshot(NodeInterface $node): array {
    $fr = $this->frTranslation($node);
    $components = $this->components($fr);
    $mapping = $this->mapping();

    return [
      'node_id' => (int) $node->id(),
      'node_uuid' => $node->uuid(),
      'bundle' => $node->bundle(),
      'language' => self::LANGCODE,
      'front' => (string) \Drupal::config('system.site')->get('page.front'),
      'aliases' => $this->aliasSnapshot(),
      'menu' => $this->menuSnapshot(),
      'content_sync' => $mapping->toDatabaseRow(),
      'components' => array_map(
        static fn (ParagraphInterface $paragraph): array => [
          'id' => (int) $paragraph->id(),
          'revision_id' => (int) $paragraph->getRevisionId(),
          'uuid' => $paragraph->uuid(),
          'bundle' => $paragraph->bundle(),
        ],
        $components,
      ),
    ];
  }

  /**
   * @return array<int, array<string, int|string>>
   *   Ordered path alias snapshot for node 5.
   */
  private function aliasSnapshot(): array {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('path', '/node/' . self::TARGET_NODE_ID)
      ->sort('id')
      ->execute();

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

  /**
   * @return array<int, array<string, mixed>>
   *   Ordered main-menu snapshot.
   */
  private function menuSnapshot(): array {
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('menu_name', 'main')
      ->sort('id')
      ->execute();

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

  private function axesHtml(): string {
    $html = [];
    foreach (self::AXES as $axis) {
      $html[] = sprintf(
        '<h3>%s</h3><p>%s</p>',
        htmlspecialchars($axis['heading'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($axis['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
      );
    }
    return implode('', $html);
  }

  /**
   * @return array{value: string, format: string}
   *   Drupal formatted text value.
   */
  private function formatted(string $value): array {
    return [
      'value' => $value,
      'format' => 'basic_html',
    ];
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
      throw new RuntimeException(sprintf(
        '%s validation failed: %s',
        $label,
        (string) $violations,
      ));
    }
  }

  private function revisionMessage(): string {
    return sprintf(
      '#1015 homepage Brand #9 / profile=%s / sha256=%s',
      self::PROFILE,
      self::PROFILE_SHA256,
    );
  }

  private function assertProfileHash(): void {
    $canonical = json_encode(
      $this->canonicalize($this->profilePayload()),
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";
    if (!hash_equals(self::PROFILE_SHA256, hash('sha256', $canonical))) {
      throw new RuntimeException('Homepage Brand #1015 closed profile hash mismatch.');
    }
  }

  /**
   * @return array<string, mixed>
   *   Exact fixed profile payload used only for identity/hash verification.
   */
  private function profilePayload(): array {
    return [
      'axes' => self::AXES,
      'bundle' => self::BUNDLE,
      'hero' => [
        'h1' => self::HERO_H1,
        'primary_cta_label' => self::PRIMARY_CTA_LABEL,
        'primary_cta_uri' => self::PRIMARY_CTA_URI,
        'secondary_cta_label' => self::SECONDARY_CTA_LABEL,
        'secondary_cta_uri' => self::SECONDARY_CTA_URI,
        'subtitle' => self::HERO_SUBTITLE,
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

  /**
   * @param array<string, mixed> $snapshot
   *   Current immutable/runtime snapshot.
   *
   * @return array<string, mixed>
   *   Non-sensitive execution result.
   */
  private function result(string $verdict, string $mode, NodeInterface $node, array $snapshot): array {
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
      'content_sync' => 'RELEASED_UNCHANGED',
      'content_sync_mapping' => $snapshot['content_sync'],
      'aliases' => $snapshot['aliases'],
      'menu_unchanged_contract' => 'REQUIRED',
      'component_count' => count($snapshot['components']),
      'node' => [
        'id' => (int) $node->id(),
        'uuid' => $node->uuid(),
        'revision_id' => (int) $node->getRevisionId(),
        'published' => $node->isPublished(),
      ],
      'en' => [
        'decision' => 'DEFER',
        'reason' => 'CURRENT FR PREPROD HUMAN REVIEW DOD DOES NOT REQUIRE NEW TRANSLATION CAPABILITY',
      ],
      'prod_write' => 'NONE',
    ];
  }

}
