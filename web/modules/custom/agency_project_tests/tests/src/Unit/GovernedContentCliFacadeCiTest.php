<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\emerging_digital_content\Drush\Commands\ContentSyncCommands;
use Drush\Attributes as CLI;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Keeps the Governed Content CLI compatibility facade visible to standard CI.
 *
 * @group agency_project_tests
 * @group governed_content
 */
final class GovernedContentCliFacadeCiTest extends TestCase {

  /**
   * Runtime sync and validation expose additive Governed Content aliases.
   */
  public function testGovernedContentRuntimeAliasesAreAdditive(): void {
    $this->loadCommandClass();

    $sync = $this->commandAttribute('sync');
    self::assertSame('emerging:content-sync', $sync->name);
    self::assertSame(['emerging:governed-content'], $sync->aliases);

    $validate = $this->commandAttribute('validate');
    self::assertSame('emerging:content-sync:validate', $validate->name);
    self::assertSame(
      ['emerging:governed-content:validate'],
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
  private function commandAttribute(string $method): CLI\Command {
    $attributes = (new ReflectionMethod(ContentSyncCommands::class, $method))
      ->getAttributes(CLI\Command::class);

    self::assertCount(1, $attributes);

    return $attributes[0]->newInstance();
  }

}
