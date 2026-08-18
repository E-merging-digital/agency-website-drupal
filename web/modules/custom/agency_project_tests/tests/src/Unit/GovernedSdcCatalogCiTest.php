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
   * Candidate/approved entries must resolve to typed SDC contracts and adapters.
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

}
