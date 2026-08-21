<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded production browser proof for editor-owned Articles.
 *
 * @group agency_project_tests
 * @group production_editorial_browser_proof
 */
final class ProductionEditorialBrowserProofWorkflowTest extends TestCase {

  /**
   * The workflow must stay issue-bound, actor-bound and main-trusted.
   */
  public function testWorkflowControlSurfaceIsClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-production-editorial-browser-proof.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString('/agency-production-browser validate', $workflow);
    self::assertStringContainsString("github.actor == 'E-merging-digital'", $workflow);
    self::assertStringContainsString("ISSUE_NUMBER\" == '401'", $workflow);
    self::assertStringContainsString('currently on live main', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('production-editorial-401.json', $workflow);
    self::assertStringContainsString('production-editorial-article.spec.mjs', $workflow);
    self::assertStringContainsString('https://emergingdigital.be', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * The repository-owned production contract must remain exact and narrow.
   */
  public function testProductionContractIsClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/tests/browser/contracts/production-editorial-401.json';
    self::assertFileExists($path);

    $contract = json_decode(
      (string) file_get_contents($path),
      TRUE,
      16,
      JSON_THROW_ON_ERROR,
    );
    self::assertSame([
      'id',
      'issue_number',
      'target',
      'actor',
      'origin',
      'category',
      'sitemap',
      'locales',
      'hreflang',
      'internal_links',
    ], array_keys($contract));
    self::assertSame('production-editorial-401', $contract['id']);
    self::assertSame(401, $contract['issue_number']);
    self::assertSame('anonymous', $contract['actor']);
    self::assertSame('https://emergingdigital.be', $contract['origin']);
    self::assertSame(['fr', 'en'], array_keys($contract['locales']));
    self::assertSame(['fr', 'en', 'x-default'], array_keys($contract['hreflang']));
    self::assertSame(
      'Checklist de préparation avant la refonte d’un site web',
      $contract['locales']['fr']['image_alt'],
    );
    self::assertSame(
      'Website redesign preparation checklist',
      $contract['locales']['en']['image_alt'],
    );
  }

  /**
   * The proof must reuse the existing Playwright audit primitives.
   */
  public function testScenarioReusesExistingBrowserAudit(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/tests/browser/production-editorial-article.spec.mjs';
    self::assertFileExists($path);

    $scenario = (string) file_get_contents($path);
    self::assertStringContainsString("from './support/browser-audit.mjs'", $scenario);
    self::assertStringContainsString("link[rel=\"canonical\"]", $scenario);
    self::assertStringContainsString('link[rel="alternate"][hreflang]', $scenario);
    self::assertStringContainsString('<loc>${expectedUrl}</loc>', $scenario);
    self::assertStringContainsString('image_alt', $scenario);
    self::assertStringContainsString('naturalWidth', $scenario);
    self::assertStringContainsString('hasHorizontalOverflow', $scenario);
    self::assertStringContainsString('audit.consoleErrors', $scenario);
    self::assertStringContainsString('audit.failedRequests', $scenario);
  }

  /**
   * The production route is public and must not gain secret or mutation inputs.
   */
  public function testWorkflowIsReadOnlyAndSecretFree(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-editorial-browser-proof.yml',
    );

    foreach ([
      'secrets.',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'SERVER_USER',
      'drush ',
      'ddev ',
      'deploy-production.sh',
      'composer require',
      'curl ',
      'wget ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }

    self::assertStringContainsString('BROWSER_VALIDATION_BASE_URL: https://emergingdigital.be', $workflow);
    self::assertStringContainsString(
      'npm run browser:validate -- tests/browser/production-editorial-article.spec.mjs',
      $workflow,
    );
  }

}
