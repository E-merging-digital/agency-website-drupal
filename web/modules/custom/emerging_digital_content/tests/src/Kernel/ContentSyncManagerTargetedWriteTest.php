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

    foreach (['hero', 'text_block', 'services', 'ai_features', 'trust_list', 'case_clients', 'cta'] as $paragraph_type) {
      ParagraphsType::create([
        'id' => $paragraph_type,
        'label' => $paragraph_type,
      ])->save();
    }

    $this->createTextLongField('field_short_description', TRUE);
    $this->createTextLongField('field_detailed_description', FALSE);
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

    $english = $node->getTranslation('en');
    self::assertSame('Drupal Agency Belgium', $english->label());
    self::assertStringContainsString(
      'Emerging Digital supports Belgian organisations',
      (string) $english->get('field_detailed_description')->value,
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
      'migration-drupal' => [
        'fr_title' => 'Migration Drupal',
        'en_title' => 'Drupal Migration',
        'fr_alias' => '/migration-drupal',
        'en_alias' => '/drupal-migration',
        'fr_text' => 'migration Drupal preparee',
        'en_text' => 'prepared and controlled Drupal migration',
      ],
      'refonte-site-drupal' => [
        'fr_title' => 'Refonte de site Drupal',
        'en_title' => 'Drupal Website Redesign',
        'fr_alias' => '/refonte-site-drupal',
        'en_alias' => '/drupal-website-redesign',
        'fr_text' => 'refonte Drupal qui protege',
        'en_text' => 'Drupal redesign that protects',
      ],
      'audit-drupal' => [
        'fr_title' => 'Audit Drupal',
        'en_title' => 'Drupal Audit',
        'fr_alias' => '/audit-drupal',
        'en_alias' => '/drupal-audit',
        'fr_text' => 'audit Drupal actionnable',
        'en_text' => 'actionable Drupal audit',
      ],
      'accessibilite-seo-optimisation' => [
        'fr_title' => 'Accessibilité, SEO et optimisation',
        'en_title' => 'Accessibility, SEO and Optimization',
        'fr_alias' => '/accessibilite-seo-optimisation',
        'en_alias' => '/ai-accessibility-seo-optimization',
        'fr_text' => 'optimisation Drupal lisible et mesurable',
        'en_text' => 'Clear and measurable Drupal optimization',
      ],
      'ia-integree' => [
        'fr_title' => 'IA intégrée',
        'en_title' => 'Integrated AI',
        'fr_alias' => '/ia-integree',
        'en_alias' => '/integrated-ai',
        'fr_text' => 'IA Drupal utile et gouvernable',
        'en_text' => 'Useful and governed Drupal AI',
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

      $english = $node->getTranslation('en');
      self::assertSame($expected['en_title'], $english->label());
      self::assertStringContainsString(
        $expected['en_text'],
        (string) $english->get('field_detailed_description')->value,
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
    self::assertCount(16, $dry_run['content_reports']);
    self::assertSame('agence-drupal-belgique', $dry_run['content_reports'][0]['id']);
    self::assertSame('would create managed entity', $dry_run['content_reports'][0]['planned_operation']);
    self::assertSame('unmapped', $dry_run['content_reports'][0]['mapping_status']);
    self::assertFalse($mapping_repository->exists('agence-drupal-belgique'));
    self::assertSame(0, $this->countServiceNodes());
    self::assertSame(0, $this->countPageNodes());

    $first_apply = $manager->sync('', FALSE, TRUE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(8, $this->countServiceNodes());
    self::assertSame(8, $this->countPageNodes());
    self::assertArrayHasKey('content_reports', $first_apply);
    self::assertCount(16, $first_apply['content_reports']);
    self::assertSame('agence-drupal-belgique', $first_apply['content_reports'][0]['id']);
    self::assertSame('creation-site-drupal', $first_apply['content_reports'][1]['id']);
    self::assertSame('maintenance-drupal', $first_apply['content_reports'][2]['id']);
    self::assertSame('migration-drupal', $first_apply['content_reports'][3]['id']);
    self::assertSame('refonte-site-drupal', $first_apply['content_reports'][4]['id']);
    self::assertSame('audit-drupal', $first_apply['content_reports'][5]['id']);
    self::assertSame('accessibilite-seo-optimisation', $first_apply['content_reports'][6]['id']);
    self::assertSame('ia-integree', $first_apply['content_reports'][7]['id']);
    self::assertSame('services', $first_apply['content_reports'][8]['id']);
    self::assertSame('ia-drupal', $first_apply['content_reports'][9]['id']);
    self::assertSame('cas-clients', $first_apply['content_reports'][10]['id']);
    self::assertSame('contact', $first_apply['content_reports'][11]['id']);
    self::assertSame('mentions-legales', $first_apply['content_reports'][12]['id']);
    self::assertSame('politique-confidentialite', $first_apply['content_reports'][13]['id']);
    self::assertSame('politique-cookies', $first_apply['content_reports'][14]['id']);
    self::assertSame('homepage', $first_apply['content_reports'][15]['id']);

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

    $services_items = $this->serviceCardItems($this->loadMappedNodeByContentId('services'), 'fr');
    self::assertCount(8, $services_items);
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
      . 'de contenu, tests et securisation du socle technique.|/fr/migration-drupal',
      $services_items,
    );
    self::assertContains(
      "Refonte de site Drupal|Refonte Drupal pour clarifier les contenus, "
      . "moderniser l'experience et proteger le SEO existant.|/fr/refonte-site-drupal",
      $services_items,
    );
    self::assertContains(
      'Audit Drupal|Audit technique, SEO, performance, accessibilite et '
      . 'editorial pour prioriser les bonnes corrections Drupal.|/fr/audit-drupal',
      $services_items,
    );
    self::assertContains(
      'Accessibilité, SEO et optimisation|Lisibilite, referencement naturel, '
      . 'accessibilite et performance pour des pages Drupal plus utiles.|/fr/accessibilite-seo-optimisation',
      $services_items,
    );
    self::assertContains(
      'IA intégrée|Automatisation editoriale utile, qualite des contenus, '
      . 'traduction et gouvernance des usages IA dans Drupal.|/fr/ia-integree',
      $services_items,
    );

    $services_en_items = $this->serviceCardItems($this->loadMappedNodeByContentId('services'), 'en');
    self::assertCount(8, $services_en_items);
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

    $homepage_items = $this->serviceCardItems($this->loadMappedNodeByContentId('homepage'), 'fr');
    self::assertCount(8, $homepage_items);
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
      'Migration et modernisation|Reprise de sites existants, montee de '
      . 'version Drupal, amelioration de la structure et des performances.|/fr/migration-drupal',
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
      . 'socle technique optimise pour vos publics et les moteurs de recherche.|/fr/accessibilite-seo-optimisation',
      $homepage_items,
    );
    self::assertContains(
      'IA intégrée dans le CMS|Aide a la redaction, amelioration de la qualite '
      . 'editoriale, enrichissement et preparation a la traduction automatique des contenus.|/fr/ia-integree',
      $homepage_items,
    );

    $homepage_en_items = $this->serviceCardItems($this->loadMappedNodeByContentId('homepage'), 'en');
    self::assertCount(8, $homepage_en_items);
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
    self::assertSame(8, $this->countServiceNodes());
    self::assertSame(8, $this->countPageNodes());

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
      'Services Drupal pour projets structurés et institutionnels',
      $paragraphs[0]->get('field_heading')->value,
    );
    self::assertSame('Nos services', $paragraphs[2]->get('field_heading')->value);
    self::assertCount(6, $paragraphs[2]->get('field_items'));
    self::assertSame(
      'Pourquoi Drupal pour des projets exigeants',
      $paragraphs[3]->get('field_heading')->value,
    );
    self::assertSame('Prendre contact', $paragraphs[4]->get('field_link')->title);

    $english_paragraphs = $page->getTranslation('en')->get('field_home_components')->referencedEntities();
    self::assertCount(5, $english_paragraphs);
    self::assertSame(
      'Drupal services for structured and institutional projects',
      $english_paragraphs[0]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Our services',
      $english_paragraphs[2]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Get in touch',
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
    self::assertCount(5, $paragraphs[2]->get('field_items'));
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
    self::assertSame('Cas clients Drupal sur des contextes structurés', $paragraphs[0]->get('field_heading')->value);
    self::assertStringContainsString('Chaque projet répond à des besoins réels', (string) $paragraphs[1]->get('field_text')->value);
    self::assertCount(3, $paragraphs[2]->get('field_items'));
    self::assertSame('Refonte d’un site institutionnel', $paragraphs[2]->get('field_items')->first()->value);
    self::assertSame('Site difficile à maintenir et à faire évoluer', $paragraphs[2]->get('field_case_problem')->first()->value);
    self::assertSame('refonte Drupal', $paragraphs[2]->get('field_case_solution')->first()->value);
    self::assertSame('meilleure structure, plus simple à éditer', $paragraphs[2]->get('field_case_result')->first()->value);
    self::assertSame('Prendre contact', $paragraphs[3]->get('field_link')->title);

    $english_paragraphs = $page->getTranslation('en')->get('field_home_components')->referencedEntities();
    self::assertCount(4, $english_paragraphs);
    self::assertSame(
      'Drupal case studies for structured contexts',
      $english_paragraphs[0]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertStringContainsString(
      'Each project responds to real needs',
      (string) $english_paragraphs[1]->getTranslation('en')->get('field_text')->value,
    );
    self::assertSame(
      'Institutional website redesign',
      $english_paragraphs[2]->getTranslation('en')->get('field_items')->first()->value,
    );
    self::assertSame(
      'Site difficult to maintain and evolve',
      $english_paragraphs[2]->getTranslation('en')->get('field_case_problem')->first()->value,
    );
    self::assertSame(
      'Drupal redesign',
      $english_paragraphs[2]->getTranslation('en')->get('field_case_solution')->first()->value,
    );
    self::assertSame(
      'better structure, easier to edit',
      $english_paragraphs[2]->getTranslation('en')->get('field_case_result')->first()->value,
    );
    self::assertSame(
      'Get in touch',
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
    self::assertSame(6, $this->countParagraphs());

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
    self::assertCount(6, $paragraphs);
    self::assertSame(
      ['hero', 'text_block', 'text_block', 'text_block', 'text_block', 'text_block'],
      array_map(static fn ($paragraph): string => $paragraph->bundle(), $paragraphs),
    );
    self::assertSame('Parlons de votre projet', $paragraphs[0]->get('field_heading')->value);
    self::assertSame('Intro', $paragraphs[1]->get('field_heading')->value);
    self::assertStringContainsString('réponse claire', (string) $paragraphs[1]->get('field_text')->value);
    self::assertSame('Coordonnées', $paragraphs[2]->get('field_heading')->value);
    self::assertStringContainsString('jonathan@emergingdigital.be', (string) $paragraphs[2]->get('field_text')->value);
    self::assertSame('Informations', $paragraphs[3]->get('field_heading')->value);
    self::assertStringContainsString('Premier échange sans engagement', (string) $paragraphs[3]->get('field_text')->value);
    self::assertSame('Formulaire', $paragraphs[4]->get('field_heading')->value);
    self::assertSame('Nom / Email / Organisation / Message', $paragraphs[4]->get('field_text')->value);
    self::assertSame('Carte', $paragraphs[5]->get('field_heading')->value);
    self::assertStringContainsString('Localisation Emerging Digital', (string) $paragraphs[5]->get('field_text')->value);

    $english_paragraphs = $page->getTranslation('en')->get('field_home_components')->referencedEntities();
    self::assertCount(6, $english_paragraphs);
    self::assertSame(
      'Let’s talk about your project',
      $english_paragraphs[0]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Contact details',
      $english_paragraphs[2]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertStringContainsString(
      'Available for projects in Wallonia and Brussels',
      (string) $english_paragraphs[3]->getTranslation('en')->get('field_text')->value,
    );
    self::assertSame(
      'Form',
      $english_paragraphs[4]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Map',
      $english_paragraphs[5]->getTranslation('en')->get('field_heading')->value,
    );

    $mapping = $mapping_repository->findByContentId('contact');
    self::assertNotNull($mapping);
    self::assertSame((int) $page->id(), $mapping->entityId());
    self::assertSame('created', $mapping->lastAction());
    self::assertNotNull($mapping_repository->findByContentId('contact.coordinates'));
    self::assertNotNull($mapping_repository->findByContentId('contact.form'));
    self::assertNotNull($mapping_repository->findByContentId('contact.map'));

    $component_ids = array_map(static fn ($paragraph): int => (int) $paragraph->id(), $paragraphs);
    $second_apply = $manager->sync('contact', FALSE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(6, $this->countParagraphs());
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
   * Creates one translatable text_long field on service nodes.
   */
  private function createTextLongField(string $field_name, bool $required): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'text_long',
      'translatable' => TRUE,
    ])->save();

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'bundle' => 'service',
      'label' => $field_name,
      'required' => $required,
      'translatable' => TRUE,
    ])->save();
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
