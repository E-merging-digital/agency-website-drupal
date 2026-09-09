<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the #959 Article route and the #1120 Service-only extension.
 *
 * @group agency_project_tests
 * @group editorial_preprod_candidate
 */
final class EditorialPreprodCandidateWorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/trusted-editorial-preprod-candidate.yml';

  /**
   * Proves the workflow remains reusable behind the dispatcher.
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
   * Proves the existing #576 Article payload contract remains exact.
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
    self::assertStringContainsString(
      'same payload hash, candidate revision and live main',
      $source,
    );
  }

  /**
   * Proves the Service source stays bound to the merged issue candidate.
   */
  public function testServiceSourceIsBoundedToMergedIssueCandidate(): void {
    $workflow = $this->source(self::WORKFLOW);
    $parser = dirname(DRUPAL_ROOT)
      . '/scripts/runner/editorial-service-candidate-source.py';
    $candidate = dirname(DRUPAL_ROOT)
      . '/docs/seo/audit-site-web-candidate-1117.md';
    self::assertFileExists($parser);
    self::assertFileExists($candidate);

    foreach ([
      'find docs/seo -maxdepth 1 -type f',
      '-name "*-candidate-${ISSUE_NUMBER}.md"',
      "candidate_kind='service'",
      'candidate_id="agency-service-${ISSUE_NUMBER}"',
      'git rev-parse "HEAD:${candidate_source}"',
      'editorial-service-candidate-source.py',
      'merged Git main file',
    ] as $needle) {
      self::assertStringContainsString($needle, $workflow);
    }

    $candidateSource = (string) file_get_contents($candidate);
    foreach ([
      'LANGUAGE_NEGOTIATION = path_prefix',
      'FR_PUBLIC_ROUTE = /fr/audit-site-web',
      'FR_STORED_ALIAS = /audit-site-web',
      'EN_PUBLIC_ROUTE = /en/website-audit',
      'EN_STORED_ALIAS = /website-audit',
    ] as $needle) {
      self::assertStringContainsString($needle, $candidateSource);
    }

    $output = tempnam(sys_get_temp_dir(), 'agency-service-candidate-');
    self::assertIsString($output);
    $command = sprintf(
      'python3 %s --source %s --issue-number 1117 --output %s 2>&1',
      escapeshellarg($parser),
      escapeshellarg('docs/seo/audit-site-web-candidate-1117.md'),
      escapeshellarg($output),
    );
    $root = dirname(DRUPAL_ROOT);
    $lines = [];
    $exit = 0;
    exec('cd ' . escapeshellarg($root) . ' && ' . $command, $lines, $exit);
    self::assertSame(0, $exit, implode("\n", $lines));
    self::assertCount(1, $lines);
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $lines[0]);
    $payload = json_decode(
      (string) file_get_contents($output),
      TRUE,
      32,
      JSON_THROW_ON_ERROR,
    );
    @unlink($output);
    self::assertSame(2, $payload['schema_version']);
    self::assertSame('service', $payload['bundle']);
    self::assertSame(1117, $payload['issue_number']);
    self::assertSame(
      '/fr/audit-site-web',
      $payload['public_routes']['fr'],
    );
    self::assertSame(
      '/en/website-audit',
      $payload['public_routes']['en'],
    );
    self::assertSame('/audit-site-web', $payload['stored_aliases']['fr']);
    self::assertSame('/website-audit', $payload['stored_aliases']['en']);
    self::assertSame(
      ['detailed_description_html', 'short_description', 'title'],
      array_keys($payload['fr']),
    );
    self::assertSame(
      ['detailed_description_html', 'short_description', 'title'],
      array_keys($payload['en']),
    );
  }

  /**
   * Proves dry-run receipt authorization stays exact and fail-closed.
   */
  public function testDryRunReceiptAuthorizationMatchesRealOutputAndFailsClosed(): void {
    $source = $this->source(self::WORKFLOW);
    foreach ([
      "heading = '### Agency editorial PREPROD candidate dry-run PASS'",
      "'candidate_revision': re.compile(r'^candidate_revision: `([0-9]+|[0-9a-f]{40})`$')",
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

    self::assertTrue(
      $this->receiptAuthorizes(
        'github-actions[bot]',
        $receipt,
        $revision,
        $hash,
        $main,
      ),
    );
    self::assertFalse(
      $this->receiptAuthorizes(
        'E-merging-digital',
        $receipt,
        $revision,
        $hash,
        $main,
      ),
    );
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
    self::assertFalse(
      $this->receiptAuthorizes(
        'github-actions[bot]',
        $missing,
        $revision,
        $hash,
        $main,
      ),
    );

    $duplicate = str_replace(
      "payload_sha256: `$hash`",
      "payload_sha256: `$hash`\npayload_sha256: `$hash`",
      $receipt,
    );
    self::assertFalse(
      $this->receiptAuthorizes(
        'github-actions[bot]',
        $duplicate,
        $revision,
        $hash,
        $main,
      ),
    );

    $malformed = str_replace(
      "candidate_revision: `$revision`",
      'candidate_revision: `not-a-number`',
      $receipt,
    );
    self::assertFalse(
      $this->receiptAuthorizes(
        'github-actions[bot]',
        $malformed,
        $revision,
        $hash,
        $main,
      ),
    );

    $serviceRevision = str_repeat('c', 40);
    $serviceReceipt = str_replace(
      "candidate_revision: `$revision`",
      "candidate_revision: `$serviceRevision`",
      $receipt,
    );
    self::assertTrue($this->receiptAuthorizes(
      'github-actions[bot]',
      $serviceReceipt,
      $serviceRevision,
      $hash,
      $main,
    ));
  }

  /**
   * Proves the PREPROD route has no production execution inputs.
   */
  public function testPreprodRouteHasNoProductionExecutionInput(): void {
    $workflow = $this->source(self::WORKFLOW);
    $runner = $this->source('scripts/runner/run-editorial-preprod-candidate.sh');
    $service = $this->source(
      'scripts/runner/editorial-service-preprod-candidate.php',
    );
    $combined = $workflow . "\n" . $runner . "\n" . $service;

    self::assertStringContainsString('PREPROD_SSH_PRIVATE_KEY', $workflow);
    self::assertStringContainsString('PREPROD_SERVER_HOST', $workflow);
    self::assertStringNotContainsString('secrets.SSH_PRIVATE_KEY', $workflow);
    self::assertStringNotContainsString('secrets.SERVER_HOST', $workflow);
    self::assertStringNotContainsString('secrets.SERVER_USER', $workflow);
    self::assertStringContainsString('agency-preprod@', $runner);
    self::assertStringContainsString('/var/www/agency-preprod/current', $runner);
    self::assertStringContainsString('verify-preprod-pinned-trust.sh', $runner);
    self::assertStringContainsString(
      'scripts/preproduction/validate-runtime.sh',
      $runner,
    );
    self::assertStringNotContainsString('/var/www/agency/current', $runner);
    self::assertStringNotContainsString('deploy-production.sh', $combined);
    self::assertStringNotContainsString('drush cim', $combined);
    self::assertStringNotContainsString('drush updb', $combined);
    self::assertStringNotContainsString('emerging:governed-content', $combined);
    self::assertStringNotContainsString('emerging:content-sync', $combined);
  }

  /**
   * Proves only metadata evidence is uploaded by the workflow.
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
    self::assertStringContainsString(
      'GIT_MAIN_FILE',
      $this->source('scripts/runner/editorial-service-preprod-candidate.php'),
    );
    self::assertStringNotContainsString(
      'agency-editorial-payload.json\n          if-no-files-found',
      $source,
    );
  }

  /**
   * Proves runner syntax and helpers stay closed to Article or Service.
   */
  public function testRunnerSyntaxAndHelpersStayClosedToArticleOrService(): void {
    $root = dirname(DRUPAL_ROOT);
    $shell = $root . '/scripts/runner/run-editorial-preprod-candidate.sh';
    $article = $root . '/scripts/runner/editorial-preprod-candidate.php';
    $service = $root . '/scripts/runner/editorial-service-preprod-candidate.php';
    $runner = $root . '/scripts/runner/editorial-preprod-candidate-runner.php';
    $parser = $root . '/scripts/runner/editorial-service-candidate-source.py';
    foreach ([$shell, $article, $service, $runner, $parser] as $path) {
      self::assertFileExists($path);
    }

    $output = [];
    $exit = 0;
    exec('bash -n ' . escapeshellarg($shell) . ' 2>&1', $output, $exit);
    self::assertSame(0, $exit, implode("\n", $output));

    foreach ([$article, $service, $runner] as $path) {
      $output = [];
      $exit = 0;
      exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exit);
      self::assertSame(0, $exit, implode("\n", $output));
    }
    $output = [];
    $exit = 0;
    exec(
      'python3 -m py_compile ' . escapeshellarg($parser) . ' 2>&1',
      $output,
      $exit,
    );
    self::assertSame(0, $exit, implode("\n", $output));

    $articleSource = (string) file_get_contents($article);
    self::assertStringContainsString(
      "private const BUNDLE = 'article'",
      $articleSource,
    );
    self::assertStringContainsString('AgencyEditorialPublication', $articleSource);
    self::assertStringNotContainsString('bundleName', $articleSource);

    $serviceSource = (string) file_get_contents($service);
    self::assertStringContainsString(
      "private const BUNDLE = 'service'",
      $serviceSource,
    );
    self::assertStringContainsString(
      'Only bundle=service is allowed',
      $serviceSource,
    );
    self::assertStringContainsString(
      "['field_short_description', 'field_detailed_description', 'path']",
      $serviceSource,
    );
    self::assertStringContainsString("'public_routes'", $serviceSource);
    self::assertStringContainsString("'stored_aliases'", $serviceSource);
    self::assertStringContainsString('ALIAS_REPAIR_READY', $serviceSource);
    self::assertStringNotContainsString("payload['aliases']", $serviceSource);
    self::assertStringNotContainsString('bundleName', $serviceSource);
    self::assertStringNotContainsString("payload['entity_type']", $serviceSource);
    self::assertStringNotContainsString("'entity_type' =>", $serviceSource);

    $shellSource = (string) file_get_contents($shell);
    self::assertStringContainsString('article|service', $shellSource);
    self::assertStringNotContainsString('page|service', $shellSource);
  }

  /**
   * Mirrors the workflow receipt authorization contract.
   *
   * @param string $author
   *   Comment author.
   * @param string $body
   *   Comment body.
   * @param string $expectedRevision
   *   Expected candidate revision.
   * @param string $expectedHash
   *   Expected payload hash.
   * @param string $expectedMain
   *   Expected trusted main SHA.
   *
   * @return bool
   *   TRUE when the receipt authorizes the exact candidate.
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
    if (($lines[0] ?? '')
      !== '### Agency editorial PREPROD candidate dry-run PASS') {
      return FALSE;
    }

    $patterns = [
      'candidate_revision' => '/^candidate_revision: `([0-9]+|[0-9a-f]{40})`$/',
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
   * Returns one canonical dry-run receipt fixture.
   *
   * @return string
   *   Dry-run receipt.
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
   * Returns repository source text for a relative path.
   *
   * @param string $relative
   *   Relative repository path.
   *
   * @return string
   *   File source.
   */
  private function source(string $relative): string {
    return (string) file_get_contents(
      dirname(DRUPAL_ROOT) . '/' . $relative,
    );
  }

}
