<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\emerging_digital_content\Drush\Commands\ContentSyncCommands;
use Drush\Attributes\Command;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the Governed Content CLI compatibility facade visible to standard CI.
 *
 * @group agency_project_tests
 * @group governed_content
 */
final class GovernedContentCliFacadeCiTest extends TestCase {

  /**
   * Governed Content is canonical and Content Sync remains compatible.
   */
  public function testGovernedContentRuntimeNamesAreCanonical(): void {
    $this->loadCommandClass();

    $sync = $this->commandAttribute('sync');
    self::assertSame('emerging:governed-content', $sync->name);
    self::assertSame(['emerging:content-sync'], $sync->aliases);

    $validate = $this->commandAttribute('validate');
    self::assertSame('emerging:governed-content:validate', $validate->name);
    self::assertSame(
      ['emerging:content-sync:validate'],
      $validate->aliases,
    );
  }

  /**
   * Historical migration commands remain compatibility-only for now.
   */
  public function testMigrationCommandsDoNotGainNewFacadeAliases(): void {
    $this->loadCommandClass();

    foreach (['release', 'readmit'] as $method) {
      self::assertSame([], $this->commandAttribute($method)->aliases);
    }
  }

  /**
   * Loads the custom command class from the project module.
   */
  private function loadCommandClass(): void {
    if (class_exists(ContentSyncCommands::class, FALSE)) {
      return;
    }

    $projectRoot = dirname(DRUPAL_ROOT);
    require_once $projectRoot
      . '/web/modules/custom/emerging_digital_content/src/Drush/Commands/'
      . 'ContentSyncCommands.php';
  }

  /**
   * Returns the single Drush command attribute for a command method.
   */
  private function commandAttribute(string $method): Command {
    $attributes = (new \ReflectionMethod(ContentSyncCommands::class, $method))
      ->getAttributes(Command::class);

    self::assertCount(1, $attributes);

    return $attributes[0]->newInstance();
  }

}
