<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded read-only PREPROD Blog image diagnostic.
 *
 * @group agency_project_tests
 * @group preprod_blog_image_diagnostic
 */
final class PreprodBlogImageDiagnosticWorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/preprod-blog-image-diagnostic.yml';
  private const RUNNER = 'scripts/runner/run-preprod-blog-image-diagnostic.sh';
  private const DIAGNOSTIC = 'scripts/runner/preprod-blog-image-diagnostic.php';

  public function testWorkflowIsBoundToIssue966AndExactCommand(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);

    self::assertArrayHasKey('workflow_call', $workflow['on'] ?? []);
    self::assertArrayHasKey('pull_request', $workflow['on'] ?? []);
    self::assertArrayNotHasKey('issue_comment', $workflow['on'] ?? []);
    self::assertStringContainsString('github.event.issue.number == 966', $source);
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-preprod-blog-image-diagnostic diagnose'",
      $source,
    );
    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'966\' ]]', $source);
    self::assertStringContainsString('[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]', $source);
    self::assertStringContainsString('EVENT_DEFAULT_SHA', $source);
    self::assertStringContainsString('JIT revalidate live main before PREPROD identity', $source);

    $secrets = $workflow['on']['workflow_call']['secrets'] ?? [];
    self::assertSame(
      ['PREPROD_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($secrets),
    );
  }

  public function testPullRequestValidationCannotReachRuntime(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $static = $workflow['jobs']['static-validation'] ?? NULL;
    self::assertIsArray($static);
    self::assertSame(
      '${{ github.event_name == \'pull_request\' }}',
      $static['if'] ?? NULL,
    );
    self::assertArrayNotHasKey('secrets', $static);

    $staticSource = json_encode($static, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('PREPROD_SSH_PRIVATE_KEY', $staticSource);
    self::assertStringNotContainsString('PREPROD_SERVER_HOST', $staticSource);
    self::assertStringNotContainsString('ssh ', $staticSource);
    self::assertStringNotContainsString('scp ', $staticSource);
  }

  public function testRunnerUsesOnlyExistingPinnedPreprodReadPath(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'966\' ]]', $runner);
    self::assertStringContainsString('agency-preprod@$PREPROD_SERVER_HOST', $runner);
    self::assertStringContainsString('/var/www/agency-preprod/current', $runner);
    self::assertStringContainsString(
      'scripts/preproduction-ssh-trust/manage-known-host.sh',
      $runner,
    );
    self::assertStringContainsString(
      'scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh',
      $runner,
    );
    self::assertStringContainsString('StrictHostKeyChecking=yes', $runner);
    self::assertStringContainsString('vendor/bin/drush status', $runner);
    self::assertStringContainsString('vendor/bin/drush php:eval', $runner);
    self::assertStringContainsString('scripts/preproduction/validate-runtime.sh', $runner);

    self::assertStringNotContainsString('scp ', $runner);
    self::assertStringNotContainsString('ssh-keyscan', $runner);
    self::assertStringNotContainsString('StrictHostKeyChecking=no', $runner);
    self::assertStringNotContainsString('accept-new', $runner);
    self::assertStringNotContainsString('SERVER_USER', $runner);
    self::assertStringNotContainsString('SSH_PRIVATE_KEY', $runner);
    self::assertStringNotContainsString(
      'SERVER_HOST',
      str_replace('PREPROD_SERVER_HOST', '', $runner),
    );
    self::assertStringNotContainsString('/var/www/agency-prod', $runner);
    self::assertStringNotContainsString('drush cr', $runner);
    self::assertStringNotContainsString('cache:rebuild', $runner);
    self::assertStringNotContainsString('maint:set', $runner);
    self::assertStringNotContainsString('sql:', $runner);
    self::assertStringNotContainsString('config:set', $runner);
    self::assertStringNotContainsString('entity:delete', $runner);
  }

  public function testDrupalDiagnosticProvesExactEntityFileAndPhysicalChain(): void {
    $php = $this->source(self::DIAGNOSTIC);

    self::assertStringContainsString('AGENCY_PREPROD_IMAGE_DIAGNOSTIC_ISSUE = 401', $php);
    self::assertStringContainsString(
      'Checklist avant une refonte de site internet : 12 points à vérifier',
      $php,
    );
    self::assertStringContainsString("AGENCY_PREPROD_IMAGE_FIELD = 'field_feature_image'", $php);
    self::assertStringContainsString("agency_editorial.issue.' . AGENCY_PREPROD_IMAGE_DIAGNOSTIC_ISSUE", $php);
    self::assertStringContainsString("getStorage('file')->load", $php);
    self::assertStringContainsString('str_starts_with($uri, \'public://\')', $php);
    self::assertStringContainsString("service('file_system')->realpath", $php);
    self::assertStringContainsString('is_file($realpath)', $php);
    self::assertStringContainsString('foreach ([\'medium\', \'large\'] as $styleName)', $php);
    self::assertStringContainsString('ImageStyle::load($styleName)', $php);
    self::assertStringContainsString('$style->buildUri($uri)', $php);
    self::assertStringContainsString("getViewDisplay('node', 'article', 'teaser')", $php);
    self::assertStringContainsString("getViewDisplay('node', 'article', 'default')", $php);
    self::assertStringContainsString('views.view.blog', $php);
    self::assertStringContainsString('media_entity_referenced', $php);
    self::assertStringContainsString('NO_BY_MODEL', $php);
  }

  public function testDiagnosticScopeIsBoundedAndDoesNotMutateDrupalOrFiles(): void {
    $php = $this->source(self::DIAGNOSTIC);

    self::assertStringContainsString('AGENCY_PREPROD_IMAGE_SCOPE_LIMIT = 100', $php);
    self::assertStringContainsString('->accessCheck(FALSE)', $php);
    self::assertStringContainsString("->condition('type', 'article')", $php);
    self::assertStringContainsString("->condition('status', 1)", $php);
    self::assertStringContainsString('->range(0, AGENCY_PREPROD_IMAGE_SCOPE_LIMIT + 1)', $php);
    self::assertStringContainsString("'missing_physical_file'", $php);
    self::assertStringContainsString("'physical_file_present'", $php);
    self::assertStringContainsString("'field_empty'", $php);
    self::assertStringContainsString("'D_PUBLIC_FILE_NOT_PRESENT_IN_PREPROD'", $php);

    foreach ([
      '->save(',
      '->delete(',
      'file_put_contents(',
      'mkdir(',
      'chmod(',
      'chown(',
      'unlink(',
      'createDerivative(',
      'renderRoot(',
      'invalidateTags(',
      'deleteAll(',
      'database()->',
      'insert(',
      'update(',
      'merge(',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $php, $forbidden);
    }
  }

  public function testEvidenceContractExplicitlyPreservesSafetyBoundary(): void {
    $php = $this->source(self::DIAGNOSTIC);
    $runner = $this->source(self::RUNNER);
    $workflow = $this->source(self::WORKFLOW);

    foreach ([$php, $runner, $workflow] as $surface) {
      self::assertStringNotContainsString('PROD_SSH_PRIVATE_KEY', $surface);
      self::assertStringNotContainsString('PREPROD_PROVISIONING_SSH_PRIVATE_KEY', $surface);
    }

    self::assertStringContainsString("'prod_access' => 'NONE'", $php);
    self::assertStringContainsString("'prod_write' => 'NONE'", $php);
    self::assertStringContainsString("'preprod_destructive_mutation' => 'NONE'", $php);
    self::assertStringContainsString('.prod_access == "NONE"', $runner);
    self::assertStringContainsString('.prod_write == "NONE"', $runner);
    self::assertStringContainsString('.preprod_destructive_mutation == "NONE"', $runner);
    self::assertStringContainsString('MODULE_INSTALL=NONE', $workflow);
  }

  /**
   * Parses one repository workflow structurally.
   *
   * @return array<string, mixed>
   */
  private function parsed(string $relativePath): array {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    return $parsed;
  }

  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}
