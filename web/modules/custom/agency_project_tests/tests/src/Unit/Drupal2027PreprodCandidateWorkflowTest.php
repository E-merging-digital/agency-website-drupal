<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Locks the #1012 closed PREPROD route without widening #959.
 *
 * @group agency_project_tests
 * @group drupal_2027_preprod_candidate
 */
#[Group('drupal_2027_preprod_candidate')]
final class Drupal2027PreprodCandidateWorkflowTest extends TestCase {

  private const EXPECTED_REVISION = '5553858896';
  private const EXPECTED_SHA = '07fb10ab4a54371d877fbfc6b3f185eda41085ae3bd5080de2d695843c9d049e';
  private const EXPECTED_MAIN = '33098073ee75a91a487fc40c76f71b731015b1c8';
  private const RECEIPT_HEADING = '### Agency Drupal 2027 PREPROD candidate dry-run PASS';

  /**
   * The new workflow is bound to one issue/profile/target and dry-run receipt.
   */
  public function testWorkflowIsClosedAndHashBound(): void {
    $workflow = $this->repositoryFile(
      '.github/workflows/trusted-drupal-2027-preprod-candidate.yml',
    );

    self::assertStringContainsString('github.event.issue.number == 1012', $workflow);
    self::assertStringContainsString('/agency-drupal-2027-candidate dry-run', $workflow);
    self::assertStringContainsString('/agency-drupal-2027-candidate apply', $workflow);
    self::assertStringContainsString('candidate_sha256=', $workflow);
    self::assertStringContainsString('trusted_main=', $workflow);
    self::assertStringContainsString('source_updated_at', $workflow);
    self::assertStringContainsString('PREPROD', $workflow);
    self::assertStringContainsString('/fr/drupal-2027', $workflow);
    self::assertStringContainsString("github-actions[bot]", $workflow);
    self::assertStringContainsString('BEGIN DRUPAL_2027_RECEIPT_PARSER', $workflow);
    self::assertStringNotContainsString('.user.type == "Bot"', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * The runtime helper has fixed page/fr/alias constants and no SQL path.
   */
  public function testProfileIsNotGenericWriter(): void {
    $profile = $this->repositoryFile(
      'scripts/runner/drupal-2027-preprod-candidate.php',
    );
    $shell = $this->repositoryFile(
      'scripts/runner/run-drupal-2027-preprod-candidate.sh',
    );

    self::assertStringContainsString("private const BUNDLE = 'page';", $profile);
    self::assertStringContainsString("private const LANGCODE = 'fr';", $profile);
    self::assertStringContainsString("private const PUBLIC_ALIAS = '/fr/drupal-2027';", $profile);
    self::assertStringContainsString("private const DRUPAL_ALIAS = '/drupal-2027';", $profile);
    self::assertStringContainsString(
      "internal:/drupal-2027#points-a-verifier-socle",
      $profile,
    );
    self::assertStringContainsString('ParagraphInterface', $profile);
    self::assertStringContainsString("getStorage('node')->create", $profile);
    self::assertStringContainsString("'pathauto' => 0", $profile);
    self::assertStringContainsString('FAIL_CLOSED', $profile);
    self::assertStringNotContainsString('database()', $profile);
    self::assertStringNotContainsString('->query(', $profile);
    self::assertStringContainsString('agency-preprod@', $shell);
    self::assertStringContainsString('verify-preprod-pinned-trust.sh', $shell);
    self::assertStringContainsString('validate-runtime.sh', $shell);
  }

  /**
   * The #959 route remains Article-only with mandatory EN translation.
   */
  public function testExisting959ArticleOnlyContractIsPreserved(): void {
    $article = $this->repositoryFile(
      'scripts/runner/editorial-preprod-candidate.php',
    );
    $finalizer = $this->repositoryFile(
      'web/modules/custom/emerging_digital_content/src/Service/EditorialPathautoFinalizer.php',
    );

    self::assertStringContainsString("private const BUNDLE = 'article';", $article);
    self::assertStringContainsString("private const TRANSLATION_LANGCODE = 'en';", $finalizer);
    self::assertStringContainsString('requires the EN translation', $finalizer);
  }

  /**
   * The real browser contract covers the required review surface.
   */
  public function testBrowserContractIsRegistered(): void {
    $config = $this->repositoryFile('playwright.config.mjs');
    $spec = $this->repositoryFile('tests/browser/drupal-2027-preprod.spec.mjs');

    self::assertStringContainsString("'drupal-2027-preprod.json'", $config);
    self::assertStringContainsString('services-page-hero.svg', $spec);
    self::assertStringContainsString('drupal-lifecycle-diagnostic', $spec);
    self::assertStringContainsString('#points-a-verifier-socle', $spec);
    self::assertStringContainsString('accessibility_baseline', $spec);
    self::assertStringContainsString('hreflangEn', $spec);
    self::assertStringContainsString('noindex', $spec);
  }

  /**
   * The exact github-actions dry-run receipt authorizes the matching apply.
   */
  public function testExactDryRunReceiptIsAccepted(): void {
    self::assertTrue($this->receiptAccepted([$this->validReceipt()]));
  }

  /**
   * The real receipt parser fails closed for every security-relevant drift.
   */
  public function testDryRunReceiptParserRefusesInvalidReceipts(): void {
    $valid = $this->validReceipt();

    $wrongSha = $valid;
    $wrongSha['body'] = str_replace(
      self::EXPECTED_SHA,
      str_repeat('a', 64),
      $wrongSha['body'],
    );

    $wrongRevision = $valid;
    $wrongRevision['body'] = str_replace(
      'candidate_revision=' . self::EXPECTED_REVISION,
      'candidate_revision=5546486305',
      $wrongRevision['body'],
    );

    $wrongMain = $valid;
    $wrongMain['body'] = str_replace(
      self::EXPECTED_MAIN,
      str_repeat('b', 40),
      $wrongMain['body'],
    );

    $missingField = $valid;
    $missingField['body'] = str_replace(
      'candidate_sha256=' . self::EXPECTED_SHA . "\n",
      '',
      $missingField['body'],
    );

    $duplicateField = $valid;
    $duplicateField['body'] = str_replace(
      'candidate_sha256=' . self::EXPECTED_SHA,
      'candidate_sha256=' . self::EXPECTED_SHA . "\n"
        . 'candidate_sha256=' . self::EXPECTED_SHA,
      $duplicateField['body'],
    );

    $malformedField = $valid;
    $malformedField['body'] = str_replace(
      'candidate_revision=' . self::EXPECTED_REVISION,
      'candidate_revision=not-an-integer',
      $malformedField['body'],
    );

    $otherBot = $valid;
    $otherBot['user']['login'] = 'dependabot[bot]';

    $humanAuthor = $valid;
    $humanAuthor['user'] = [
      'login' => 'E-merging-digital',
      'type' => 'User',
    ];

    $wrongHeading = $valid;
    $wrongHeading['body'] = str_replace(
      self::RECEIPT_HEADING,
      '### Agency Drupal 2027 PREPROD candidate apply PASS',
      $wrongHeading['body'],
    );

    $cases = [
      'wrong_sha' => $wrongSha,
      'wrong_revision' => $wrongRevision,
      'wrong_main' => $wrongMain,
      'missing_field' => $missingField,
      'duplicate_security_field' => $duplicateField,
      'malformed_security_field' => $malformedField,
      'other_bot' => $otherBot,
      'human_author' => $humanAuthor,
      'wrong_heading' => $wrongHeading,
    ];

    foreach ($cases as $label => $comment) {
      self::assertFalse(
        $this->receiptAccepted([$comment]),
        sprintf('Invalid receipt case %s was accepted.', $label),
      );
    }
  }

  /**
   * Returns one exact dry-run PASS comment fixture.
   *
   * @return array<string, mixed>
   *   GitHub issue comment fixture.
   */
  private function validReceipt(): array {
    return [
      'user' => [
        'login' => 'github-actions[bot]',
        'type' => 'Bot',
      ],
      'body' => implode("\n", [
        self::RECEIPT_HEADING,
        '',
        'mode=dry-run',
        'profile=drupal-2027-landing',
        'candidate_revision=' . self::EXPECTED_REVISION,
        'candidate_sha256=' . self::EXPECTED_SHA,
        'trusted_main=' . self::EXPECTED_MAIN,
        'target=PREPROD',
        'prod_write=NONE',
      ]),
    ];
  }

  /**
   * Executes the exact parser embedded in the #1012 workflow.
   *
   * @param array<int, array<string, mixed>> $comments
   *   GitHub issue comments to validate.
   */
  private function receiptAccepted(array $comments): bool {
    $workflow = $this->repositoryFile(
      '.github/workflows/trusted-drupal-2027-preprod-candidate.yml',
    );
    $parser = $this->receiptParser($workflow);
    $commentsFile = tempnam(sys_get_temp_dir(), 'agency-1012-comments-');
    $parserFile = tempnam(sys_get_temp_dir(), 'agency-1012-parser-');
    self::assertIsString($commentsFile);
    self::assertIsString($parserFile);

    try {
      $encoded = json_encode([$comments], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
      self::assertNotFalse(file_put_contents($commentsFile, $encoded));
      self::assertNotFalse(file_put_contents($parserFile, $parser));

      $environment = getenv();
      self::assertIsArray($environment);
      $environment['COMMENTS_FILE'] = $commentsFile;
      $environment['CANDIDATE_REVISION'] = self::EXPECTED_REVISION;
      $environment['CANDIDATE_SHA256'] = self::EXPECTED_SHA;
      $environment['TRUSTED_MAIN'] = self::EXPECTED_MAIN;

      $process = proc_open(
        ['python3', $parserFile],
        [
          0 => ['pipe', 'r'],
          1 => ['pipe', 'w'],
          2 => ['pipe', 'w'],
        ],
        $pipes,
        NULL,
        $environment,
      );
      self::assertIsResource($process);
      fclose($pipes[0]);
      stream_get_contents($pipes[1]);
      stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);

      return proc_close($process) === 0;
    }
    finally {
      @unlink($commentsFile);
      @unlink($parserFile);
    }
  }

  /**
   * Extracts and de-indents the bounded Python receipt parser.
   */
  private function receiptParser(string $workflow): string {
    $matched = preg_match(
      '/^          # BEGIN DRUPAL_2027_RECEIPT_PARSER\R(?<parser>.*?)^          # END DRUPAL_2027_RECEIPT_PARSER$/ms',
      $workflow,
      $matches,
    );
    self::assertSame(1, $matched, 'Unable to locate the embedded #1012 receipt parser.');
    $parser = preg_replace('/^ {10}/m', '', $matches['parser']);
    self::assertIsString($parser);
    return $parser . "\n";
  }

  /**
   * Reads one repository file relative to the Drupal project root.
   */
  private function repositoryFile(string $path): string {
    $file = dirname(DRUPAL_ROOT) . '/' . ltrim($path, '/');
    $content = file_get_contents($file);
    self::assertIsString($content, sprintf('Unable to read %s.', $path));
    return $content;
  }

}
