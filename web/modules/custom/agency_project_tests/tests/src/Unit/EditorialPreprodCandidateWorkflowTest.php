<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the #959 Article-only PREPROD candidate route.
 *
 * @group agency_project_tests
 * @group editorial_preprod_candidate
 */
final class EditorialPreprodCandidateWorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/trusted-editorial-preprod-candidate.yml';

  /**
   * Proves the workflow stays reusable behind the single dispatcher.
   */
  public function testWorkflowIsReusableAndNeverOwnsIssueCommentListener(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/' . self::WORKFLOW;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    $on = $parsed['on'] ?? NULL;
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayNotHasKey('issue_comment', $on);

    $source = (string) file_get_contents($path);
    self::assertStringContainsString('/agency-editorial-candidate inspect', $source);
    self::assertStringContainsString('/agency-editorial-candidate dry-run', $source);
    self::assertStringContainsString('/agency-editorial-candidate apply', $source);
    self::assertStringNotContainsString('workflow_dispatch:', $source);
  }

  /**
   * Proves the PREPROD route reuses the exact #576 payload contract.
   */
  public function testExisting576PayloadContractIsReusedExactly(): void {
    $source = $this->source(self::WORKFLOW);
    foreach ([
      '<!-- agency-editorial-payload:v1 -->',
      "'schema_version', 'issue_number', 'bundle', 'published'",
      "'category', 'fr', 'en'",
      "expected_lang = {'title', 'short_description', 'body_html'}",
      "expected_category = {'tid', 'name'}",
      "payload.get('bundle') != 'article'",
      'sort_keys=True',
      "separators=(',', ':')",
      'hashlib.sha256',
    ] as $needle) {
      self::assertStringContainsString($needle, $source);
    }
    self::assertStringContainsString('candidate_revision', $source);
    self::assertStringContainsString('same payload hash, comment revision and live main', $source);
  }

  /**
   * Proves PREPROD execution cannot receive production execution inputs.
   */
  public function testPreprodRouteHasNoProductionExecutionInput(): void {
    $workflow = $this->source(self::WORKFLOW);
    $runner = $this->source('scripts/runner/run-editorial-preprod-candidate.sh');
    $combined = $workflow . "\n" . $runner;

    self::assertStringContainsString('PREPROD_SSH_PRIVATE_KEY', $workflow);
    self::assertStringContainsString('PREPROD_SERVER_HOST', $workflow);
    self::assertStringNotContainsString('secrets.SSH_PRIVATE_KEY', $workflow);
    self::assertStringNotContainsString('secrets.SERVER_HOST', $workflow);
    self::assertStringNotContainsString('secrets.SERVER_USER', $workflow);
    self::assertStringContainsString('agency-preprod@', $runner);
    self::assertStringContainsString('/var/www/agency-preprod/current', $runner);
    self::assertStringContainsString('verify-preprod-pinned-trust.sh', $runner);
    self::assertStringContainsString('scripts/preproduction/validate-runtime.sh', $runner);
    self::assertStringNotContainsString('/var/www/agency/current', $runner);
    self::assertStringNotContainsString('deploy-production.sh', $combined);
    self::assertStringNotContainsString('drush cim', $combined);
    self::assertStringNotContainsString('drush updb', $combined);
    self::assertStringNotContainsString('emerging:governed-content', $combined);
  }

  /**
   * Proves only bounded metadata evidence is uploaded by the workflow.
   */
  public function testOnlyMetadataEvidenceIsUploaded(): void {
    $source = $this->source(self::WORKFLOW);
    self::assertStringContainsString(
      'path: artifacts/editorial-preprod-candidate/result.json',
      $source,
    );
    self::assertStringContainsString('prod_write: \`NONE\`', $source);
    self::assertStringContainsString(
      'GITHUB_ISSUE_COMMENT',
      $this->source('scripts/runner/editorial-preprod-candidate.php'),
    );
    self::assertStringNotContainsString(
      'agency-editorial-payload.json\n          if-no-files-found',
      $source,
    );
  }

  /**
   * Proves the runner syntax and helper remain Article-specific.
   */
  public function testRunnerSyntaxAndArticleSpecificHelper(): void {
    $root = dirname(DRUPAL_ROOT);
    $shell = $root . '/scripts/runner/run-editorial-preprod-candidate.sh';
    $candidate = $root . '/scripts/runner/editorial-preprod-candidate.php';
    $runner = $root . '/scripts/runner/editorial-preprod-candidate-runner.php';
    foreach ([$shell, $candidate, $runner] as $path) {
      self::assertFileExists($path);
    }

    $output = [];
    $exit = 0;
    exec('bash -n ' . escapeshellarg($shell) . ' 2>&1', $output, $exit);
    self::assertSame(0, $exit, implode("\n", $output));

    foreach ([$candidate, $runner] as $path) {
      $output = [];
      $exit = 0;
      exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exit);
      self::assertSame(0, $exit, implode("\n", $output));
    }

    $source = (string) file_get_contents($candidate);
    self::assertStringContainsString("private const BUNDLE = 'article'", $source);
    self::assertStringContainsString('AgencyEditorialPublication', $source);
    self::assertStringContainsString("'UPDATE_READY'", $source);
    self::assertStringContainsString("'IDEMPOTENT'", $source);
    self::assertStringNotContainsString('bundleName', $source);
    self::assertStringNotContainsString("payload['entity_type']", $source);
    self::assertStringNotContainsString("'entity_type' =>", $source);
  }

  /**
   * Returns one repository file as source text.
   */
  private function source(string $relative): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relative);
  }

}
