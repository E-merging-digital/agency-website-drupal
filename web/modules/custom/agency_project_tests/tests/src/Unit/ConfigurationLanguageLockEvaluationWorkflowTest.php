<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded Configuration Language Lock evaluation route.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageLockEvaluationWorkflowTest extends TestCase {

  /**
   * The workflow stays tied to the reviewed #628 candidate.
   */
  public function testTargetAndTriggerAreFixed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-config-language-lock-evaluation.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString('github.event.issue.number == 629', $workflow);
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-lock evaluate'",
      $workflow,
    );
    self::assertStringContainsString("test \"\$PR_NUMBER\" = '629'", $workflow);
    self::assertStringContainsString(
      "test \"\$(jq -r '.draft' <<<\"\$pr_json\")\" = 'true'",
      $workflow,
    );
    self::assertStringContainsString(
      "test \"\$(jq -r '.head.ref' <<<\"\$pr_json\")\" = 'feature/628-config-language-lock-candidate'",
      $workflow,
    );
    self::assertStringContainsString(
      'Expected drupal/config_language_lock:^1.0',
      $workflow,
    );
    self::assertStringContainsString('Expected stable 1.0.x', $workflow);
    self::assertStringContainsString(
      'composer.json and composer.lock',
      $workflow,
    );
  }

  /**
   * The self-hosted proof is read-only, reversible and non-enforcing.
   */
  public function testEvaluationIsReadOnlyAndReversible(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-config-language-lock-evaluation.yml';
    $workflow = (string) file_get_contents($path);

    $evaluateStart = strpos($workflow, "  evaluate:\n");
    $reportStart = strpos($workflow, "  report-result:\n");
    self::assertIsInt($evaluateStart);
    self::assertIsInt($reportStart);
    $evaluate = substr($workflow, $evaluateStart, $reportStart - $evaluateStart);

    self::assertStringContainsString('contents: read', $evaluate);
    self::assertStringNotContainsString('contents: write', $evaluate);
    self::assertStringContainsString('persist-credentials: false', $evaluate);
    self::assertStringContainsString('ddev composer audit --locked', $evaluate);
    self::assertStringContainsString('site:install --existing-config', $evaluate);
    self::assertStringContainsString('ddev drush cim -y', $evaluate);
    self::assertStringContainsString(
      'ddev drush pm:enable config_language_lock -y',
      $evaluate,
    );
    self::assertStringContainsString(
      'ddev drush pm:uninstall config_language_lock -y',
      $evaluate,
    );
    self::assertStringContainsString(
      "changed != ['core.extension']",
      $evaluate,
    );
    self::assertStringContainsString(
      "name.startswith('config_language_lock.')",
      $evaluate,
    );
    self::assertStringContainsString(
      "if key == 'locked_langcode'",
      $evaluate,
    );
    self::assertStringContainsString(
      'Active configuration did not return exactly to baseline',
      $evaluate,
    );
    self::assertStringContainsString(
      '.special.system_menu_footer_langcode == "und"',
      $evaluate,
    );
    self::assertStringContainsString(
      'test -z "$(git status --short config/sync)"',
      $evaluate,
    );
    self::assertStringNotContainsString('drush cex', $evaluate);
    self::assertStringNotContainsString('OPENAI_API_KEY', $workflow);
    self::assertStringNotContainsString('deploy-production', $workflow);
    self::assertStringNotContainsString('ssh ', $workflow);
  }

  /**
   * The active-configuration fingerprint helper performs reads only.
   */
  public function testFingerprintHelperIsDeterministicAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/runner/config-language-lock-state.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString('$storage->listAll()', $script);
    self::assertStringContainsString('$storage->read($name)', $script);
    self::assertStringContainsString('ksort($value, SORT_STRING)', $script);
    self::assertStringContainsString("hash('sha256'", $script);
    self::assertStringContainsString("'module_owned'", $script);
    self::assertStringContainsString("'config_language_lock_enabled'", $script);
    self::assertStringNotContainsString('->write(', $script);
    self::assertStringNotContainsString('->save(', $script);
    self::assertStringNotContainsString('->delete(', $script);
  }

}
