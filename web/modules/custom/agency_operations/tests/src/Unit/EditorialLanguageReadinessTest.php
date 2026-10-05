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

  /**
   * Proves that required languages come only from current Drupal configuration.
   */
  public function testConfiguredLanguagesAreDiscoveredDynamically(): void {
    $service = $this->serviceFor(['aa', 'bb']);
    self::assertSame(['aa', 'bb'], array_keys($service->getRequiredLanguages()));

    $service = $this->serviceFor(['aa', 'bb', 'cc']);
    self::assertSame(['aa', 'bb', 'cc'], array_keys($service->getRequiredLanguages()));
  }

  /**
   * Proves that one missing required language blocks overall readiness.
   */
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

  /**
   * Proves that a newly configured language is required without a code change.
   */
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
   * Creates a readiness service backed by arbitrary configurable languages.
   *
   * @param string[] $codes
   *   Arbitrary configurable language codes used by the mocked manager.
   *
   * @return \Drupal\agency_operations\Service\EditorialLanguageReadiness
   *   A readiness service using the mocked current-language configuration.
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
   * Returns a fully ready per-language candidate state.
   *
   * @return array<string, mixed>
   *   State accepted as ready by the publication-language contract.
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
