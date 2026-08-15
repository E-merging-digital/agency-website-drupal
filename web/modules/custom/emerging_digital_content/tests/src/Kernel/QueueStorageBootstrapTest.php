<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests queue storage initialization required before entity cleanup.
 *
 * @group emerging_digital_content
 */
#[RunTestsInSeparateProcesses]
final class QueueStorageBootstrapTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'emerging_digital_content',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once DRUPAL_ROOT . '/modules/custom/emerging_digital_content/emerging_digital_content.install';
  }

  /**
   * Tests initialization creates queue storage without residual bootstrap work.
   */
  public function testInitializeQueueStorageCreatesEmptyQueueTable(): void {
    $database = $this->container->get('database');
    $schema = $database->schema();

    if ($schema->tableExists('queue')) {
      $schema->dropTable('queue');
    }
    self::assertFalse($schema->tableExists('queue'));

    _emerging_digital_content_initialize_queue_storage();

    self::assertTrue($schema->tableExists('queue'));
    $count = (int) $database
      ->select('queue', 'q')
      ->countQuery()
      ->execute()
      ->fetchField();
    self::assertSame(0, $count);
  }

}
