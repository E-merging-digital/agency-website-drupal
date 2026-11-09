<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards historical IA identity without coupling other existing Articles.
 *
 * @group agency_project_tests
 */
final class AgencyExistingArticle1525HoldTest extends TestCase {

  /**
   * The stable PROD veto is independent of mutable blog aliases.
   */
  public function testHeldIdentityAndIndependentArticle(): void {
    $source = (string) file_get_contents(dirname(DRUPAL_ROOT) . '/scripts/runner/editorial-existing-article-1525.php');
    self::assertStringContainsString(
      'self::isHeldIaIdentity($target, (int) $node->id())',
      $source,
    );
    self::assertMatchesRegularExpression(
      '/isHeldIaIdentity\(\$target, \(int\) \$node->id\(\)\)\) \{'
      . '\s*throw new RuntimeException\(\'HOLD_IA_PREPROD_IDENTITY/',
      $source,
    );
    self::assertSame(1, preg_match(
      '/private static function isHeldIaIdentity\(string \$target, int \$nid\): bool \{'
      . '\s*return \$target === \'([A-Z]+)\' && \$nid === ([0-9]+);/',
      $source,
      $identity,
    ));
    $held = static fn (string $target, int $nid): bool => $target === $identity[1] && $nid === (int) $identity[2];
    self::assertTrue($held('PROD', 40), 'The held historical IA Article must be rejected.');
    self::assertFalse($held('PROD', 39), 'A different PROD Article must remain independent.');
    self::assertFalse($held('PREPROD', 40), 'Node IDs are not transferable between databases.');
  }

  /**
   * The bilingual candidate pins text formats and does not impose basic_html.
   */
  public function testExistingTextFormatsArePartOfTheExactCandidate(): void {
    $source = (string) file_get_contents(dirname(DRUPAL_ROOT) . '/scripts/runner/editorial-existing-article-1525.php');
    self::assertStringContainsString("'short_description_format' => \$this->fieldFormat", $source);
    self::assertStringContainsString("'body_format' => \$this->fieldFormat", $source);
    self::assertStringContainsString('Target FR/EN text formats differ from reviewed PREPROD candidate.', $source);
    self::assertStringNotContainsString("'format' => 'basic_html'", $source);
  }

}
