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
   * Regresses the exact real receipt format and all security-field failures.
   */
  public function testDryRunReceiptAuthorizationMatchesRealOutputAndFailsClosed(): void {
    $source = $this->source(self::WORKFLOW);
    foreach ([
      "heading = '### Agency editorial PREPROD candidate dry-run PASS'",
      "'candidate_revision': re.compile(r'^candidate_revision: `([0-9]+)`$')",
      "'payload_sha256': re.compile(r'^payload_sha256: `([0-9a-f]{64})`$')",
      "'trusted_main': re.compile(r'^trusted_main: `([0-9a-f]{40})`$')",
      "if not lines or lines[0] != heading:",
      "if malformed or len(matches) != 1:",
      "comment.get('user', {}).get('login') != 'github-actions[bot]'",
    ] as $needle) {
      self::assertStringContainsString($needle, $source);
    }

    $revision = '5510862057';
    $hash = '2e92228480ee6ae7410c028eab2b88c7d7db1534668477f6eafbc236668cb700';
    $main = '65a067691431d130bbc083423e94fa0769318612';
    $receipt = $this->realDryRunReceipt();

    self::assertTrue($this->receiptAuthorizes(
      'github-actions[bot]',
      $receipt,
      $revision,
      $hash,
      $main,
    ));

    self::assertFalse($this->receiptAuthorizes(
      'E-merging-digital',
      $receipt,
      $revision,
      $hash,
      $main,
    ));
    self::assertFalse($this->receiptAuthorizes(
      'github-actions[bot]',
      str_replace('dry-run PASS', 'dry-run FAIL', $receipt),
      $revision,
      $hash,
      $main,
    ));
    self::assertFalse($this->receiptAuthorizes(
      'github-actions[bot]',
      str_replace($hash, str_repeat('a', 64), $receipt),
      $revision,
      $hash,
      $main,
    ));
    self::assertFalse($this->receiptAuthorizes(
      'github-actions[bot]',
      str_replace($revision, '5510862058', $receipt),
      $revision,
      $hash,
      $main,
    ));
    self::assertFalse($this->receiptAuthorizes(
      'github-actions[bot]',
      str_replace($main, str_repeat('b', 40), $receipt),
      $revision,
      $hash,
      $main,
    ));

    $missing = preg_replace('/^trusted_main: .*\R?/m', '', $receipt, 1);
    self::assertIsString($missing);
    self::assertFalse($this->receiptAuthorizes(
      'github-actions[bot]',
      $missing,
      $revision,
      $hash,
      $main,
    ));

    $duplicate = str_replace(
      "payload_sha256: `$hash`",
      "payload_sha256: `$hash`\npayload_sha256: `$hash`",
      $receipt,
    );
    self::assertFalse($this->receiptAuthorizes(
      'github-actions[bot]',
      $duplicate,
      $revision,
      $hash,
      $main,
    ));

    $malformed = str_replace(
      "candidate_revision: `$revision`",
      'candidate_revision: `not-a-number`',
      $receipt,
    );
    self::assertFalse($this->receiptAuthorizes(
      'github-actions[bot]',
      $malformed,
      $revision,
      $hash,
      $main,
    ));
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
   * Mirrors the workflow's strict named-field receipt authorization contract.
   */
  private function receiptAuthorizes(
    string $author,
    string $body,
    string $expectedRevision,
    string $expectedHash,
    string $expectedMain,
  ): bool {
    if ($author !== 'github-actions[bot]') {
      return FALSE;
    }

    $lines = preg_split('/\R/u', $body) ?: [];
    if (($lines[0] ?? '') !== '### Agency editorial PREPROD candidate dry-run PASS') {
      return FALSE;
    }

    $patterns = [
      'candidate_revision' => '/^candidate_revision: `([0-9]+)`$/',
      'payload_sha256' => '/^payload_sha256: `([0-9a-f]{64})`$/',
      'trusted_main' => '/^trusted_main: `([0-9a-f]{40})`$/',
    ];
    $fields = [];
    foreach ($patterns as $name => $pattern) {
      $matches = [];
      foreach (array_slice($lines, 1) as $line) {
        if (!str_starts_with($line, $name . ':')) {
          continue;
        }
        if (preg_match($pattern, $line, $match) !== 1) {
          return FALSE;
        }
        $matches[] = $match[1];
      }
      if (count($matches) !== 1) {
        return FALSE;
      }
      $fields[$name] = $matches[0];
    }

    return $fields['candidate_revision'] === $expectedRevision
      && hash_equals($expectedHash, $fields['payload_sha256'])
      && hash_equals($expectedMain, $fields['trusted_main']);
  }

  /**
   * Exact receipt emitted by real dry-run #958 / run 33640254463.
   */
  private function realDryRunReceipt(): string {
    return <<<'RECEIPT'
### Agency editorial PREPROD candidate dry-run PASS

target: `PREPROD`
candidate_id: `agency-article-958`
candidate_revision: `5510862057`
payload_sha256: `2e92228480ee6ae7410c028eab2b88c7d7db1534668477f6eafbc236668cb700`
trusted_main: `65a067691431d130bbc083423e94fa0769318612`
run_id: `33640254463`
verdict: `READY`
node_id: `n/a`
revision_id: `n/a`
fr_url: `n/a`
en_url: `n/a`
prod_write: `NONE`
RECEIPT;
  }

  /**
   * Returns one repository file as source text.
   */
  private function source(string $relative): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relative);
  }

}
