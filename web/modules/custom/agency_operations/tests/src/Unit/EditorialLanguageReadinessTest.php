<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_operations\Unit;

use Drupal\agency_operations\Service\EditorialLanguageReadiness;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Proves the dynamic all-configured-language publication contract.
 *
 * @group agency_operations
 */
final class EditorialLanguageReadinessTest extends UnitTestCase {

  public function testConfiguredLanguagesAreDiscoveredDynamically(): void {
    $service = $this->serviceFor(['aa', 'bb']);
    self::assertSame(['aa', 'bb'], array_keys($service->getRequiredLanguages()));

    $service = $this->serviceFor(['aa', 'bb', 'cc']);
    self::assertSame(['aa', 'bb', 'cc'], array_keys($service->getRequiredLanguages()));
  }

  public function testEveryRequiredLanguageMustBeReady(): void {
    $service = $this->serviceFor(['aa', 'bb', 'cc']);
    $ready = $this->readyState();

    $states = [
      'aa' => $ready,
      'bb' => $ready,
      'cc' => $ready,
    ];
    self::assertTrue($service->isPublicationLanguageComplete($states));

    unset($states['cc']);
    $result = $service->evaluate($states);
    self::assertSame('BLOCKED', $result['overall']);
    self::assertFalse($result['languages']['cc']['translation_exists']);
    self::assertFalse($result['languages']['cc']['ready']);
  }

  public function testNewConfiguredLanguageNeedsNoCodeChangeButBlocksUntilReady(): void {
    $twoLanguageService = $this->serviceFor(['aa', 'bb']);
    $states = [
      'aa' => $this->readyState(),
      'bb' => $this->readyState(),
    ];
    self::assertTrue($twoLanguageService->isPublicationLanguageComplete($states));

    $threeLanguageService = $this->serviceFor(['aa', 'bb', 'cc']);
    self::assertFalse($threeLanguageService->isPublicationLanguageComplete($states));

    $states['cc'] = $this->readyState();
    self::assertTrue($threeLanguageService->isPublicationLanguageComplete($states));
  }

  /**
   * @param string[] $codes
   */
  private function serviceFor(array $codes): EditorialLanguageReadiness {
    $languages = [];
    foreach ($codes as $code) {
      $languages[$code] = new Language([
        'id' => $code,
        'name' => strtoupper($code),
      ]);
    }

    $manager = $this->createMock(LanguageManagerInterface::class);
    $manager->expects(self::atLeastOnce())
      ->method('getLanguages')
      ->with(LanguageInterface::STATE_CONFIGURABLE)
      ->willReturn($languages);

    return new EditorialLanguageReadiness($manager);
  }

  /**
   * @return array<string, mixed>
   */
  private function readyState(): array {
    return [
      'translation_exists' => TRUE,
      'translation_state' => 'READY',
      'validation_state' => 'PASS',
      'preprod_render_state' => 'PASS',
      'approval_required' => FALSE,
    ];
  }

}
