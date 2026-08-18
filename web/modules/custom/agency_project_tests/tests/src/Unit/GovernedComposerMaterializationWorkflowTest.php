<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the trusted Composer materialization execution contract.
 *
 * @group agency_project_tests
 * @group governed_composer
 */
final class GovernedComposerMaterializationWorkflowTest extends TestCase {

  /**
   * The dispatch gateway must expose only the bounded control surface.
   */
  public function testDispatchGatewayIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/agent-composer-materialization-dispatch.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString(
      'agency/composer-materialization-dispatch-control',
      $workflow,
    );
    self::assertStringContainsString(
      '.agency/composer-materialization-request.json',
      $workflow,
    );
    self::assertStringContainsString(
      'Unsupported Composer materialization profile',
      $workflow,
    );
    self::assertStringContainsString(
      "changed\" != 'composer.json'",
      $workflow,
    );
    self::assertStringContainsString(
      "['drupal/ai_agents'] = '^1.3'",
      $workflow,
    );
    self::assertStringNotContainsString(
      'composer require $',
      $workflow,
    );
  }

  /**
   * The self-hosted job must never receive repository write authority.
   */
  public function testSelfHostedResolverIsReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-composer-materialization.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringNotContainsString('[[ "${{ inputs.', $workflow);

    $generateStart = strpos($workflow, "  generate-lock:\n");
    $publishStart = strpos($workflow, "  publish-lock:\n");
    self::assertIsInt($generateStart);
    self::assertIsInt($publishStart);
    self::assertGreaterThan($generateStart, $publishStart);

    $generate = substr(
      $workflow,
      $generateStart,
      $publishStart - $generateStart,
    );
    $publish = substr($workflow, $publishStart);

    self::assertStringContainsString('contents: read', $generate);
    self::assertStringNotContainsString('contents: write', $generate);
    self::assertStringContainsString('persist-credentials: false', $generate);
    self::assertStringContainsString('--no-scripts', $generate);
    self::assertStringContainsString(
      "changed\" != 'composer.lock'",
      $generate,
    );

    self::assertStringContainsString('contents: write', $publish);
    self::assertStringContainsString(
      'Target PR advanced before Composer lock publication.',
      $publish,
    );
    self::assertStringContainsString(
      'git push origin "HEAD:$EXPECTED_HEAD_REF"',
      $publish,
    );
  }

  /**
   * Profile data must remain repository-owned and fail closed.
   */
  public function testInitialProfileIsFixedAndFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/runner/composer-materialization-profiles.sh';
    self::assertFileExists($path);

    $profiles = (string) file_get_contents($path);
    self::assertStringContainsString('canvas-ai-agents-530)', $profiles);
    self::assertStringContainsString(
      "COMPOSER_PACKAGE='drupal/ai_agents'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_CONSTRAINT='^1.3'",
      $profiles,
    );
    self::assertStringContainsString(
      'Unsupported Composer materialization profile',
      $profiles,
    );

    $output = [];
    $exitCode = 0;
    exec(
      'bash -n ' . escapeshellarg($path) . ' 2>&1',
      $output,
      $exitCode,
    );
    self::assertSame(0, $exitCode, implode("\n", $output));

    $unsupported = [];
    $unsupportedExitCode = 0;
    exec(
      'COMPOSER_PROFILE=unsupported bash -c '
      . escapeshellarg('source ' . escapeshellarg($path))
      . ' 2>&1',
      $unsupported,
      $unsupportedExitCode,
    );
    self::assertNotSame(0, $unsupportedExitCode);
  }

}
