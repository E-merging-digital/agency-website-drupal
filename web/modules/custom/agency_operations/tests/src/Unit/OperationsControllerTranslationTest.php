<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_operations\Unit;

use Drupal\agency_operations\Controller\OperationsController;
use Drupal\agency_operations\Service\CapabilityRegistryReader;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use ReflectionClass;

/**
 * Proves semantic cockpit statuses are translated in the presentation layer.
 *
 * @group agency_operations
 */
final class OperationsControllerTranslationTest extends UnitTestCase {

  /**
   * Proves English source labels and a French UI translation stay semantic.
   */
  public function testHumanStatusLabelsFollowUiTranslation(): void {
    $controller = (new ReflectionClass(OperationsController::class))
      ->newInstanceWithoutConstructor();
    $translator = $this->createMock(TranslationInterface::class);
    $translator->method('translate')
      ->willReturnCallback(static function (string $string): string {
        return [
          'Operational' => 'Opérationnel',
          'Ready' => 'Prêt',
          'Preparing' => 'En préparation',
          'Blocked' => 'Bloqué',
          'Unavailable' => 'Indisponible',
          'Human action required' => 'Action humaine requise',
        ][$string] ?? $string;
      });
    $controller->setStringTranslation($translator);

    $method = (new ReflectionClass(OperationsController::class))
      ->getMethod('humanStatusLabel');
    $method->setAccessible(TRUE);

    self::assertSame(
      'Opérationnel',
      $method->invoke($controller, CapabilityRegistryReader::STATUS_OPERATIONAL),
    );
    self::assertSame(
      'Prêt',
      $method->invoke($controller, CapabilityRegistryReader::STATUS_READY),
    );
    self::assertSame(
      'En préparation',
      $method->invoke($controller, CapabilityRegistryReader::STATUS_PREPARING),
    );
    self::assertSame(
      'Bloqué',
      $method->invoke($controller, CapabilityRegistryReader::STATUS_BLOCKED),
    );
    self::assertSame(
      'Action humaine requise',
      $method->invoke($controller, CapabilityRegistryReader::STATUS_HUMAN_ACTION_REQUIRED),
    );
    self::assertSame(
      'Indisponible',
      $method->invoke($controller, CapabilityRegistryReader::STATUS_UNAVAILABLE),
    );
    self::assertSame('Indisponible', $method->invoke($controller, 'unknown-status'));
  }

}
