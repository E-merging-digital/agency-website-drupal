<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Unit;

use Drupal\emerging_digital_content\Drush\Commands\ContentSyncCommands;
use Drush\Attributes as CLI;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards the additive Governed Content CLI compatibility facade.
 */
final class GovernedContentCliFacadeTest extends TestCase {

  /**
   * Runtime sync and validation expose Governed Content aliases.
   */
  public function testGovernedContentRuntimeAliasesAreAdditive(): void {
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
    foreach (['release', 'readmit'] as $method) {
      self::assertSame([], $this->commandAttribute($method)->aliases);
    }
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
