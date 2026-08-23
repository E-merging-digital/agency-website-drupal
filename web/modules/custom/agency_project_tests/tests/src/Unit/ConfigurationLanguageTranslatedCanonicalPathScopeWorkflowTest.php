<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects #726 migration-path scoping in the governed #720 writer route.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageTranslatedCanonicalPathScopeWorkflowTest extends TestCase {

  /**
   * Runtime DDEV files must not participate in canonical migration path gates.
   */
  public function testAllPathAndCommitGatesAreBoundedToMigrationSurfaces(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/governed-configuration-language-translated-canonical-migration.yml',
    );

    self::assertIsArray(DrupalYaml::decode($workflow));

    self::assertStringContainsString(
      'git status --porcelain --untracked-files=all -- config/sync',
      $workflow,
    );
    self::assertStringContainsString(
      'docs/evidence/configuration-language-translated-canonical-cohort-720.yml',
      $workflow,
    );
    self::assertStringContainsString(
      'mapfile -t staged_paths < <(git diff --cached --name-only | sort)',
      $workflow,
    );
    self::assertStringContainsString(
      'diff -u <(printf \'%s\\n\' "${paths[@]}") <(printf \'%s\\n\' "${staged_paths[@]}")',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ -z "$(git diff --name-only)" ]]',
      $workflow,
    );
    self::assertStringContainsString(
      'git ls-files --others --exclude-standard -- config/sync docs/evidence/configuration-language-translated-canonical-cohort-720.yml',
      $workflow,
    );

    self::assertStringNotContainsString(
      "mapfile -t changed_all_paths < <(git status --porcelain | awk '{print \$2}'",
      $workflow,
    );
    self::assertStringNotContainsString(
      '[[ -z "$(git status --porcelain | grep -v',
      $workflow,
    );
  }

}
