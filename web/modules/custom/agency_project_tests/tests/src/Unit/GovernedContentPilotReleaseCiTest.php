<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the #440 pilot release contract inside the standard project CI suite.
 *
 * The module-local Governed Content tests remain the detailed owner tests. This
 * project-level gate exists because the standard CI suite executes
 * agency_project_tests/tests recursively.
 *
 * @group agency_project_tests
 * @group governed_content
 */
final class GovernedContentPilotReleaseCiTest extends TestCase {

  /**
   * The three pilot case studies are absent and the pending set is down to 30.
   */
  public function testPilotReleaseContractIsVisibleToStandardCi(): void {
    $project_root = dirname(DRUPAL_ROOT);
    $module_root = $project_root . '/web/modules/custom/emerging_digital_content';

    require_once $module_root . '/src/ContentSync/Policy/GovernedContentPolicy.php';

    $policy_class = 'Drupal\\emerging_digital_content\\ContentSync\\Policy\\GovernedContentPolicy';
    self::assertCount(3, $policy_class::GOVERNED_CONTENT_IDS);
    self::assertCount(30, $policy_class::LEGACY_RELEASE_PENDING_IDS);

    $catalog = Yaml::decode((string) file_get_contents($module_root . '/content_sync/catalog.yml'));
    self::assertIsArray($catalog);
    self::assertIsArray($catalog['contents'] ?? NULL);
    self::assertCount(33, $catalog['contents']);

    $catalog_ids = array_column($catalog['contents'], 'id');
    $released_case_studies = [
      'cas-client-refonte-drupal-institutionnelle',
      'cas-client-migration-drupal-11',
      'cas-client-integration-ia-editoriale',
    ];

    foreach ($released_case_studies as $content_id) {
      self::assertNotContains($content_id, $catalog_ids);
      self::assertNotContains($content_id, $policy_class::LEGACY_RELEASE_PENDING_IDS);
      self::assertFileDoesNotExist($module_root . '/content_sync/node/' . $content_id . '.yml');
    }

    foreach ($policy_class::GOVERNED_CONTENT_IDS as $content_id) {
      self::assertContains($content_id, $catalog_ids);
    }
  }

}
