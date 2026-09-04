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
      "expected = {'request_id', 'pr_number', 'head_sha', 'profile'}",
      $workflow,
    );
    self::assertStringContainsString(
      'Unsupported Composer materialization profile',
      $workflow,
    );
    self::assertStringContainsString('canvas-ai-agents-530', $workflow);
    self::assertStringContainsString('config-language-lock-628', $workflow);
    self::assertStringContainsString('dependency-maintenance-962', $workflow);
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
    self::assertStringContainsString("mode='lock-refresh'", $workflow);
    self::assertStringContainsString(
      'Lock-refresh target PR must have no file delta before resolution.',
      $workflow,
    );
    self::assertStringContainsString(
      "expected.setdefault('require', {})[os.environ['EXPECTED_PACKAGE']]",
      $workflow,
    );
    self::assertStringNotContainsString('package_name', $workflow);
    self::assertStringNotContainsString('composer_args', $workflow);
    self::assertStringNotContainsString('selectors_input', $workflow);
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
  public function testSelfHostedResolverIsReadOnlyTargetedAndLockOnly(): void {
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
      'ddev composer update "$COMPOSER_PACKAGE"',
      $generate,
    );
    self::assertStringContainsString(
      'ddev composer update "${selectors[@]}"',
      $generate,
    );
    self::assertStringContainsString(
      'read -r -a selectors <<< "$COMPOSER_LOCK_REFRESH_SELECTORS"',
      $generate,
    );
    self::assertStringContainsString(
      'test "${#selectors[@]}" -gt 0',
      $generate,
    );
    self::assertStringNotContainsString(
      "ddev composer update \\\n              --with-all-dependencies",
      $generate,
    );
    self::assertStringContainsString(
      "changed\" != 'composer.lock'",
      $generate,
    );
    self::assertStringContainsString(
      "'reviewed_selectors': reviewed_selectors",
      $generate,
    );
    self::assertStringContainsString(
      "'resolved_versions': resolved_versions",
      $generate,
    );
    self::assertStringContainsString(
      "'owner_issue': int(os.environ['EXPECTED_OWNER_ISSUE'])",
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

    self::assertStringContainsString('contents: write', $publish);
    self::assertStringContainsString(
      'Hosted publisher may change exactly composer.lock.',
      $publish,
    );
    self::assertStringContainsString(
      'Target PR advanced before Composer lock publication.',
      $publish,
    );
    self::assertStringContainsString(
      'git push origin "HEAD:$EXPECTED_HEAD_REF"',
      $publish,
    );
    self::assertStringContainsString(
      'Artifact selector metadata mismatch.',
      $publish,
    );
    self::assertStringContainsString(
      'Artifact resolved-version map mismatch.',
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
   * Profile data must remain repository-owned, compatible and fail closed.
   */
  public function testProfilesAreFixedBackwardCompatibleAndFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/runner/composer-materialization-profiles.sh';
    self::assertFileExists($path);

    $profiles = (string) file_get_contents($path);
    self::assertStringContainsString('canvas-ai-agents-530)', $profiles);
    self::assertStringContainsString('config-language-lock-628)', $profiles);
    self::assertStringContainsString(
      'drupal-maintenance-ai-1.5-rc1)',
      $profiles,
    );
    self::assertStringContainsString('dependency-maintenance-962)', $profiles);
    self::assertStringContainsString(
      "COMPOSER_MODE='lock-refresh'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_OWNER_ISSUE='962'",
      $profiles,
    );

    $selectors = 'drupal/core-recommended '
      . 'drupal/core-composer-scaffold '
      . 'drupal/core-project-message '
      . 'drupal/core-recipe-unpack '
      . 'drupal/core-dev phpstan/phpstan composer/composer';
    self::assertStringContainsString(
      "COMPOSER_LOCK_REFRESH_SELECTORS='{$selectors}'",
      $profiles,
    );
    self::assertStringContainsString(
      '"drupal/core-recommended":"^11\\\\.4\\\\.[0-9]+$"',
      $profiles,
    );
    self::assertStringContainsString(
      '"phpstan/phpstan":"^2\\\\.2\\\\.[0-9]+$"',
      $profiles,
    );
    self::assertStringContainsString(
      '"composer/composer":"^2\\\\.10\\\\.[0-9]+$"',
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

    foreach ([
      'canvas-ai-agents-530',
      'config-language-lock-628',
      'drupal-maintenance-ai-1.5-rc1',
    ] as $profile) {
      $command = 'COMPOSER_PROFILE=' . escapeshellarg($profile)
        . ' bash -c '
        . escapeshellarg(
          'source ' . escapeshellarg($path)
          . '; test "$COMPOSER_MODE" = package',
        )
        . ' 2>&1';
      $profileOutput = [];
      $profileExitCode = 0;
      exec($command, $profileOutput, $profileExitCode);
      self::assertSame(
        0,
        $profileExitCode,
        $profile . ': ' . implode("\n", $profileOutput),
      );
    }

    $lockRefreshOutput = [];
    $lockRefreshExitCode = 0;
    exec(
      'COMPOSER_PROFILE=dependency-maintenance-962 bash -c '
      . escapeshellarg(
        'source ' . escapeshellarg($path)
        . '; test "$COMPOSER_MODE" = lock-refresh'
        . '; test -n "$COMPOSER_LOCK_REFRESH_SELECTORS"',
      )
      . ' 2>&1',
      $lockRefreshOutput,
      $lockRefreshExitCode,
    );
    self::assertSame(
      0,
      $lockRefreshExitCode,
      implode("\n", $lockRefreshOutput),
    );

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
