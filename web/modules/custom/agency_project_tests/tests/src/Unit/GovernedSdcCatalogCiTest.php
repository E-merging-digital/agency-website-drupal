<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the governed SDC admission catalogue in standard CI.
 *
 * @group agency_project_tests
 * @group governed_sdc
 */
final class GovernedSdcCatalogCiTest extends TestCase {

  /**
   * Candidate/approved entries must resolve to typed SDC contracts.
   */
  public function testGovernedCatalogResolvesToSdcContracts(): void {
    $projectRoot = dirname(DRUPAL_ROOT);
    $catalogPath = $projectRoot . '/docs/design-system/component-catalog.yml';
    self::assertFileExists($catalogPath);

    $catalog = Yaml::parseFile($catalogPath);
    self::assertSame(1, $catalog['version'] ?? NULL);
    self::assertSame('approved', $catalog['policy']['ai_composable_status'] ?? NULL);

    $allowedStatuses = $catalog['policy']['allowed_statuses'] ?? [];
    self::assertSame(
      ['candidate', 'approved', 'deprecated', 'retired'],
      $allowedStatuses,
    );

    $components = $catalog['components'] ?? [];
    self::assertSame(
      [
        'emerging_digital:cta',
        'emerging_digital:hero',
        'emerging_digital:trust-list',
      ],
      array_keys($components),
    );

    foreach ($components as $componentId => $entry) {
      $status = $entry['status'] ?? NULL;
      self::assertContains($status, $allowedStatuses, $componentId);

      $metadataPath = $projectRoot . '/' . ($entry['source'] ?? '');
      $templatePath = $projectRoot . '/' . ($entry['template'] ?? '');
      $adapterPath = $projectRoot . '/' . ($entry['adapter'] ?? '');
      self::assertFileExists($metadataPath, $componentId);
      self::assertFileExists($templatePath, $componentId);
      self::assertFileExists($adapterPath, $componentId);

      $metadata = Yaml::parseFile($metadataPath);
      self::assertSame('stable', $metadata['status'] ?? NULL, $componentId);
      self::assertNotEmpty($metadata['name'] ?? NULL, $componentId);
      self::assertSame('object', $metadata['props']['type'] ?? NULL, $componentId);

      $properties = $metadata['props']['properties'] ?? [];
      foreach ($properties as $propName => $schema) {
        self::assertNotEmpty($schema['title'] ?? NULL, "$componentId:$propName");
        self::assertTrue(
          array_key_exists('type', $schema) || array_key_exists('$ref', $schema),
          "$componentId:$propName must declare a JSON schema type or ref.",
        );
      }

      foreach ($metadata['props']['required'] ?? [] as $requiredProp) {
        self::assertArrayHasKey($requiredProp, $properties, $componentId);
        self::assertNotEmpty(
          $properties[$requiredProp]['examples'] ?? [],
          "$componentId:$requiredProp requires an example for Canvas defaults.",
        );
        if (($properties[$requiredProp]['type'] ?? NULL) === 'array') {
          self::assertGreaterThanOrEqual(
            1,
            $properties[$requiredProp]['minItems'] ?? 0,
            "$componentId:$requiredProp requires minItems >= 1.",
          );
        }
      }

      $adapter = (string) file_get_contents($adapterPath);
      self::assertStringContainsString($componentId, $adapter, $componentId);

      $template = (string) file_get_contents($templatePath);
      self::assertStringContainsString(
        "data-ed-component', '$componentId'",
        $template,
        $componentId,
      );

      $approvedForAi = (bool) ($entry['approved_for_ai_composition'] ?? FALSE);
      if ($status === 'approved') {
        self::assertTrue($approvedForAi, $componentId);
        self::assertMatchesRegularExpression(
          '/^run:[0-9]+$/',
          (string) ($entry['evidence']['browser_run'] ?? ''),
          "$componentId requires an exact browser run before approval.",
        );
      }
      else {
        self::assertFalse($approvedForAi, $componentId);
      }
    }
  }

  /**
   * Contact Hero must be admitted by the SDC contract and theme mapping.
   */
  public function testContactHeroSdcVariantContract(): void {
    $projectRoot = dirname(DRUPAL_ROOT);
    $themeRoot = $projectRoot . '/web/themes/custom/emerging_digital';
    $componentRoot = $themeRoot . '/components/hero';
    $metadata = Yaml::parseFile($componentRoot . '/hero.component.yml');
    $variant = $metadata['props']['properties']['variant'] ?? [];

    $expected = [
      'home' => 'Home',
      'ia-drupal' => 'AI & Drupal',
      'services' => 'Services',
      'case-clients' => 'Case studies',
      'team' => 'Team',
      'contact' => 'Contact',
    ];
    self::assertSame(array_keys($expected), $variant['enum'] ?? NULL);
    self::assertSame($expected, $variant['meta:enum'] ?? NULL);

    $template = (string) file_get_contents($componentRoot . '/hero.twig');
    $matches = [];
    self::assertSame(1, preg_match(
      '/^\{% set allowed_variants = \[([^\r\n]*)\] %\}$/m',
      $template,
      $matches,
    ));
    $values = [];
    preg_match_all("/'([^']+)'/", $matches[1], $values);
    self::assertSame(array_keys($expected), $values[1]);
    self::assertStringContainsString(
      "variant in allowed_variants ? variant : 'home'",
      $template,
    );
    self::assertStringContainsString(
      "'ed-section--hero-' ~ safe_variant",
      $template,
    );

    $theme = (string) file_get_contents($themeRoot . '/emerging_digital.theme');
    self::assertStringContainsString("'/contact' => 'contact',", $theme);
    $illustrationPattern = <<<'REGEX'
~'contact'\s*=>\s*\[
  \s*'path'\s*=>\s*'images/contact/contact-page-hero\.svg',
  \s*'alt'\s*=>\s*\[
  \s*'fr'\s*=>\s*'[^']+',
  \s*'en'\s*=>\s*'[^']+',
~x
REGEX;
    self::assertMatchesRegularExpression($illustrationPattern, $theme);
    self::assertFileExists(
      $themeRoot . '/images/contact/contact-page-hero.svg',
    );

    $adapter = (string) file_get_contents(
      $themeRoot . '/templates/paragraphs/paragraph--hero.html.twig',
    );
    self::assertStringContainsString(
      "include('emerging_digital:hero'",
      $adapter,
    );
    self::assertStringContainsString(
      "variant: hero_illustration_class",
      $adapter,
    );
    self::assertStringContainsString("'ed-section--hero-': ''", $adapter);
  }

  /**
   * Protects active Hero resolution and the previous archived version.
   */
  public function testCanonicalHeroHistoricalVersionCompatibility(): void {
    $projectRoot = dirname(DRUPAL_ROOT);
    $configPath = $projectRoot
      . '/config/sync/canvas.component.sdc.emerging_digital.hero.yml';
    $config = Yaml::parseFile($configPath);
    $oldVersion = 'f6ebbdd7632bea80';
    $newVersion = '8172164706c8dc5f';

    self::assertSame($newVersion, $config['active_version'] ?? NULL);
    self::assertSame(
      '6480184b-d40e-409e-8383-94e695caae9d',
      $config['uuid'] ?? NULL,
    );
    self::assertSame('sdc.emerging_digital.hero', $config['id'] ?? NULL);
    self::assertSame('emerging_digital', $config['provider'] ?? NULL);
    self::assertSame('sdc', $config['source'] ?? NULL);
    self::assertSame(
      'emerging_digital:hero',
      $config['source_local_id'] ?? NULL,
    );
    self::assertSame(
      [
        'module' => ['link', 'options'],
        'theme' => ['emerging_digital'],
      ],
      $config['dependencies'] ?? NULL,
    );

    $versions = $config['versioned_properties'] ?? [];
    self::assertSame(['active', $oldVersion], array_keys($versions));
    self::assertIsArray($versions['active']);
    self::assertSame($versions['active'], $versions[$oldVersion]);
    self::assertSame(
      [
        'heading',
        'heading_level',
        'variant',
        'image_src',
        'image_alt',
        'image_loading',
      ],
      array_keys($versions['active']['settings']['prop_field_definitions']),
    );
    self::assertSame(
      ['intro', 'primary_action', 'secondary_action', 'media'],
      array_keys($versions['active']['fallback_metadata']['slot_definitions']),
    );

    $importer = (string) file_get_contents(
      $projectRoot
      . '/web/modules/custom/emerging_digital_content/src/ContentSync/'
      . 'GovernedCoreDefaultContentImporter.php',
    );
    self::assertStringContainsString(
      "'sdc.emerging_digital.hero' => '$newVersion'",
      $importer,
    );

    $isCompatible = static function (array $candidate) use (
      $config,
      $versions,
      $oldVersion,
      $newVersion,
    ): bool {
      return ($candidate['active_version'] ?? NULL) === $newVersion
        && ($candidate['id'] ?? NULL) === $config['id']
        && ($candidate['uuid'] ?? NULL) === $config['uuid']
        && ($candidate['dependencies'] ?? NULL) === $config['dependencies']
        && array_keys($candidate['versioned_properties'] ?? [])
          === ['active', $oldVersion]
        && $candidate['versioned_properties']['active']
          === $versions['active']
        && $candidate['versioned_properties'][$oldVersion]
          === $versions['active'];
    };
    self::assertTrue($isCompatible($config));

    $missingArchive = $config;
    unset($missingArchive['versioned_properties'][$oldVersion]);
    self::assertFalse($isCompatible($missingArchive));

    $wrongArchive = $config;
    $fields = &$wrongArchive['versioned_properties'][$oldVersion]['settings'];
    $fields['prop_field_definitions']['variant']['required'] = FALSE;
    unset($fields);
    self::assertFalse($isCompatible($wrongArchive));

    $wrongSlot = $config;
    $slotArchive = &$wrongSlot['versioned_properties'][$oldVersion];
    $slotDefinitions = &$slotArchive['fallback_metadata']['slot_definitions'];
    $slotDefinitions['media']['title'] = 'Incorrect';
    unset($slotDefinitions, $slotArchive);
    self::assertFalse($isCompatible($wrongSlot));

    $wrongDependency = $config;
    $wrongDependency['dependencies']['module'][] = 'unexpected';
    self::assertFalse($isCompatible($wrongDependency));

    $extraVersion = $config;
    $extraVersion['versioned_properties']['unapproved'] = [];
    self::assertFalse($isCompatible($extraVersion));
  }

}
