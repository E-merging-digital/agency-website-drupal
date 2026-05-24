<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Kernel;

use Drupal\emerging_digital_content\ContentSync\Entity\ContentSyncMappingRecord;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests targeted Content Sync writes from the YAML catalog.
 *
 * @group emerging_digital_content
 */
#[RunTestsInSeparateProcesses]
final class ContentSyncManagerTargetedWriteTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
    'default_content',
    'emerging_digital_content',
    'entity_reference_revisions',
    'field',
    'file',
    'filter',
    'hal',
    'language',
    'link',
    'node',
    'paragraphs',
    'path',
    'path_alias',
    'serialization',
    'system',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('emerging_digital_content', ['emerging_digital_content_sync_mapping']);
    $this->installConfig(['filter', 'node', 'system']);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('en')->save();
    $this->config('system.site')
      ->set('langcode', 'fr')
      ->set('default_langcode', 'fr')
      ->save();

    NodeType::create([
      'type' => 'service',
      'name' => 'Service',
    ])->save();

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    NodeType::create([
      'type' => 'ai_feature',
      'name' => 'AI Feature',
    ])->save();

    NodeType::create([
      'type' => 'case_client',
      'name' => 'Cas client',
    ])->save();

    foreach (['hero', 'text_block', 'services', 'ai_features', 'trust_list', 'case_clients', 'cta'] as $paragraph_type) {
      ParagraphsType::create([
        'id' => $paragraph_type,
        'label' => $paragraph_type,
      ])->save();
    }

    $this->createNodeTextLongField('field_short_description', ['service', 'ai_feature', 'case_client'], TRUE);
    $this->createNodeTextLongField('field_detailed_description', ['service', 'ai_feature', 'case_client'], FALSE);
    $this->createNodeTextLongField('field_customer_benefit', ['ai_feature'], FALSE);
    $this->createNodeTextLongField('field_concrete_example', ['ai_feature'], FALSE);
    $this->createNodeTextLongField('field_use_cases', ['ai_feature'], FALSE, FieldStorageConfig::CARDINALITY_UNLIMITED);
    $this->createNodeReferenceField('field_related_services', ['case_client'], ['service']);
    $this->createNodeReferenceField('field_related_ai_features', ['case_client'], ['ai_feature']);
    $this->createHomeComponentsField();
    $this->createParagraphField('field_heading', 'string', [
      'hero',
      'text_block',
      'services',
      'ai_features',
      'trust_list',
      'case_clients',
    ]);
    $this->createParagraphField('field_text', 'text_long', ['hero', 'text_block', 'ai_features', 'case_clients', 'cta']);
    $this->createParagraphField('field_items', 'text_long', ['services', 'ai_features', 'trust_list', 'case_clients']);
    $this->createParagraphField('field_case_problem', 'text_long', ['case_clients']);
    $this->createParagraphField('field_case_solution', 'text_long', ['case_clients']);
    $this->createParagraphField('field_case_result', 'text_long', ['case_clients']);
    $this->createParagraphField('field_link', 'link', ['cta']);
  }

  /**
   * Tests dry-run safety, targeted create/update and mapping idempotence.
   */
  public function testTargetedSyncCreatesTranslationsAliasesAndMapping(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $dry_run = $manager->sync('agence-drupal-belgique', TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertFalse($mapping_repository->exists('agence-drupal-belgique'));
    self::assertSame(0, $this->countServiceNodes());

    $first_apply = $manager->sync('agence-drupal-belgique', FALSE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(1, $this->countServiceNodes());

    $node = $this->loadOnlyServiceNode();
    self::assertSame('fr', $node->language()->getId());
    self::assertSame('Agence Drupal Belgique', $node->label());
    self::assertTrue($node->hasTranslation('en'));
    self::assertStringContainsString(
      'Emerging Digital accompagne',
      (string) $node->get('field_detailed_description')->value,
    );
    $this->assertLocalizedInternalLinks(
      (string) $node->get('field_detailed_description')->value,
      'fr',
      [
        '/fr/services',
        '/fr/ia-drupal',
        '/fr/creation-site-drupal',
        '/fr/refonte-site-drupal',
        '/fr/migration-drupal',
        '/fr/maintenance-drupal',
        '/fr/audit-drupal',
        '/fr/accessibilite-seo-optimisation',
        '/fr/ia-integree',
      ],
    );

    $english = $node->getTranslation('en');
    self::assertSame('Drupal Agency Belgium', $english->label());
    self::assertStringContainsString(
      'Emerging Digital supports Belgian organisations',
      (string) $english->get('field_detailed_description')->value,
    );
    $this->assertLocalizedInternalLinks(
      (string) $english->get('field_detailed_description')->value,
      'en',
      [
        '/en/services',
        '/en/ai-drupal',
        '/en/drupal-website-creation',
        '/en/drupal-website-redesign',
        '/en/drupal-migration',
        '/en/drupal-maintenance',
        '/en/drupal-audit',
        '/en/ai-accessibility-seo-optimization',
        '/en/integrated-ai',
      ],
    );

    $alias_manager = $this->container->get('path_alias.manager');
    $alias_manager->cacheClear('/node/' . $node->id());
    self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias('/agence-drupal-belgique', 'fr'));
    self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias('/drupal-agency-belgium', 'en'));

    $mapping = $mapping_repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($mapping);
    self::assertSame((int) $node->id(), $mapping->entityId());
    self::assertSame($node->uuid(), $mapping->entityUuid());
    self::assertSame('created', $mapping->lastAction());

    $second_apply = $manager->sync('agence-drupal-belgique', FALSE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countServiceNodes());

    $updated_mapping = $mapping_repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($updated_mapping);
    self::assertSame($mapping->id(), $updated_mapping->id());
    self::assertSame('updated', $updated_mapping->lastAction());
  }

  /**
   * Tests the new service landing pages create translations and mappings.
   */
  public function testServiceLandingPagesSyncCreateTranslationsAliasesAndMappings(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');
    $alias_manager = $this->container->get('path_alias.manager');

    $pages = [
      'creation-site-drupal' => [
        'fr_title' => 'Création de site Drupal',
        'en_title' => 'Drupal Website Creation',
        'fr_alias' => '/creation-site-drupal',
        'en_alias' => '/drupal-website-creation',
        'fr_text' => 'création Drupal structurée',
        'en_text' => 'structured Drupal build',
        'fr_required_links' => [
          '/fr/services',
          '/fr/ia-drupal',
          '/fr/agence-drupal-belgique',
          '/fr/maintenance-drupal',
        ],
        'en_required_links' => [
          '/en/services',
          '/en/ai-drupal',
          '/en/drupal-agency-belgium',
          '/en/drupal-maintenance',
        ],
      ],
      'maintenance-drupal' => [
        'fr_title' => 'Maintenance Drupal',
        'en_title' => 'Drupal Maintenance',
        'fr_alias' => '/maintenance-drupal',
        'en_alias' => '/drupal-maintenance',
        'fr_text' => 'maintenance Drupal orientée continuité',
        'en_text' => 'Drupal maintenance focused on continuity',
        'fr_required_links' => [
          '/fr/services',
          '/fr/ia-drupal',
          '/fr/agence-drupal-belgique',
          '/fr/creation-site-drupal',
        ],
        'en_required_links' => [
          '/en/services',
          '/en/ai-drupal',
          '/en/drupal-agency-belgium',
          '/en/drupal-website-creation',
        ],
      ],
      'migration-drupal' => [
        'fr_title' => 'Migration Drupal',
        'en_title' => 'Drupal Migration',
        'fr_alias' => '/migration-drupal',
        'en_alias' => '/drupal-migration',
        'fr_text' => 'migration Drupal préparée',
        'en_text' => 'prepared and controlled Drupal migration',
        'fr_required_links' => [
          '/fr/services',
          '/fr/ia-drupal',
          '/fr/agence-drupal-belgique',
          '/fr/audit-drupal',
          '/fr/refonte-site-drupal',
          '/fr/maintenance-drupal',
        ],
        'en_required_links' => [
          '/en/services',
          '/en/ai-drupal',
          '/en/drupal-agency-belgium',
          '/en/drupal-audit',
          '/en/drupal-website-redesign',
          '/en/drupal-maintenance',
        ],
      ],
      'refonte-site-drupal' => [
        'fr_title' => 'Refonte de site Drupal',
        'en_title' => 'Drupal Website Redesign',
        'fr_alias' => '/refonte-site-drupal',
        'en_alias' => '/drupal-website-redesign',
        'fr_text' => 'refonte Drupal qui protège',
        'en_text' => 'Drupal redesign that protects',
        'fr_required_links' => [
          '/fr/services',
          '/fr/ia-drupal',
          '/fr/agence-drupal-belgique',
          '/fr/audit-drupal',
          '/fr/maintenance-drupal',
        ],
        'en_required_links' => [
          '/en/services',
          '/en/ai-drupal',
          '/en/drupal-agency-belgium',
          '/en/drupal-audit',
          '/en/drupal-maintenance',
        ],
      ],
      'audit-drupal' => [
        'fr_title' => 'Audit Drupal',
        'en_title' => 'Drupal Audit',
        'fr_alias' => '/audit-drupal',
        'en_alias' => '/drupal-audit',
        'fr_text' => 'audit Drupal actionnable',
        'en_text' => 'actionable Drupal audit',
        'fr_required_links' => [
          '/fr/services',
          '/fr/ia-drupal',
          '/fr/agence-drupal-belgique',
          '/fr/refonte-site-drupal',
          '/fr/migration-drupal',
          '/fr/maintenance-drupal',
        ],
        'en_required_links' => [
          '/en/services',
          '/en/ai-drupal',
          '/en/drupal-agency-belgium',
          '/en/drupal-website-redesign',
          '/en/drupal-migration',
          '/en/drupal-maintenance',
        ],
      ],
      'accessibilite-seo-optimisation' => [
        'fr_title' => 'Accessibilité, SEO et optimisation',
        'en_title' => 'Accessibility, SEO and Optimization',
        'fr_alias' => '/accessibilite-seo-optimisation',
        'en_alias' => '/ai-accessibility-seo-optimization',
        'fr_text' => 'optimisation Drupal lisible et mesurable',
        'en_text' => 'Clear and measurable Drupal optimization',
        'fr_required_links' => [
          '/fr/services',
          '/fr/ia-drupal',
          '/fr/audit-drupal',
          '/fr/refonte-site-drupal',
          '/fr/maintenance-drupal',
          '/fr/creation-site-drupal',
        ],
        'en_required_links' => [
          '/en/services',
          '/en/ai-drupal',
          '/en/drupal-audit',
          '/en/drupal-website-redesign',
          '/en/drupal-maintenance',
          '/en/drupal-website-creation',
        ],
      ],
      'ia-integree' => [
        'fr_title' => 'IA intégrée',
        'en_title' => 'Integrated AI',
        'fr_alias' => '/ia-integree',
        'en_alias' => '/integrated-ai',
        'fr_text' => 'IA Drupal utile et gouvernable',
        'en_text' => 'Useful and governed Drupal AI',
        'fr_required_links' => [
          '/fr/services',
          '/fr/ia-drupal',
          '/fr/accessibilite-seo-optimisation',
          '/fr/creation-site-drupal',
          '/fr/maintenance-drupal',
          '/fr/audit-drupal',
        ],
        'en_required_links' => [
          '/en/services',
          '/en/ai-drupal',
          '/en/ai-accessibility-seo-optimization',
          '/en/drupal-website-creation',
          '/en/drupal-maintenance',
          '/en/drupal-audit',
        ],
      ],
    ];

    $expected_count = 0;
    foreach ($pages as $content_id => $expected) {
      $dry_run = $manager->sync($content_id, TRUE);
      self::assertSame([], $dry_run['errors']);
      self::assertFalse($mapping_repository->exists($content_id));

      $first_apply = $manager->sync($content_id, FALSE);
      self::assertSame([], $first_apply['errors']);
      self::assertSame(++$expected_count, $this->countServiceNodes());

      $mapping = $mapping_repository->findByContentId($content_id);
      self::assertNotNull($mapping);
      self::assertSame('created', $mapping->lastAction());

      $node = $this->loadMappedNodeByContentId($content_id);
      self::assertSame('service', $node->bundle());
      self::assertSame('fr', $node->language()->getId());
      self::assertSame($expected['fr_title'], $node->label());
      self::assertTrue($node->hasTranslation('en'));
      self::assertStringContainsString(
        $expected['fr_text'],
        (string) $node->get('field_detailed_description')->value,
      );
      $this->assertLocalizedInternalLinks(
        (string) $node->get('field_detailed_description')->value,
        'fr',
        $expected['fr_required_links'],
      );

      $english = $node->getTranslation('en');
      self::assertSame($expected['en_title'], $english->label());
      self::assertStringContainsString(
        $expected['en_text'],
        (string) $english->get('field_detailed_description')->value,
      );
      $this->assertLocalizedInternalLinks(
        (string) $english->get('field_detailed_description')->value,
        'en',
        $expected['en_required_links'],
      );

      $alias_manager->cacheClear('/node/' . $node->id());
      self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias($expected['fr_alias'], 'fr'));
      self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias($expected['en_alias'], 'en'));

      $second_apply = $manager->sync($content_id, FALSE);
      self::assertSame([], $second_apply['errors']);
      self::assertSame($expected_count, $this->countServiceNodes());
      self::assertSame($mapping->id(), $mapping_repository->findByContentId($content_id)?->id());
      self::assertSame('updated', $mapping_repository->findByContentId($content_id)?->lastAction());
    }
  }

  /**
   * Tests AI Feature content sync creates translated nodes and mappings.
   */
  public function testAiFeatureSyncCreatesTranslatedNodesAliasesAndMappings(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $expected_features = [
      'ai-automatisation-contenu-drupal' => [
        'fr_title' => 'Automatisation de contenu Drupal',
        'en_title' => 'Drupal content automation',
        'fr_alias' => '/ia-drupal/automatisation-contenu-drupal',
        'en_alias' => '/ai-drupal/drupal-content-automation',
        'fr_link' => '/fr/services',
        'en_link' => '/en/services',
      ],
      'ai-generation-multilingue' => [
        'fr_title' => 'Génération multilingue',
        'en_title' => 'Multilingual generation',
        'fr_alias' => '/ia-drupal/generation-multilingue',
        'en_alias' => '/ai-drupal/multilingual-generation',
        'fr_link' => '/fr/ia-integree',
        'en_link' => '/en/integrated-ai',
      ],
      'ai-chatbot-qualification' => [
        'fr_title' => 'Chatbot de qualification',
        'en_title' => 'Qualification chatbot',
        'fr_alias' => '/ia-drupal/chatbot-qualification',
        'en_alias' => '/ai-drupal/qualification-chatbot',
        'fr_link' => '/fr/services',
        'en_link' => '/en/services',
      ],
      'ai-audit-intelligent' => [
        'fr_title' => 'Audit intelligent',
        'en_title' => 'Intelligent audit',
        'fr_alias' => '/ia-drupal/audit-intelligent',
        'en_alias' => '/ai-drupal/intelligent-audit',
        'fr_link' => '/fr/audit-drupal',
        'en_link' => '/en/drupal-audit',
      ],
      'ai-redaction-assistee' => [
        'fr_title' => 'Rédaction assistée',
        'en_title' => 'Assisted writing',
        'fr_alias' => '/ia-drupal/redaction-assistee',
        'en_alias' => '/ai-drupal/assisted-writing',
        'fr_link' => '/fr/ia-drupal',
        'en_link' => '/en/ai-drupal',
      ],
      'ai-correction-editoriale' => [
        'fr_title' => 'Correction éditoriale',
        'en_title' => 'Editorial review',
        'fr_alias' => '/ia-drupal/correction-editoriale',
        'en_alias' => '/ai-drupal/editorial-review',
        'fr_link' => '/fr/accessibilite-seo-optimisation',
        'en_link' => '/en/ai-accessibility-seo-optimization',
      ],
      'ai-traduction-fr-en' => [
        'fr_title' => 'Préparation traduction FR/EN',
        'en_title' => 'FR/EN translation preparation',
        'fr_alias' => '/ia-drupal/traduction-fr-en',
        'en_alias' => '/ai-drupal/fr-en-translation',
        'fr_link' => '/fr/ia-integree',
        'en_link' => '/en/integrated-ai',
      ],
      'ai-resumes-tags-structure' => [
        'fr_title' => 'Résumés, tags et structure',
        'en_title' => 'Summaries, tags and structure',
        'fr_alias' => '/ia-drupal/resumes-tags-structure',
        'en_alias' => '/ai-drupal/summaries-tags-structure',
        'fr_link' => '/fr/migration-drupal',
        'en_link' => '/en/drupal-migration',
      ],
      'ai-seo-liens-internes' => [
        'fr_title' => 'Suggestions SEO et liens internes',
        'en_title' => 'SEO suggestions and internal links',
        'fr_alias' => '/ia-drupal/seo-liens-internes',
        'en_alias' => '/ai-drupal/seo-internal-links',
        'fr_link' => '/fr/services',
        'en_link' => '/en/services',
      ],
      'ai-gouvernance-validation' => [
        'fr_title' => 'Gouvernance et validation IA',
        'en_title' => 'AI governance and approval',
        'fr_alias' => '/ia-drupal/gouvernance-validation',
        'en_alias' => '/ai-drupal/governance-approval',
        'fr_link' => '/fr/ia-drupal',
        'en_link' => '/en/ai-drupal',
      ],
    ];

    foreach ($expected_features as $content_id => $expected) {
      $dry_run = $manager->sync($content_id, TRUE);
      self::assertSame([], $dry_run['errors']);
      self::assertFalse($mapping_repository->exists($content_id));

      $first_apply = $manager->sync($content_id, FALSE);
      self::assertSame([], $first_apply['errors']);

      $node = $this->loadMappedNodeByContentId($content_id);
      self::assertSame('ai_feature', $node->bundle());
      self::assertSame('fr', $node->language()->getId());
      self::assertSame($expected['fr_title'], $node->label());
      self::assertTrue($node->hasTranslation('en'));
      self::assertNotEmpty((string) $node->get('field_short_description')->value);
      self::assertNotEmpty((string) $node->get('field_customer_benefit')->value);
      self::assertNotEmpty((string) $node->get('field_concrete_example')->value);
      self::assertCount(3, $node->get('field_use_cases'));
      self::assertStringContainsString('Workflow IA crédible', (string) $node->get('field_detailed_description')->value);
      $this->assertLocalizedInternalLinks(
        (string) $node->get('field_detailed_description')->value,
        'fr',
        [$expected['fr_link'], '/fr/contact'],
      );

      $english = $node->getTranslation('en');
      self::assertSame($expected['en_title'], $english->label());
      self::assertNotEmpty((string) $english->get('field_short_description')->value);
      self::assertNotEmpty((string) $english->get('field_customer_benefit')->value);
      self::assertNotEmpty((string) $english->get('field_concrete_example')->value);
      self::assertCount(3, $english->get('field_use_cases'));
      self::assertStringContainsString('Credible AI workflow', (string) $english->get('field_detailed_description')->value);
      $this->assertLocalizedInternalLinks(
        (string) $english->get('field_detailed_description')->value,
        'en',
        [$expected['en_link'], '/en/contact'],
      );

      $alias_manager = $this->container->get('path_alias.manager');
      $alias_manager->cacheClear('/node/' . $node->id());
      self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias($expected['fr_alias'], 'fr'));
      self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias($expected['en_alias'], 'en'));

      $mapping = $mapping_repository->findByContentId($content_id);
      self::assertNotNull($mapping);
      self::assertSame((int) $node->id(), $mapping->entityId());
      self::assertSame('created', $mapping->lastAction());

      $second_apply = $manager->sync($content_id, FALSE);
      self::assertSame([], $second_apply['errors']);
      self::assertSame((int) $node->id(), (int) $this->loadMappedNodeByContentId($content_id)->id());
      self::assertSame('updated', $mapping_repository->findByContentId($content_id)?->lastAction());
    }

    self::assertSame(10, $this->countAiFeatureNodes());
  }

  /**
   * Tests case client content sync creates translated nodes and relationships.
   */
  public function testCaseClientSyncCreatesTranslatedNodesAliasesAndRelationships(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');
    $alias_manager = $this->container->get('path_alias.manager');

    foreach ([
      'refonte-site-drupal',
      'audit-drupal',
      'migration-drupal',
      'maintenance-drupal',
      'ia-integree',
      'ia-pour-pme',
      'ai-seo-liens-internes',
      'ai-audit-intelligent',
      'ai-resumes-tags-structure',
      'ai-redaction-assistee',
      'ai-traduction-fr-en',
      'ai-gouvernance-validation',
    ] as $dependency_id) {
      self::assertSame([], $manager->sync($dependency_id, FALSE)['errors']);
    }

    $expected_cases = [
      'cas-client-refonte-drupal-institutionnelle' => [
        'fr_title' => 'Cas client — Refonte Drupal institutionnelle',
        'en_title' => 'Case Study — Institutional Drupal Redesign',
        'fr_alias' => '/cas-clients/refonte-drupal-institutionnelle',
        'en_alias' => '/case-studies/institutional-drupal-redesign',
        'fr_text' => 'refonte Drupal institutionnelle',
        'en_text' => 'institutional Drupal redesign',
        'service_ids' => ['refonte-site-drupal', 'audit-drupal'],
        'ai_feature_ids' => ['ai-seo-liens-internes'],
        'fr_required_links' => [
          '/fr/refonte-site-drupal',
          '/fr/audit-drupal',
          '/fr/services',
          '/fr/contact',
        ],
        'en_required_links' => [
          '/en/drupal-website-redesign',
          '/en/drupal-audit',
          '/en/services',
          '/en/contact',
        ],
      ],
      'cas-client-migration-drupal-11' => [
        'fr_title' => 'Cas client — Migration maîtrisée vers Drupal 11',
        'en_title' => 'Case Study — Controlled Migration to Drupal 11',
        'fr_alias' => '/cas-clients/migration-drupal-11',
        'en_alias' => '/case-studies/drupal-11-migration',
        'fr_text' => 'Migration cadrée',
        'en_text' => 'Migration framed',
        'service_ids' => ['migration-drupal', 'maintenance-drupal', 'audit-drupal'],
        'ai_feature_ids' => ['ai-audit-intelligent', 'ai-resumes-tags-structure'],
        'fr_required_links' => [
          '/fr/migration-drupal',
          '/fr/maintenance-drupal',
          '/fr/audit-drupal',
          '/fr/contact',
        ],
        'en_required_links' => [
          '/en/drupal-migration',
          '/en/drupal-maintenance',
          '/en/drupal-audit',
          '/en/contact',
        ],
      ],
      'cas-client-integration-ia-editoriale' => [
        'fr_title' => 'Cas client — Intégration IA éditoriale',
        'en_title' => 'Case Study — Editorial AI Integration',
        'fr_alias' => '/cas-clients/integration-ia-editoriale',
        'en_alias' => '/case-studies/editorial-ai-integration',
        'fr_text' => 'aide éditoriale intégrée',
        'en_text' => 'editorial assistance inside Drupal',
        'service_ids' => ['ia-integree', 'ia-pour-pme'],
        'ai_feature_ids' => ['ai-redaction-assistee', 'ai-traduction-fr-en', 'ai-gouvernance-validation'],
        'fr_required_links' => [
          '/fr/ia-integree',
          '/fr/ia-drupal',
          '/fr/ia-drupal/redaction-assistee',
          '/fr/ia-drupal/traduction-fr-en',
          '/fr/contact',
        ],
        'en_required_links' => [
          '/en/integrated-ai',
          '/en/ai-drupal',
          '/en/ai-drupal/assisted-writing',
          '/en/ai-drupal/fr-en-translation',
          '/en/contact',
        ],
      ],
    ];

    $expected_count = 0;
    foreach ($expected_cases as $content_id => $expected) {
      $dry_run = $manager->sync($content_id, TRUE);
      self::assertSame([], $dry_run['errors']);
      self::assertFalse($mapping_repository->exists($content_id));

      $first_apply = $manager->sync($content_id, FALSE);
      self::assertSame([], $first_apply['errors']);
      self::assertSame(++$expected_count, $this->countCaseClientNodes());

      $node = $this->loadMappedNodeByContentId($content_id);
      self::assertSame('case_client', $node->bundle());
      self::assertSame('fr', $node->language()->getId());
      self::assertSame($expected['fr_title'], $node->label());
      self::assertTrue($node->hasTranslation('en'));
      self::assertStringContainsString($expected['fr_text'], (string) $node->get('field_detailed_description')->value);
      $this->assertLocalizedInternalLinks(
        (string) $node->get('field_detailed_description')->value,
        'fr',
        $expected['fr_required_links'],
      );

      $english = $node->getTranslation('en');
      self::assertSame($expected['en_title'], $english->label());
      self::assertStringContainsString($expected['en_text'], (string) $english->get('field_detailed_description')->value);
      $this->assertLocalizedInternalLinks(
        (string) $english->get('field_detailed_description')->value,
        'en',
        $expected['en_required_links'],
      );

      self::assertSame(
        $this->nodeIdsForContentIds($expected['service_ids']),
        array_map(
          static fn (NodeInterface $referenced): int => (int) $referenced->id(),
          $node->get('field_related_services')->referencedEntities(),
        ),
      );
      self::assertSame(
        $this->nodeIdsForContentIds($expected['ai_feature_ids']),
        array_map(
          static fn (NodeInterface $referenced): int => (int) $referenced->id(),
          $node->get('field_related_ai_features')->referencedEntities(),
        ),
      );

      $alias_manager->cacheClear('/node/' . $node->id());
      self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias($expected['fr_alias'], 'fr'));
      self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias($expected['en_alias'], 'en'));

      $mapping = $mapping_repository->findByContentId($content_id);
      self::assertNotNull($mapping);
      self::assertSame('created', $mapping->lastAction());

      $second_apply = $manager->sync($content_id, FALSE);
      self::assertSame([], $second_apply['errors']);
      self::assertSame($expected_count, $this->countCaseClientNodes());
      self::assertSame($mapping->id(), $mapping_repository->findByContentId($content_id)?->id());
      self::assertSame('updated', $mapping_repository->findByContentId($content_id)?->lastAction());
    }

    self::assertStringContainsString(
      '/fr/cas-clients/refonte-drupal-institutionnelle',
      (string) $this->loadMappedNodeByContentId('refonte-site-drupal')->get('field_detailed_description')->value,
    );
    self::assertStringContainsString(
      '/fr/cas-clients/migration-drupal-11',
      (string) $this->loadMappedNodeByContentId('ai-audit-intelligent')->get('field_detailed_description')->value,
    );
    self::assertStringContainsString(
      '/fr/cas-clients/integration-ia-editoriale',
      (string) $this->loadMappedNodeByContentId('ai-redaction-assistee')->get('field_detailed_description')->value,
    );
  }

  /**
   * Tests full catalog dry-run safety, apply and idempotence.
   */
  public function testAllSyncCreatesCatalogContentsWithoutDuplication(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $dry_run = $manager->sync('', TRUE, TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertSame('dry_run', $dry_run['summary']['mode']);
    self::assertTrue($dry_run['summary']['all']);
    self::assertTrue($dry_run['summary']['dry_run']);
    self::assertFalse($dry_run['summary']['blocking_errors']);
    self::assertCount(36, $dry_run['content_reports']);
    self::assertSame('agence-drupal-belgique', $dry_run['content_reports'][0]['id']);
    self::assertSame('would create managed entity', $dry_run['content_reports'][0]['planned_operation']);
    self::assertSame('unmapped', $dry_run['content_reports'][0]['mapping_status']);
    self::assertFalse($mapping_repository->exists('agence-drupal-belgique'));
    self::assertSame(0, $this->countServiceNodes());
    self::assertSame(0, $this->countPageNodes());
    self::assertSame(0, $this->countAiFeatureNodes());
    self::assertSame(0, $this->countCaseClientNodes());

    $first_apply = $manager->sync('', FALSE, TRUE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(14, $this->countServiceNodes());
    self::assertSame(9, $this->countPageNodes());
    self::assertSame(10, $this->countAiFeatureNodes());
    self::assertSame(3, $this->countCaseClientNodes());
    self::assertArrayHasKey('content_reports', $first_apply);
    self::assertCount(36, $first_apply['content_reports']);
    self::assertSame('agence-drupal-belgique', $first_apply['content_reports'][0]['id']);
    self::assertSame('creation-site-drupal', $first_apply['content_reports'][1]['id']);
    self::assertSame('maintenance-drupal', $first_apply['content_reports'][2]['id']);
    self::assertSame('migration-drupal', $first_apply['content_reports'][3]['id']);
    self::assertSame('refonte-site-drupal', $first_apply['content_reports'][4]['id']);
    self::assertSame('audit-drupal', $first_apply['content_reports'][5]['id']);
    self::assertSame('accessibilite-seo-optimisation', $first_apply['content_reports'][6]['id']);
    self::assertSame('ia-integree', $first_apply['content_reports'][7]['id']);
    self::assertSame('creation-site-web-professionnel', $first_apply['content_reports'][8]['id']);
    self::assertSame('refonte-site-internet', $first_apply['content_reports'][9]['id']);
    self::assertSame('agence-web-belgique', $first_apply['content_reports'][10]['id']);
    self::assertSame('agence-web-liege', $first_apply['content_reports'][11]['id']);
    self::assertSame('site-web-pme', $first_apply['content_reports'][12]['id']);
    self::assertSame('ia-pour-pme', $first_apply['content_reports'][13]['id']);
    self::assertSame('services', $first_apply['content_reports'][14]['id']);
    self::assertSame('ia-drupal', $first_apply['content_reports'][15]['id']);
    self::assertSame('ai-automatisation-contenu-drupal', $first_apply['content_reports'][16]['id']);
    self::assertSame('ai-generation-multilingue', $first_apply['content_reports'][17]['id']);
    self::assertSame('ai-chatbot-qualification', $first_apply['content_reports'][18]['id']);
    self::assertSame('ai-audit-intelligent', $first_apply['content_reports'][19]['id']);
    self::assertSame('ai-redaction-assistee', $first_apply['content_reports'][20]['id']);
    self::assertSame('ai-correction-editoriale', $first_apply['content_reports'][21]['id']);
    self::assertSame('ai-traduction-fr-en', $first_apply['content_reports'][22]['id']);
    self::assertSame('ai-resumes-tags-structure', $first_apply['content_reports'][23]['id']);
    self::assertSame('ai-seo-liens-internes', $first_apply['content_reports'][24]['id']);
    self::assertSame('ai-gouvernance-validation', $first_apply['content_reports'][25]['id']);
    self::assertSame('cas-client-refonte-drupal-institutionnelle', $first_apply['content_reports'][26]['id']);
    self::assertSame('cas-client-migration-drupal-11', $first_apply['content_reports'][27]['id']);
    self::assertSame('cas-client-integration-ia-editoriale', $first_apply['content_reports'][28]['id']);
    self::assertSame('cas-clients', $first_apply['content_reports'][29]['id']);
    self::assertSame('equipe', $first_apply['content_reports'][30]['id']);
    self::assertSame('contact', $first_apply['content_reports'][31]['id']);
    self::assertSame('mentions-legales', $first_apply['content_reports'][32]['id']);
    self::assertSame('politique-confidentialite', $first_apply['content_reports'][33]['id']);
    self::assertSame('politique-cookies', $first_apply['content_reports'][34]['id']);
    self::assertSame('homepage', $first_apply['content_reports'][35]['id']);

    $mapping = $mapping_repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($mapping);
    self::assertSame('created', $mapping->lastAction());
    self::assertNotNull($mapping_repository->findByContentId('creation-site-drupal'));
    self::assertNotNull($mapping_repository->findByContentId('maintenance-drupal'));
    self::assertNotNull($mapping_repository->findByContentId('migration-drupal'));
    self::assertNotNull($mapping_repository->findByContentId('refonte-site-drupal'));
    self::assertNotNull($mapping_repository->findByContentId('audit-drupal'));
    self::assertNotNull($mapping_repository->findByContentId('accessibilite-seo-optimisation'));
    self::assertNotNull($mapping_repository->findByContentId('ia-integree'));
    self::assertNotNull($mapping_repository->findByContentId('creation-site-web-professionnel'));
    self::assertNotNull($mapping_repository->findByContentId('refonte-site-internet'));
    self::assertNotNull($mapping_repository->findByContentId('agence-web-belgique'));
    self::assertNotNull($mapping_repository->findByContentId('agence-web-liege'));
    self::assertNotNull($mapping_repository->findByContentId('site-web-pme'));
    self::assertNotNull($mapping_repository->findByContentId('ia-pour-pme'));
    self::assertNotNull($mapping_repository->findByContentId('ai-automatisation-contenu-drupal'));
    self::assertNotNull($mapping_repository->findByContentId('ai-generation-multilingue'));
    self::assertNotNull($mapping_repository->findByContentId('ai-chatbot-qualification'));
    self::assertNotNull($mapping_repository->findByContentId('ai-audit-intelligent'));
    self::assertNotNull($mapping_repository->findByContentId('ai-redaction-assistee'));
    self::assertNotNull($mapping_repository->findByContentId('ai-correction-editoriale'));
    self::assertNotNull($mapping_repository->findByContentId('ai-traduction-fr-en'));
    self::assertNotNull($mapping_repository->findByContentId('ai-resumes-tags-structure'));
    self::assertNotNull($mapping_repository->findByContentId('ai-seo-liens-internes'));
    self::assertNotNull($mapping_repository->findByContentId('ai-gouvernance-validation'));
    self::assertNotNull($mapping_repository->findByContentId('cas-client-refonte-drupal-institutionnelle'));
    self::assertNotNull($mapping_repository->findByContentId('cas-client-migration-drupal-11'));
    self::assertNotNull($mapping_repository->findByContentId('cas-client-integration-ia-editoriale'));
    self::assertNotNull($mapping_repository->findByContentId('cas-clients'));
    self::assertNotNull($mapping_repository->findByContentId('equipe'));
    self::assertNotNull($mapping_repository->findByContentId('contact'));
    self::assertNotNull($mapping_repository->findByContentId('homepage'));

    $services_items = $this->serviceCardItems($this->loadMappedNodeByContentId('services'), 'fr');
    self::assertCount(18, $services_items);
    self::assertContains(
      'Création de site Drupal|Conception et développement de sites Drupal '
      . 'clairs, accessibles, performants et prêts pour le SEO.|/fr/creation-site-drupal',
      $services_items,
    );
    self::assertContains(
      'Maintenance Drupal|Mises à jour, sécurité, support et amélioration '
      . 'continue pour garder votre site Drupal fiable.|/fr/maintenance-drupal',
      $services_items,
    );
    self::assertContains(
      'Migration Drupal|Migration Drupal vers Drupal 11 avec audit, reprise '
      . 'de contenu, tests et sécurisation du socle technique.|/fr/migration-drupal',
      $services_items,
    );
    self::assertContains(
      "Refonte de site Drupal|Refonte Drupal pour clarifier les contenus, "
      . "moderniser l'expérience et protéger le SEO existant.|/fr/refonte-site-drupal",
      $services_items,
    );
    self::assertContains(
      'Audit Drupal|Audit technique, SEO, performance, accessibilité et '
      . 'éditorial pour prioriser les bonnes corrections Drupal.|/fr/audit-drupal',
      $services_items,
    );
    self::assertContains(
      'Accessibilité, SEO et optimisation|Lisibilité, référencement naturel, '
      . 'accessibilité et performance pour des pages Drupal plus utiles.|/fr/accessibilite-seo-optimisation',
      $services_items,
    );
    self::assertContains(
      'IA intégrée|Automatisation éditoriale utile, qualité des contenus, '
      . 'traduction et gouvernance des usages IA dans Drupal.|/fr/ia-integree',
      $services_items,
    );

    $services_en_items = $this->serviceCardItems($this->loadMappedNodeByContentId('services'), 'en');
    self::assertCount(18, $services_en_items);
    self::assertContains(
      'Drupal Website Creation|Design and development of clear, accessible, '
      . 'performant Drupal websites ready for SEO.|/en/drupal-website-creation',
      $services_en_items,
    );
    self::assertContains(
      'Drupal Maintenance|Updates, security, support and continuous '
      . 'improvement to keep your Drupal website reliable.|/en/drupal-maintenance',
      $services_en_items,
    );
    self::assertContains(
      'Drupal Migration|Drupal migration to Drupal 11 with audit, content '
      . 'migration, testing and a secure technical foundation.|/en/drupal-migration',
      $services_en_items,
    );
    self::assertContains(
      'Drupal Website Redesign|Drupal redesign to clarify content, modernise '
      . 'the experience and protect existing SEO value.|/en/drupal-website-redesign',
      $services_en_items,
    );
    self::assertContains(
      'Drupal Audit|Technical, SEO, performance, accessibility and editorial '
      . 'audit to prioritise the right Drupal fixes.|/en/drupal-audit',
      $services_en_items,
    );
    self::assertContains(
      'Accessibility, SEO, and optimization|Readability, organic search, '
      . 'accessibility and performance for more useful Drupal pages.|/en/ai-accessibility-seo-optimization',
      $services_en_items,
    );
    self::assertContains(
      'Integrated AI|Useful editorial automation, content quality, translation '
      . 'and governed AI use cases in Drupal.|/en/integrated-ai',
      $services_en_items,
    );

    $homepage = $this->loadMappedNodeByContentId('homepage');
    $homepage_components = $homepage->get('field_home_components')
      ->referencedEntities();
    self::assertSame(
      ['hero', 'text_block', 'text_block'],
      array_map(
        static fn (Paragraph $paragraph): string => $paragraph->bundle(),
        array_slice($homepage_components, 0, 3),
      ),
    );
    self::assertStringContainsString(
      'Expertise principale :',
      $homepage_components[1]->getTranslation('fr')->get('field_text')->value,
    );
    self::assertStringContainsString(
      'Drupal • PHP sur mesure • Symfony • Laravel • Magento',
      $homepage_components[1]->getTranslation('fr')->get('field_text')->value,
    );

    $homepage_en_components = $homepage->getTranslation('en')
      ->get('field_home_components')
      ->referencedEntities();
    self::assertStringContainsString(
      'Core expertise:',
      $homepage_en_components[1]->getTranslation('en')->get('field_text')->value,
    );
    self::assertStringContainsString(
      'Drupal • Custom PHP • Symfony • Laravel • Magento',
      $homepage_en_components[1]->getTranslation('en')->get('field_text')->value,
    );

    $homepage_items = $this->serviceCardItems($homepage, 'fr');
    self::assertCount(10, $homepage_items);
    self::assertContains(
      'Création de site Drupal|Un site Drupal conçu pour vos contenus, '
      . 'vos équipes et votre référencement naturel.|/fr/creation-site-drupal',
      $homepage_items,
    );
    self::assertContains(
      'Maintenance Drupal|Un accompagnement technique régulier pour sécuriser '
      . 'et faire évoluer votre site Drupal.|/fr/maintenance-drupal',
      $homepage_items,
    );
    self::assertContains(
      'Migration et modernisation|Reprise de sites existants, montée de '
      . 'version Drupal, amélioration de la structure et des performances.|/fr/migration-drupal',
      $homepage_items,
    );
    self::assertContains(
      'Refonte de site Drupal|Une refonte Drupal pour remettre contenus, '
      . 'parcours et socle technique au service de vos objectifs.|/fr/refonte-site-drupal',
      $homepage_items,
    );
    self::assertContains(
      'Audit Drupal|Un diagnostic Drupal clair pour comprendre les risques '
      . 'et prioriser les actions utiles.|/fr/audit-drupal',
      $homepage_items,
    );
    self::assertContains(
      'Accessibilité, SEO et performance|Contenus lisibles, parcours clairs et '
      . 'socle technique optimisé pour vos publics et les moteurs de recherche.|/fr/accessibilite-seo-optimisation',
      $homepage_items,
    );
    self::assertContains(
      'IA intégrée dans le CMS|Aide à la rédaction, amélioration de la qualité '
      . 'éditoriale, enrichissement et préparation à la traduction automatique des contenus.|/fr/ia-integree',
      $homepage_items,
    );

    $homepage_en_items = $this->serviceCardItems($this->loadMappedNodeByContentId('homepage'), 'en');
    self::assertCount(10, $homepage_en_items);
    self::assertContains(
      'Migration and modernization|Resumption of existing sites, Drupal '
      . 'version upgrade, improvement of structure and performance.|/en/drupal-migration',
      $homepage_en_items,
    );
    self::assertContains(
      'Drupal Website Redesign|A Drupal redesign to realign content, journeys '
      . 'and the technical foundation with your goals.|/en/drupal-website-redesign',
      $homepage_en_items,
    );
    self::assertContains(
      'Drupal Audit|A clear Drupal diagnosis to understand risks and prioritise '
      . 'useful actions.|/en/drupal-audit',
      $homepage_en_items,
    );
    self::assertContains(
      'Accessibility, SEO, and performance|Readable content, clear pathways, '
      . 'and a technical foundation optimized for your audiences and search engines.|/en/ai-accessibility-seo-optimization',
      $homepage_en_items,
    );
    self::assertContains(
      'AI integrated into the CMS|Writing assistance, improvement of editorial '
      . 'quality, enrichment and preparation for automatic translation of content.|/en/integrated-ai',
      $homepage_en_items,
    );

    $second_apply = $manager->sync('', FALSE, TRUE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(14, $this->countServiceNodes());
    self::assertSame(9, $this->countPageNodes());
    self::assertSame(10, $this->countAiFeatureNodes());
    self::assertSame(3, $this->countCaseClientNodes());

    $updated_mapping = $mapping_repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($updated_mapping);
    self::assertSame($mapping->id(), $updated_mapping->id());
    self::assertSame('updated', $updated_mapping->lastAction());
  }

  /**
   * Tests Services page sync creates translated paragraphs in stable order.
   */
  public function testServicesPageSyncCreatesTranslatedParagraphsWithoutDuplication(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $dry_run = $manager->sync('services', TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertFalse($mapping_repository->exists('services'));
    self::assertSame(0, $this->countPageNodes());
    self::assertSame(0, $this->countParagraphs());

    $first_apply = $manager->sync('services', FALSE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(5, $this->countParagraphs());

    $page = $this->loadOnlyPageNode();
    self::assertSame('fr', $page->language()->getId());
    self::assertSame('Services', $page->label());
    self::assertTrue($page->hasTranslation('en'));
    self::assertSame('Services', $page->getTranslation('en')->label());

    $alias_manager = $this->container->get('path_alias.manager');
    $alias_manager->cacheClear('/node/' . $page->id());
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/services', 'fr'));
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/services', 'en'));

    $paragraphs = $page->get('field_home_components')->referencedEntities();
    self::assertCount(5, $paragraphs);
    self::assertSame(
      ['hero', 'text_block', 'services', 'text_block', 'cta'],
      array_map(static fn ($paragraph): string => $paragraph->bundle(), $paragraphs),
    );
    self::assertSame(
      'Services web senior, avec Drupal comme socle premium',
      $paragraphs[0]->get('field_heading')->value,
    );
    self::assertSame('Trois parcours pour cadrer votre projet', $paragraphs[2]->get('field_heading')->value);
    self::assertCount(18, $paragraphs[2]->get('field_items'));
    self::assertSame(
      'Pourquoi Drupal pour des projets exigeants',
      $paragraphs[3]->get('field_heading')->value,
    );
    self::assertSame('Qualifier mon projet', $paragraphs[4]->get('field_link')->title);

    $english_paragraphs = $page->getTranslation('en')->get('field_home_components')->referencedEntities();
    self::assertCount(5, $english_paragraphs);
    self::assertSame(
      'Senior web services, with Drupal as the premium foundation',
      $english_paragraphs[0]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Three paths to frame your project',
      $english_paragraphs[2]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertCount(18, $english_paragraphs[2]->getTranslation('en')->get('field_items'));
    self::assertSame(
      'Qualify my project',
      $english_paragraphs[4]->getTranslation('en')->get('field_link')->title,
    );

    $mapping = $mapping_repository->findByContentId('services');
    self::assertNotNull($mapping);
    self::assertSame((int) $page->id(), $mapping->entityId());
    self::assertSame('created', $mapping->lastAction());
    self::assertNotNull($mapping_repository->findByContentId('services.grid'));

    $component_ids = array_map(static fn ($paragraph): int => (int) $paragraph->id(), $paragraphs);
    $second_apply = $manager->sync('services', FALSE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(5, $this->countParagraphs());
    self::assertSame($component_ids, array_map(
      static fn ($paragraph): int => (int) $paragraph->id(),
      $this->loadOnlyPageNode()->get('field_home_components')->referencedEntities(),
    ));
    self::assertSame('updated', $mapping_repository->findByContentId('services')?->lastAction());
  }

  /**
   * Tests IA & Drupal page sync creates translated paragraphs in stable order.
   */
  public function testIaDrupalPageSyncCreatesTranslatedParagraphsWithoutDuplication(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $dry_run = $manager->sync('ia-drupal', TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertFalse($mapping_repository->exists('ia-drupal'));
    self::assertSame(0, $this->countPageNodes());
    self::assertSame(0, $this->countParagraphs());

    $first_apply = $manager->sync('ia-drupal', FALSE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(6, $this->countParagraphs());

    $page = $this->loadOnlyPageNode();
    self::assertSame('fr', $page->language()->getId());
    self::assertSame('IA & Drupal', $page->label());
    self::assertTrue($page->hasTranslation('en'));
    self::assertSame('AI & Drupal', $page->getTranslation('en')->label());

    $alias_manager = $this->container->get('path_alias.manager');
    $alias_manager->cacheClear('/node/' . $page->id());
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/ia-drupal', 'fr'));
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/ai-drupal', 'en'));

    $paragraphs = $page->get('field_home_components')->referencedEntities();
    self::assertCount(6, $paragraphs);
    self::assertSame(
      ['hero', 'text_block', 'ai_features', 'trust_list', 'text_block', 'cta'],
      array_map(static fn ($paragraph): string => $paragraph->bundle(), $paragraphs),
    );
    self::assertSame('IA utile dans Drupal pour les équipes éditoriales', $paragraphs[0]->get('field_heading')->value);
    self::assertSame('Cas d’usage IA dans Drupal', $paragraphs[2]->get('field_heading')->value);
    self::assertCount(10, $paragraphs[2]->get('field_items'));
    self::assertContains(
      'Automatisation de contenu Drupal|Automatiser les tâches éditoriales '
      . 'répétitives dans Drupal avec des suggestions IA relues, traçables et '
      . 'validées.|/fr/ia-drupal/automatisation-contenu-drupal',
      $this->paragraphItems($paragraphs[2], 'fr'),
    );
    self::assertSame('Bénéfices', $paragraphs[3]->get('field_heading')->value);
    self::assertCount(4, $paragraphs[3]->get('field_items'));
    self::assertSame('Intégration dans vos processus CMS', $paragraphs[4]->get('field_heading')->value);
    self::assertSame('Prendre contact', $paragraphs[5]->get('field_link')->title);

    $english_paragraphs = $page->getTranslation('en')->get('field_home_components')->referencedEntities();
    self::assertCount(6, $english_paragraphs);
    self::assertSame(
      'Useful AI in Drupal for editorial teams',
      $english_paragraphs[0]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'AI use cases in Drupal',
      $english_paragraphs[2]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertCount(10, $english_paragraphs[2]->getTranslation('en')->get('field_items'));
    self::assertContains(
      'Drupal content automation|Automate repetitive editorial tasks in Drupal '
      . 'with reviewed, traceable and approved AI suggestions.|/en/ai-drupal/drupal-content-automation',
      $this->paragraphItems($english_paragraphs[2], 'en'),
    );
    self::assertSame(
      'Benefits',
      $english_paragraphs[3]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Integration into your CMS processes',
      $english_paragraphs[4]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Get in touch',
      $english_paragraphs[5]->getTranslation('en')->get('field_link')->title,
    );

    $mapping = $mapping_repository->findByContentId('ia-drupal');
    self::assertNotNull($mapping);
    self::assertSame((int) $page->id(), $mapping->entityId());
    self::assertSame('created', $mapping->lastAction());
    self::assertNotNull($mapping_repository->findByContentId('ia-drupal.features'));
    self::assertNotNull($mapping_repository->findByContentId('ia-drupal.benefits'));

    $component_ids = array_map(static fn ($paragraph): int => (int) $paragraph->id(), $paragraphs);
    $second_apply = $manager->sync('ia-drupal', FALSE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(6, $this->countParagraphs());
    self::assertSame($component_ids, array_map(
      static fn ($paragraph): int => (int) $paragraph->id(),
      $this->loadOnlyPageNode()->get('field_home_components')->referencedEntities(),
    ));
    self::assertSame('updated', $mapping_repository->findByContentId('ia-drupal')?->lastAction());
  }

  /**
   * Tests Cas clients page sync creates translated paragraphs in stable order.
   */
  public function testCasClientsPageSyncCreatesTranslatedParagraphsWithoutDuplication(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $dry_run = $manager->sync('cas-clients', TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertFalse($mapping_repository->exists('cas-clients'));
    self::assertSame(0, $this->countPageNodes());
    self::assertSame(0, $this->countParagraphs());

    $first_apply = $manager->sync('cas-clients', FALSE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(4, $this->countParagraphs());

    $page = $this->loadOnlyPageNode();
    self::assertSame('fr', $page->language()->getId());
    self::assertSame('Cas clients', $page->label());
    self::assertTrue($page->hasTranslation('en'));
    self::assertSame('Case studies', $page->getTranslation('en')->label());

    $alias_manager = $this->container->get('path_alias.manager');
    $alias_manager->cacheClear('/node/' . $page->id());
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/cas-clients', 'fr'));
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/case-studies', 'en'));

    $paragraphs = $page->get('field_home_components')->referencedEntities();
    self::assertCount(4, $paragraphs);
    self::assertSame(
      ['hero', 'text_block', 'case_clients', 'cta'],
      array_map(static fn ($paragraph): string => $paragraph->bundle(), $paragraphs),
    );
    self::assertSame('Cas clients : des transformations web durables', $paragraphs[0]->get('field_heading')->value);
    self::assertStringContainsString('ne sont pas un portfolio technique', (string) $paragraphs[1]->get('field_text')->value);
    self::assertCount(3, $paragraphs[2]->get('field_items'));
    self::assertSame('Trois transformations représentatives', $paragraphs[2]->get('field_heading')->value);
    self::assertSame('Repositionnement business d\'une présence Drupal', $paragraphs[2]->get('field_items')->first()->value);
    self::assertStringContainsString('niveau d\'expertise Drupal', $paragraphs[2]->get('field_case_problem')->first()->value);
    self::assertStringContainsString('/fr/agence-drupal-belgique', $paragraphs[2]->get('field_case_solution')->first()->value);
    self::assertStringContainsString('visibilité Drupal', $paragraphs[2]->get('field_case_result')->first()->value);
    self::assertSame('Parler de votre projet', $paragraphs[3]->get('field_link')->title);

    $english_paragraphs = $page->getTranslation('en')->get('field_home_components')->referencedEntities();
    self::assertCount(4, $english_paragraphs);
    self::assertSame(
      'Case studies: durable web transformations',
      $english_paragraphs[0]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertStringContainsString(
      'not a technical portfolio',
      (string) $english_paragraphs[1]->getTranslation('en')->get('field_text')->value,
    );
    self::assertSame(
      'Three representative transformations',
      $english_paragraphs[2]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Business repositioning of a Drupal presence',
      $english_paragraphs[2]->getTranslation('en')->get('field_items')->first()->value,
    );
    self::assertStringContainsString(
      'level of Drupal expertise',
      $english_paragraphs[2]->getTranslation('en')->get('field_case_problem')->first()->value,
    );
    self::assertStringContainsString(
      '/en/drupal-agency-belgium',
      $english_paragraphs[2]->getTranslation('en')->get('field_case_solution')->first()->value,
    );
    self::assertStringContainsString(
      'Drupal visibility',
      $english_paragraphs[2]->getTranslation('en')->get('field_case_result')->first()->value,
    );
    self::assertSame(
      'Discuss your project',
      $english_paragraphs[3]->getTranslation('en')->get('field_link')->title,
    );

    $mapping = $mapping_repository->findByContentId('cas-clients');
    self::assertNotNull($mapping);
    self::assertSame((int) $page->id(), $mapping->entityId());
    self::assertSame('created', $mapping->lastAction());
    self::assertNotNull($mapping_repository->findByContentId('cas-clients.case-studies'));

    $component_ids = array_map(static fn ($paragraph): int => (int) $paragraph->id(), $paragraphs);
    $second_apply = $manager->sync('cas-clients', FALSE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(4, $this->countParagraphs());
    self::assertSame($component_ids, array_map(
      static fn ($paragraph): int => (int) $paragraph->id(),
      $this->loadOnlyPageNode()->get('field_home_components')->referencedEntities(),
    ));
    self::assertSame('updated', $mapping_repository->findByContentId('cas-clients')?->lastAction());
  }

  /**
   * Tests Contact page sync creates translated paragraphs in stable order.
   */
  public function testContactPageSyncCreatesTranslatedParagraphsWithoutDuplication(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $dry_run = $manager->sync('contact', TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertFalse($mapping_repository->exists('contact'));
    self::assertSame(0, $this->countPageNodes());
    self::assertSame(0, $this->countParagraphs());

    $first_apply = $manager->sync('contact', FALSE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(7, $this->countParagraphs());

    $page = $this->loadOnlyPageNode();
    self::assertSame('fr', $page->language()->getId());
    self::assertSame('Contact', $page->label());
    self::assertTrue($page->hasTranslation('en'));
    self::assertSame('Contact', $page->getTranslation('en')->label());

    $alias_manager = $this->container->get('path_alias.manager');
    $alias_manager->cacheClear('/node/' . $page->id());
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/contact', 'fr'));
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/contact', 'en'));

    $paragraphs = $page->get('field_home_components')->referencedEntities();
    self::assertCount(7, $paragraphs);
    self::assertSame(
      ['hero', 'text_block', 'text_block', 'text_block', 'text_block', 'text_block', 'text_block'],
      array_map(static fn ($paragraph): string => $paragraph->bundle(), $paragraphs),
    );
    self::assertSame('Un projet Drupal à cadrer ? Parlons-en simplement', $paragraphs[0]->get('field_heading')->value);
    self::assertStringContainsString('Contactez E-merging Digital', (string) $paragraphs[0]->get('field_text')->value);
    self::assertSame('Choisir un contexte de qualification', $paragraphs[1]->get('field_heading')->value);
    self::assertStringContainsString('id="qualification-contact"', (string) $paragraphs[1]->get('field_text')->value);
    self::assertStringContainsString('/fr/contact?type=audit', (string) $paragraphs[1]->get('field_text')->value);
    self::assertSame('Qualifier votre demande', $paragraphs[2]->get('field_heading')->value);
    self::assertStringContainsString('Ne transmettez pas de mots de passe', (string) $paragraphs[2]->get('field_text')->value);
    self::assertSame('Vous cherchez le bon point d’entrée ?', $paragraphs[3]->get('field_heading')->value);
    self::assertStringContainsString('/fr/audit-drupal', (string) $paragraphs[3]->get('field_text')->value);
    self::assertSame('Un premier échange utile et sans engagement', $paragraphs[4]->get('field_heading')->value);
    self::assertStringContainsString('deux jours ouvrables', (string) $paragraphs[4]->get('field_text')->value);
    self::assertSame('Coordonnées', $paragraphs[5]->get('field_heading')->value);
    self::assertStringContainsString('contact@emergingdigital.be', (string) $paragraphs[5]->get('field_text')->value);
    self::assertSame('Carte', $paragraphs[6]->get('field_heading')->value);
    self::assertStringContainsString('Localisation Emerging Digital', (string) $paragraphs[6]->get('field_text')->value);

    $english_paragraphs = $page->getTranslation('en')->get('field_home_components')->referencedEntities();
    self::assertCount(7, $english_paragraphs);
    self::assertSame(
      'A Drupal project to frame? Let’s make it clear',
      $english_paragraphs[0]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Choose a qualification context',
      $english_paragraphs[1]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertStringContainsString(
      '/en/contact?type=audit',
      (string) $english_paragraphs[1]->getTranslation('en')->get('field_text')->value,
    );
    self::assertSame(
      'Qualify your request',
      $english_paragraphs[2]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertStringContainsString(
      'Do not send passwords',
      (string) $english_paragraphs[2]->getTranslation('en')->get('field_text')->value,
    );
    self::assertStringContainsString(
      '/en/ai-drupal',
      (string) $english_paragraphs[3]->getTranslation('en')->get('field_text')->value,
    );
    self::assertStringContainsString(
      'within two business days',
      (string) $english_paragraphs[4]->getTranslation('en')->get('field_text')->value,
    );
    self::assertSame(
      'Contact details',
      $english_paragraphs[5]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Map',
      $english_paragraphs[6]->getTranslation('en')->get('field_heading')->value,
    );

    $mapping = $mapping_repository->findByContentId('contact');
    self::assertNotNull($mapping);
    self::assertSame((int) $page->id(), $mapping->entityId());
    self::assertSame('created', $mapping->lastAction());
    self::assertNotNull($mapping_repository->findByContentId('contact.coordinates'));
    self::assertNotNull($mapping_repository->findByContentId('contact.form'));
    self::assertNotNull($mapping_repository->findByContentId('contact.map'));
    self::assertNotNull($mapping_repository->findByContentId('contact.qualification_paths'));

    $component_ids = array_map(static fn ($paragraph): int => (int) $paragraph->id(), $paragraphs);
    $second_apply = $manager->sync('contact', FALSE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(7, $this->countParagraphs());
    self::assertSame($component_ids, array_map(
      static fn ($paragraph): int => (int) $paragraph->id(),
      $this->loadOnlyPageNode()->get('field_home_components')->referencedEntities(),
    ));
    self::assertSame('updated', $mapping_repository->findByContentId('contact')?->lastAction());
  }

  /**
   * Tests legal page sync preserves historical UUIDs, translations and aliases.
   */
  public function testLegalPagesSyncCreateTranslatedParagraphsWithoutDuplication(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $pages = [
      'mentions-legales' => [
        'uuid' => '0705e972-2817-4e71-a29e-659bbe78ab73',
        'component_id' => 'mentions-legales.content',
        'paragraph_uuid' => 'd88c477b-b568-4353-8c4a-e159644325bf',
        'fr_title' => 'Mentions légales',
        'en_title' => 'Legal Notices',
        'fr_alias' => '/mentions-legales',
        'en_alias' => '/legal-notices',
        'fr_text' => 'Éditeur du site',
        'en_text' => 'Site Publisher',
      ],
      'politique-confidentialite' => [
        'uuid' => '72c950fa-49bc-440b-a778-47b4b33d5735',
        'component_id' => 'politique-confidentialite.content',
        'paragraph_uuid' => '50c557e0-a74e-4124-acb2-115ab84e402b',
        'fr_title' => 'Politique de confidentialité',
        'en_title' => 'Privacy Policy',
        'fr_alias' => '/politique-de-confidentialite',
        'en_alias' => '/privacy-policy',
        'fr_text' => 'données personnelles',
        'en_text' => 'personal data',
      ],
      'politique-cookies' => [
        'uuid' => '8eb854c8-d7cc-4235-aa79-18f428325a8b',
        'component_id' => 'politique-cookies.content',
        'paragraph_uuid' => '4629b8a1-71cd-4298-9d0c-e668e27f6e99',
        'fr_title' => 'Politique de cookies',
        'en_title' => 'Cookie Policy',
        'fr_alias' => '/politique-de-cookies',
        'en_alias' => '/cookie-policy',
        'fr_text' => 'utilise des cookies',
        'en_text' => 'uses cookies',
      ],
    ];

    foreach ($pages as $content_id => $expected) {
      $this->createLegacyLegalPage($expected);

      $dry_run = $manager->sync($content_id, TRUE);
      self::assertSame([], $dry_run['errors']);
      self::assertFalse($mapping_repository->exists($content_id));

      $first_apply = $manager->sync($content_id, FALSE);
      self::assertSame([], $first_apply['errors']);

      $mapping = $mapping_repository->findByContentId($content_id);
      self::assertNotNull($mapping);
      self::assertSame($expected['uuid'], $mapping->entityUuid());
      self::assertSame('updated', $mapping->lastAction());

      $node = $this->loadPageNodeByUuid($expected['uuid']);
      self::assertSame('fr', $node->language()->getId());
      self::assertSame($expected['fr_title'], $node->label());
      self::assertTrue($node->hasTranslation('en'));
      self::assertSame($expected['en_title'], $node->getTranslation('en')->label());

      $alias_manager = $this->container->get('path_alias.manager');
      $alias_manager->cacheClear('/node/' . $node->id());
      self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias($expected['fr_alias'], 'fr'));
      self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias($expected['en_alias'], 'en'));

      $paragraphs = $node->get('field_home_components')->referencedEntities();
      self::assertCount(1, $paragraphs);
      self::assertSame('text_block', $paragraphs[0]->bundle());
      self::assertSame($expected['paragraph_uuid'], $paragraphs[0]->uuid());
      self::assertSame($expected['fr_title'], $paragraphs[0]->get('field_heading')->value);
      self::assertStringContainsString($expected['fr_text'], (string) $paragraphs[0]->get('field_text')->value);
      self::assertSame($expected['en_title'], $paragraphs[0]->getTranslation('en')->get('field_heading')->value);
      self::assertStringContainsString($expected['en_text'], (string) $paragraphs[0]->getTranslation('en')->get('field_text')->value);
      self::assertNotNull($mapping_repository->findByContentId($expected['component_id']));

      $component_ids = array_map(static fn ($paragraph): int => (int) $paragraph->id(), $paragraphs);
      $second_apply = $manager->sync($content_id, FALSE);
      self::assertSame([], $second_apply['errors']);
      self::assertSame($component_ids, array_map(
        static fn ($paragraph): int => (int) $paragraph->id(),
        $this->loadPageNodeByUuid($expected['uuid'])->get('field_home_components')->referencedEntities(),
      ));
      self::assertSame('updated', $mapping_repository->findByContentId($content_id)?->lastAction());
    }
  }

  /**
   * Tests prune only touches active managed nodes absent from catalog.
   */
  public function testPruneUnpublishDryRunAndApplyAreScopedToManagedNodes(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $catalog_apply = $manager->sync('', FALSE, TRUE);
    self::assertSame([], $catalog_apply['errors']);

    $obsolete_node = $this->createPublishedServiceNode('Obsolete managed service');
    $manual_node = $this->createPublishedServiceNode('Manual unmanaged service');
    $mapping_repository->createOrUpdate(new ContentSyncMappingRecord(
      'obsolete-service',
      'node',
      (int) $obsolete_node->id(),
      $obsolete_node->uuid(),
      'fr',
      str_repeat('c', 64),
      1_700_000_000,
      'updated',
      'active',
    ));

    $dry_run = $manager->sync('', TRUE, TRUE, 'unpublish');
    self::assertSame([], $dry_run['errors']);
    self::assertStringContainsString(
      sprintf('would unpublish managed node:%d', (int) $obsolete_node->id()),
      implode("\n", $dry_run['actions']),
    );
    self::assertTrue($this->reloadNode($obsolete_node)->isPublished());
    self::assertTrue($this->reloadNode($manual_node)->isPublished());
    self::assertSame('updated', $mapping_repository->findByContentId('obsolete-service')?->lastAction());

    $apply = $manager->sync('', FALSE, TRUE, 'unpublish');
    self::assertSame([], $apply['errors']);
    self::assertStringContainsString(
      sprintf('unpublished managed node:%d', (int) $obsolete_node->id()),
      implode("\n", $apply['actions']),
    );
    self::assertFalse($this->reloadNode($obsolete_node)->isPublished());
    self::assertTrue($this->reloadNode($manual_node)->isPublished());

    $obsolete_mapping = $mapping_repository->findByContentId('obsolete-service');
    self::assertNotNull($obsolete_mapping);
    self::assertSame('unpublished', $obsolete_mapping->lastAction());
    self::assertSame('unpublished', $obsolete_mapping->status());
  }

  /**
   * Tests production prune apply requires an explicit environment flag.
   */
  public function testProductionPruneUnpublishApplyRequiresEnvironmentFlag(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');

    $previous_app_env = getenv('APP_ENV');
    $previous_allow_prune = getenv('CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH');

    putenv('APP_ENV=production');
    putenv('CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH');

    try {
      $dry_run = $manager->sync('', TRUE, TRUE, 'unpublish');
      self::assertSame([], $dry_run['errors']);
      self::assertSame('unpublish', $dry_run['summary']['prune']);

      try {
        $manager->sync('', FALSE, TRUE, 'unpublish');
        self::fail('Production prune apply should require CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH=1.');
      }
      catch (\InvalidArgumentException $exception) {
        self::assertSame(
          'Content Sync --prune=unpublish is blocked in production unless CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH=1 is set.',
          $exception->getMessage(),
        );
      }

      putenv('CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH=1');
      $apply = $manager->sync('', FALSE, TRUE, 'unpublish');
      self::assertSame([], $apply['errors']);
      self::assertSame('unpublish', $apply['summary']['prune']);
    }
    finally {
      $this->restoreEnvironmentVariable('APP_ENV', $previous_app_env);
      $this->restoreEnvironmentVariable('CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH', $previous_allow_prune);
    }
  }

  /**
   * Tests blocking errors are exposed in the final structured summary.
   */
  public function testBlockingErrorsAreSummarized(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');

    $report = $manager->sync('unknown-content-id', TRUE);

    self::assertNotSame([], $report['errors']);
    self::assertTrue($report['summary']['blocking_errors']);
    self::assertSame(1, $report['summary']['errors']);
  }

  /**
   * Tests prune cannot be used outside the explicit safe --all mode.
   */
  public function testPruneRejectsTargetedAndUnsupportedModes(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Content Sync prune mode requires --all.');
    $manager->sync('agence-drupal-belgique', TRUE, FALSE, 'unpublish');
  }

  /**
   * Tests delete prune mode is not implemented.
   */
  public function testPruneDeleteIsRejected(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Only "unpublish" is available.');
    $manager->sync('', TRUE, TRUE, 'delete');
  }

  /**
   * Creates one translatable text_long field on node bundles.
   *
   * @param string $field_name
   *   Field machine name.
   * @param list<string> $bundles
   *   Node bundle IDs.
   * @param bool $required
   *   Whether the field is required.
   * @param int $cardinality
   *   Field cardinality.
   */
  private function createNodeTextLongField(
    string $field_name,
    array $bundles,
    bool $required,
    int $cardinality = 1,
  ): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'text_long',
      'translatable' => TRUE,
      'cardinality' => $cardinality,
    ])->save();

    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $field_name,
        'required' => $required,
        'translatable' => TRUE,
      ])->save();
    }
  }

  /**
   * Creates a translatable node reference field.
   *
   * @param string $field_name
   *   Field machine name.
   * @param list<string> $bundles
   *   Source node bundle IDs.
   * @param list<string> $target_bundles
   *   Target node bundle IDs.
   */
  private function createNodeReferenceField(string $field_name, array $bundles, array $target_bundles): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'translatable' => TRUE,
      'settings' => [
        'target_type' => 'node',
      ],
    ])->save();

    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $field_name,
        'required' => FALSE,
        'translatable' => TRUE,
        'settings' => [
          'handler' => 'default:node',
          'handler_settings' => [
            'target_bundles' => array_combine($target_bundles, $target_bundles),
          ],
        ],
      ])->save();
    }
  }

  /**
   * Creates the translatable page components reference field.
   */
  private function createHomeComponentsField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_home_components',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'translatable' => TRUE,
      'settings' => [
        'target_type' => 'paragraph',
      ],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_home_components',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Composants de page',
      'required' => FALSE,
      'translatable' => TRUE,
      'settings' => [
        'handler' => 'default:paragraph',
      ],
    ])->save();
  }

  /**
   * Creates a translatable paragraph field on the requested paragraph bundles.
   *
   * @param string $field_name
   *   Field machine name.
   * @param string $type
   *   Field storage type.
   * @param list<string> $bundles
   *   Paragraph bundle IDs.
   */
  private function createParagraphField(string $field_name, string $type, array $bundles): void {
    $storage = [
      'field_name' => $field_name,
      'entity_type' => 'paragraph',
      'type' => $type,
      'cardinality' => $field_name === 'field_heading' ? 1 : FieldStorageConfig::CARDINALITY_UNLIMITED,
      'translatable' => TRUE,
    ];
    if ($type === 'string') {
      $storage['settings'] = [
        'max_length' => 255,
      ];
    }

    FieldStorageConfig::create($storage)->save();

    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'paragraph',
        'bundle' => $bundle,
        'label' => $field_name,
        'required' => FALSE,
        'translatable' => TRUE,
      ])->save();
    }
  }

  /**
   * Counts service nodes without applying access checks.
   */
  private function countServiceNodes(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service')
      ->count()
      ->execute();
  }

  /**
   * Counts page nodes without applying access checks.
   */
  private function countPageNodes(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page')
      ->count()
      ->execute();
  }

  /**
   * Counts AI Feature nodes without applying access checks.
   */
  private function countAiFeatureNodes(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'ai_feature')
      ->count()
      ->execute();
  }

  /**
   * Counts case client nodes without applying access checks.
   */
  private function countCaseClientNodes(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'case_client')
      ->count()
      ->execute();
  }

  /**
   * Counts paragraphs without applying access checks.
   */
  private function countParagraphs(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('paragraph')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Loads the only service node created by the targeted sync.
   */
  private function loadOnlyServiceNode(): NodeInterface {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service')
      ->execute();

    $node = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->load((int) reset($ids));
    self::assertInstanceOf(NodeInterface::class, $node);

    return $node;
  }

  /**
   * Loads the only page node created by the targeted sync.
   */
  private function loadOnlyPageNode(): NodeInterface {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page')
      ->execute();

    $node = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->load((int) reset($ids));
    self::assertInstanceOf(NodeInterface::class, $node);

    return $node;
  }

  /**
   * Loads one page node by UUID.
   */
  private function loadPageNodeByUuid(string $uuid): NodeInterface {
    $node = $this->container->get('entity.repository')->loadEntityByUuid('node', $uuid);
    self::assertInstanceOf(NodeInterface::class, $node);

    return $node;
  }

  /**
   * Loads a managed node through its Content Sync mapping.
   */
  private function loadMappedNodeByContentId(string $content_id): NodeInterface {
    $mapping = $this->container
      ->get('emerging_digital_content.content_sync_mapping_repository')
      ->findByContentId($content_id);
    self::assertNotNull($mapping);

    $node = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->load($mapping->entityId());
    self::assertInstanceOf(NodeInterface::class, $node);

    return $node;
  }

  /**
   * Returns mapped node IDs for content identifiers.
   *
   * @param list<string> $content_ids
   *   Content Sync identifiers.
   *
   * @return list<int>
   *   Mapped node IDs in the requested order.
   */
  private function nodeIdsForContentIds(array $content_ids): array {
    return array_map(
      fn (string $content_id): int => (int) $this->loadMappedNodeByContentId($content_id)->id(),
      $content_ids,
    );
  }

  /**
   * Asserts service body links stay language-prefixed and intentional.
   *
   * @param string $html
   *   HTML body to inspect.
   * @param string $langcode
   *   Expected URL language prefix.
   * @param list<string> $required_links
   *   Required localized paths.
   */
  private function assertLocalizedInternalLinks(string $html, string $langcode, array $required_links): void {
    foreach ($required_links as $required_link) {
      self::assertStringContainsString('href="' . $required_link . '"', $html);
    }

    self::assertStringNotContainsString('/fr/fr/', $html);
    self::assertStringNotContainsString('/en/en/', $html);

    preg_match_all('/href="([^"]+)"/', $html, $matches);
    foreach ($matches[1] as $href) {
      if (!str_starts_with($href, '/')) {
        continue;
      }

      self::assertStringStartsWith('/' . $langcode . '/', $href);
    }
  }

  /**
   * Returns the normalized services card values for a page translation.
   *
   * @return list<string>
   *   Services card item values.
   */
  private function serviceCardItems(NodeInterface $page, string $langcode): array {
    $page_translation = $page->hasTranslation($langcode)
      ? $page->getTranslation($langcode)
      : $page;

    foreach ($page_translation->get('field_home_components')->referencedEntities() as $component) {
      if ($component instanceof Paragraph && $component->bundle() === 'services') {
        $paragraph = $component->hasTranslation($langcode)
          ? $component->getTranslation($langcode)
          : $component;

        $items = [];
        foreach ($paragraph->get('field_items') as $item) {
          $item_value = $item->getValue();
          $items[] = (string) ($item_value['value'] ?? '');
        }

        return $items;
      }
    }

    throw new \RuntimeException(sprintf('No services paragraph found for "%s".', $langcode));
  }

  /**
   * Returns normalized text item values for a paragraph translation.
   *
   * @return list<string>
   *   Paragraph item values.
   */
  private function paragraphItems(Paragraph $paragraph, string $langcode): array {
    $paragraph_translation = $paragraph->hasTranslation($langcode)
      ? $paragraph->getTranslation($langcode)
      : $paragraph;

    $items = [];
    foreach ($paragraph_translation->get('field_items') as $item) {
      $item_value = $item->getValue();
      $items[] = (string) ($item_value['value'] ?? '');
    }

    return $items;
  }

  /**
   * Creates a pre-existing translated legal page with historical UUIDs.
   *
   * @param array<string, string> $expected
   *   Expected legal page values.
   */
  private function createLegacyLegalPage(array $expected): NodeInterface {
    $paragraph = Paragraph::create([
      'type' => 'text_block',
      'uuid' => $expected['paragraph_uuid'],
      'langcode' => 'fr',
      'status' => TRUE,
      'field_heading' => $expected['fr_title'],
      'field_text' => [
        'value' => '<p>' . $expected['fr_text'] . '</p>',
        'format' => 'basic_html',
      ],
    ]);
    $paragraph->addTranslation('en', [
      'field_heading' => $expected['en_title'],
      'field_text' => [
        'value' => '<p>' . $expected['en_text'] . '</p>',
        'format' => 'basic_html',
      ],
    ]);
    $paragraph->save();

    $node = Node::create([
      'type' => 'page',
      'uuid' => $expected['uuid'],
      'langcode' => 'fr',
      'title' => $expected['fr_title'],
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
      'field_home_components' => [
        [
          'target_id' => (int) $paragraph->id(),
          'target_revision_id' => (int) $paragraph->getRevisionId(),
        ],
      ],
      'path' => [
        'alias' => $expected['fr_alias'],
        'pathauto' => FALSE,
      ],
    ]);
    $node->save();

    $node->addTranslation('en', [
      'title' => $expected['en_title'],
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
      'field_home_components' => [
        [
          'target_id' => (int) $paragraph->id(),
          'target_revision_id' => (int) $paragraph->getRevisionId(),
        ],
      ],
      'path' => [
        'alias' => $expected['en_alias'],
        'pathauto' => FALSE,
      ],
    ]);
    $node->save();

    return $node;
  }

  /**
   * Creates a published service node for prune scope tests.
   */
  private function createPublishedServiceNode(string $title): NodeInterface {
    $node = Node::create([
      'type' => 'service',
      'title' => $title,
      'langcode' => 'fr',
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
    ]);
    $node->save();
    self::assertInstanceOf(NodeInterface::class, $node);

    return $node;
  }

  /**
   * Reloads a node after a Content Sync operation.
   */
  private function reloadNode(NodeInterface $node): NodeInterface {
    $reloaded = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->load((int) $node->id());
    self::assertInstanceOf(NodeInterface::class, $reloaded);

    return $reloaded;
  }

  /**
   * Restores an environment variable after a guarded test.
   */
  private function restoreEnvironmentVariable(string $name, string|false $value): void {
    if ($value === FALSE) {
      putenv($name);
      return;
    }

    putenv($name . '=' . $value);
  }

}
