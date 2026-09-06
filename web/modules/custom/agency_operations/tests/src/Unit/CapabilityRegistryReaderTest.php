<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_operations\Unit;

use Drupal\agency_operations\Service\CapabilityRegistryReader;
use Drupal\Tests\UnitTestCase;

/**
 * Proves the cockpit derives presentation from the existing registry file.
 *
 * @group agency_operations
 */
final class CapabilityRegistryReaderTest extends UnitTestCase {

  /**
   * Temporary project root used by the fixed-path registry reader.
   */
  private string $fixtureRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fixtureRoot = sys_get_temp_dir() . '/agency-operations-' . bin2hex(random_bytes(6));
    mkdir($this->fixtureRoot . '/web', 0777, TRUE);
    mkdir($this->fixtureRoot . '/docs/operations', 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    @unlink($this->fixtureRoot . '/docs/operations/execution-capabilities.md');
    @rmdir($this->fixtureRoot . '/docs/operations');
    @rmdir($this->fixtureRoot . '/docs');
    @rmdir($this->fixtureRoot . '/web');
    @rmdir($this->fixtureRoot);
    parent::tearDown();
  }

  /**
   * Proves the existing Markdown registry is parsed into the four UI groups.
   */
  public function testExistingRegistryIsParsedAndGrouped(): void {
    file_put_contents(
      $this->fixtureRoot . '/docs/operations/execution-capabilities.md',
      "# Registry\n\n## 3. Current operational capability index\n\n"
      . "| Capability | Owner | Status | Current execution surface | Mutation/data boundary |\n"
      . "| --- | --- | --- | --- | --- |\n"
      . "| Immutable code/config build | release | `PROVEN` | hosted | artifact only |\n"
      . "| PROD -> PREPROD sanitized DB refresh | #816 | `PROVEN` | controlled | PREPROD only |\n"
      . "| Editorial Candidate PREPROD | #872 | `PENDING` | controlled | PREPROD |\n"
      . "| Development Seed DDEV consumer | #873 | `PROVEN` | DDEV | pull-only |\n"
      . "\n## 4. Next section\n",
    );

    $result = (new CapabilityRegistryReader($this->fixtureRoot . '/web'))->read();

    self::assertTrue($result['available']);
    self::assertCount(1, $result['groups']['CODE_CONFIG']);
    self::assertCount(1, $result['groups']['DATA_REFRESH']);
    self::assertCount(1, $result['groups']['EDITORIAL']);
    self::assertCount(1, $result['groups']['DEVELOPMENT_DATA']);
    self::assertSame('PROVEN', $result['groups']['CODE_CONFIG'][0]['status']);
  }

}
