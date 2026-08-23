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
    self::assertStringContainsString('canvas-ai-agents-530', $workflow);
    self::assertStringContainsString('config-language-lock-628', $workflow);
    self::assertStringContainsString(
      "expected_package='drupal/ai_agents'",
      $workflow,
    );
    self::assertStringContainsString(
      "expected_constraint='^1.3'",
      $workflow,
    );
    self::assertStringContainsString(
      "expected_package='drupal/config_language_lock'",
      $workflow,
    );
    self::assertStringContainsString(
      "expected_constraint='^1.0'",
      $workflow,
    );
    self::assertStringContainsString(
      "expected.setdefault('require', {})[os.environ['EXPECTED_PACKAGE']]",
      $workflow,
    );
    self::assertStringNotContainsString('composer require $', $workflow);
    self::assertStringNotContainsString('package_name', $workflow);
    self::assertStringNotContainsString('composer_args', $workflow);
  }

  /**
   * Every valid dispatch must expose its exact trusted run and conclusion.
   */
  public function testDispatchGatewayPublishesObservableRunStatus(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/agent-composer-materialization-dispatch.yml';
    $workflow = (string) file_get_contents($path);

    self::assertStringContainsString('statuses: write', $workflow);
    self::assertStringContainsString(
      "context='agency/composer-materialization'",
      $workflow,
    );
    self::assertStringContainsString("state='pending'", $workflow);
    self::assertStringContainsString(
      '--workflow trusted-composer-materialization.yml',
      $workflow,
    );
    self::assertStringContainsString(
      'Trusted Composer workflow run was not discoverable.',
      $workflow,
    );
    self::assertStringContainsString(
      'gh run view "$TRUSTED_RUN_ID"',
      $workflow,
    );
    self::assertStringContainsString(
      'if [[ "$CONCLUSION" == \'success\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      'Inspect the linked status run for the failing job',
      $workflow,
    );
    self::assertStringContainsString(
      'test "$state" = \'success\'',
      $workflow,
    );
  }

  /**
   * The self-hosted job must remain read-only and lockfile-only.
   */
  public function testSelfHostedResolverIsReadOnlyAndLockOnly(): void {
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
    self::assertStringContainsString('--no-install', $generate);
    self::assertStringContainsString('--no-scripts', $generate);
    self::assertStringContainsString(
      "changed\" != 'composer.lock'",
      $generate,
    );

    self::assertStringContainsString(
      'ddev list --json-output 2>&1',
      $generate,
    );
    self::assertStringContainsString(
      'python3 trusted/scripts/runner/'
      . 'extract-stale-composer-ddev-projects.py',
      $generate,
    );
    self::assertStringContainsString(
      'ddev stop --unlist --omit-snapshot "$stale_project"',
      $generate,
    );
    self::assertStringContainsString(
      'ddev delete --omit-snapshot --yes "$isolated_name"',
      $generate,
    );

    $deletePosition = strpos(
      $generate,
      'ddev delete --omit-snapshot --yes "$isolated_name"',
    );
    $removeOverridePosition = strrpos(
      $generate,
      'rm -f .ddev/config.gate-composer-ci.yaml',
    );
    self::assertIsInt($deletePosition);
    self::assertIsInt($removeOverridePosition);
    self::assertLessThan($removeOverridePosition, $deletePosition);

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
   * DDEV warnings must be parsed without widening the cleanup namespace.
   */
  public function testStaleDdevProjectExtractorIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/extract-stale-composer-ddev-projects.py';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString(
      'agency-composer-[0-9]+-[0-9]+',
      $script,
    );
    self::assertStringContainsString('_PATTERN.findall(item)', $script);
    self::assertStringContainsString('decoder.raw_decode(raw, offset)', $script);
    self::assertStringContainsString(
      'Non-JSON data in DDEV output',
      $script,
    );

    $output = [];
    $exitCode = 0;
    exec(
      'python3 ' . escapeshellarg($path) . ' --self-test 2>&1',
      $output,
      $exitCode,
    );
    self::assertSame(0, $exitCode, implode("\n", $output));
    self::assertContains('PASS', $output);
  }

  /**
   * Profile data must remain repository-owned and fail closed.
   */
  public function testProfilesAreFixedAndFailClosed(): void {
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
    self::assertStringContainsString('config-language-lock-628)', $profiles);
    self::assertStringContainsString(
      "COMPOSER_PACKAGE='drupal/config_language_lock'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_CONSTRAINT='^1.0'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_VERSION_REGEX='^1\\.0\\.[0-9]+$'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_OWNER_ISSUE='628'",
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
