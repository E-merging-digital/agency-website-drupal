<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Service;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Fail-closed publication readiness for all configured content languages.
 */
final class EditorialLanguageReadiness implements EditorialLanguageReadinessInterface {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getRequiredLanguages(): array {
    $required = [];
    foreach ($this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE) as $language) {
      $required[$language->getId()] = $language->getName();
    }

    return $required;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(array $languageStates): array {
    $matrix = [];
    $complete = TRUE;

    foreach ($this->getRequiredLanguages() as $langcode => $name) {
      $input = $languageStates[$langcode] ?? [];
      $translationExists = ($input['translation_exists'] ?? FALSE) === TRUE;
      $translationState = (string) ($input['translation_state'] ?? ($translationExists ? 'PRESENT' : 'MISSING'));
      $validationState = (string) ($input['validation_state'] ?? 'NOT_READY');
      $preprodRenderState = (string) ($input['preprod_render_state'] ?? 'NOT_READY');
      $approvalRequired = ($input['approval_required'] ?? FALSE) === TRUE;
      $approvalState = (string) ($input['approval_state'] ?? ($approvalRequired ? 'NOT_READY' : 'NOT_APPLICABLE'));

      $languageReady = $translationExists
        && in_array($translationState, ['PRESENT', 'READY'], TRUE)
        && $validationState === 'PASS'
        && $preprodRenderState === 'PASS'
        && (!$approvalRequired || $approvalState === 'APPROVED');

      if (!$languageReady) {
        $complete = FALSE;
      }

      $matrix[$langcode] = [
        'language_code' => $langcode,
        'language_name' => $name,
        'translation_exists' => $translationExists,
        'translation_state' => $translationState,
        'validation_state' => $validationState,
        'preprod_render_state' => $preprodRenderState,
        'approval_state' => $approvalState,
        'ready' => $languageReady,
      ];
    }

    return [
      'overall' => $complete ? 'READY' : 'BLOCKED',
      'languages' => $matrix,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isPublicationLanguageComplete(array $languageStates): bool {
    return $this->evaluate($languageStates)['overall'] === 'READY';
  }

}
